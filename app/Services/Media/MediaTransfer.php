<?php

namespace App\Services\Media;

use App\Models\MediaPull;
use App\Models\MediaPullItem;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;

/**
 * MediaTransfer (W-0448) — moves ONE media file into the library, resumably.
 *
 * The ETL paradigm applies to a single item too: a transfer is chunked (the
 * body streams to a .part, never held in memory), resumable (a surviving .part
 * is continued with a Range request, or a folder copy re-runs from zero into a
 * fresh .part), visible (bytes_done ticks at most every 2 s so the Step-2
 * dashboard advances without hammering the row), derived (connect 30 s / read
 * 300 s, no magic per-file budget), and halt-honouring (the run's `halted`
 * status stops the transfer at the next tick and leaves the item `pending`, so
 * a resume continues the same .part).
 *
 * The Guzzle client is injectable so the transfer test drives it with a
 * MockHandler and inspects the Range header; production passes null and a real
 * client is built.
 */
class MediaTransfer
{
    /** Derived timeouts (ETL paradigm — not a per-file magic budget). */
    public const CONNECT_TIMEOUT = 30;

    public const READ_TIMEOUT = 300;

    /** Give up after this many CONSECUTIVE attempts that write no new bytes. */
    public const MAX_NO_PROGRESS = 3;

    /** Item row / halt poll cadence (bounded writes). */
    private const TICK_SECONDS = 2;

    /** Streaming copy / append buffer. */
    private const CHUNK_BYTES = 1048576;

    /**
     * The longest a LIVE transfer can go without touching its item row, in
     * seconds. Derived from the transfer's own bounds, never a second number:
     * a live lane ticks every TICK_SECONDS while bytes flow, and the longest
     * silence is MAX_NO_PROGRESS attempts that each connect and then stall to
     * the read timeout (after which the item is written `failed`), plus one
     * read timeout of margin for the segment merge. A `running` item silent
     * for longer than this has no worker (the lane was killed mid-item); the
     * planner's stale-claim reclaim returns it to the pool.
     */
    public static function staleAfterSeconds(): int
    {
        return self::MAX_NO_PROGRESS * (self::CONNECT_TIMEOUT + self::READ_TIMEOUT) + self::READ_TIMEOUT;
    }

    public function __construct(
        private readonly MediaLibraryService $library,
        private ?Client $http = null,
    ) {}

    /**
     * Transfer one item. `$sourceKind` is 'web' or 'folder'; `$sourceBase` is
     * the website origin (web) or the source folder root (folder). Returns
     * `{status, bytes, error?}` where status is done | failed | skipped |
     * pending (pending = halted mid-flight, resume later).
     *
     * @return array{status: string, bytes: int, error?: string}
     */
    public function pull(MediaPullItem $item, string $sourceKind, string $sourceBase): array
    {
        $destAbs = $this->library->destAbs((string) $item->dest);
        $this->library->ensureDir(dirname($destAbs));
        $part = $destAbs.'.part';

        // Halt before any network / disk work — a control that seizes.
        if ($this->halted($item)) {
            return ['status' => 'pending', 'bytes' => $this->size($part)];
        }

        // Already on disk (a raced resume, or a plan that did not mark it
        // skipped): do not re-fetch.
        if ($this->size($destAbs) > 0) {
            return ['status' => 'skipped', 'bytes' => $this->size($destAbs)];
        }

        try {
            return $sourceKind === 'folder'
                ? $this->fromFolder($item, $sourceBase, $destAbs, $part)
                : $this->fromWeb($item, $sourceBase, $destAbs, $part);
        } catch (HaltSignal) {
            // The run was halted mid-transfer. Keep the .part; leave the item
            // pending so a resume continues it.
            return ['status' => 'pending', 'bytes' => $this->size($part)];
        } catch (SupersededSignal) {
            // The claim was reclaimed and another lane took the item. This lane
            // writes nothing more: the caller records no status and no counter.
            return ['status' => 'superseded', 'bytes' => $this->size($part)];
        } catch (\Throwable $e) {
            // Keep the .part on failure (the next attempt resumes it).
            return ['status' => 'failed', 'bytes' => $this->size($part), 'error' => mb_substr($e->getMessage(), 0, 900)];
        }
    }

    // ── web ──────────────────────────────────────────────────────────────────

    /**
     * @return array{status: string, bytes: int}
     */
    private function fromWeb(MediaPullItem $item, string $base, string $destAbs, string $part): array
    {
        $url = $this->library->urlFor((string) $item->source, $base);
        $noProgress = 0;

        // A .part that already holds every expected byte (a crash between the
        // last read and the rename) is published as is. A Range request past
        // the end answers 416 and would burn the no-progress budget.
        $expected = (int) $item->bytes_expected;
        if ($expected > 0 && $this->size($part) === $expected) {
            $this->atomicRename($part, $destAbs);
            $this->touch($item, $expected);

            return ['status' => 'done', 'bytes' => $expected];
        }

        while (true) {
            $before = $this->size($part);
            try {
                $total = $this->downloadOnce($item, $url, $part);
                if ($total !== null && $this->size($part) !== $total) {
                    throw new \RuntimeException(
                        'size mismatch: wrote '.$this->size($part).' of '.$total
                    );
                }
                $this->atomicRename($part, $destAbs);
                $bytes = $this->size($destAbs);
                $this->touch($item, $bytes);

                return ['status' => 'done', 'bytes' => $bytes];
            } catch (HaltSignal|SupersededSignal $e) {
                throw $e;
            } catch (\Throwable $e) {
                // A retry is worthwhile only while the .part keeps growing. Three
                // attempts that add nothing means the far end is not serving the
                // rest — stop (bounded retries: 3 without progress).
                if ($this->size($part) > $before) {
                    $noProgress = 0;
                } elseif (++$noProgress >= self::MAX_NO_PROGRESS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * One GET into the .part. A surviving .part is resumed with a Range header;
     * a 206 appends, a 200 restarts (the server ignored the range). The body is
     * read in bounded chunks (never held whole in memory) into a fresh segment,
     * then merged into the .part, so a 200 restart never corrupts a partial. A
     * halt at a 2 s tick aborts. Returns the server-declared total when known,
     * else null.
     */
    private function downloadOnce(MediaPullItem $item, string $url, string $part): ?int
    {
        $resume = $this->size($part);
        $seg = $part.'.seg';
        @unlink($seg);

        // stream => true: read the body incrementally, so a multi-GB master
        // never loads into memory and a halt can interrupt between chunks.
        $response = $this->client()->request('GET', $url, [
            'headers'         => $resume > 0 ? ['Range' => 'bytes='.$resume.'-'] : [],
            'stream'          => true,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'read_timeout'    => self::READ_TIMEOUT,
            'timeout'         => 0,
            'http_errors'     => true,
        ]);

        $sink = fopen($seg, 'wb');
        if ($sink === false) {
            throw new \RuntimeException('cannot open segment '.$seg);
        }

        // The status is known from the headers before the body is read, so a
        // halt mid-stream can still merge the bytes so far into the .part the
        // right way (append a 206 remainder, replace on a 200).
        $status = $response->getStatusCode();
        $length = $this->headerInt($response->getHeaderLine('Content-Length'));
        $rangeTotal = $this->contentRangeTotal($response->getHeaderLine('Content-Range'));

        $body = $response->getBody();
        $written = 0;
        $lastTick = 0.0;
        $halted = false;

        try {
            while (! $body->eof()) {
                $chunk = $body->read(self::CHUNK_BYTES);
                if ($chunk === '') {
                    break;
                }
                fwrite($sink, $chunk);
                $written += strlen($chunk);

                $now = microtime(true);
                if ($now - $lastTick >= self::TICK_SECONDS) {
                    $lastTick = $now;
                    if ($this->halted($item)) {
                        $halted = true;
                        break;
                    }
                    $this->touch($item, $resume + $written);
                }
            }
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
            $body->close();
        }

        // A lane that lost its claim never merges: the segment path may now be
        // the new lane's file.
        $this->assertOwned($item);

        // Merge the segment into the .part, preserving progress on a halt.
        if ($resume > 0 && $status === 206) {
            $this->appendFile($seg, $part);
            @unlink($seg);
            $total = $rangeTotal;
        } else {
            // 200 (or a fresh start): the segment IS the whole file so far.
            @unlink($part);
            $this->atomicRename($seg, $part);
            $total = $length;
        }

        $this->setExpected($item, $total);

        if ($halted) {
            throw new HaltSignal;
        }

        return $total;
    }

    // ── folder ─────────────────────────────────────────────────────────────

    /**
     * @return array{status: string, bytes: int}
     */
    private function fromFolder(MediaPullItem $item, string $base, string $destAbs, string $part): array
    {
        $baseNorm = rtrim(str_replace('\\', '/', $base), '/');
        $rel = ltrim(str_replace('\\', '/', (string) $item->source), '/');
        $src = $baseNorm.'/'.$rel;
        if (! is_file($src)) {
            // Tolerate a base that points AT the Subjects folder itself. The
            // item source is website-relative ("Subjects/<Subject>/..."), so a
            // base already ending in Subjects (the common MEDIA_DIR mix-up)
            // otherwise doubles the segment. Retry once with the leading
            // "Subjects/" stripped before failing.
            if (stripos($rel, 'Subjects/') === 0) {
                $alt = $baseNorm.'/'.substr($rel, strlen('Subjects/'));
                if (is_file($alt)) {
                    $src = $alt;
                }
            }
            if (! is_file($src)) {
                throw new \RuntimeException('source not found: '.$src);
            }
        }

        $expected = $this->size($src);
        $this->setExpected($item, $expected);

        $in = fopen($src, 'rb');
        $out = fopen($part, 'wb');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            throw new \RuntimeException('cannot open for copy: '.$src);
        }

        $written = 0;
        $lastTick = 0.0;

        try {
            while (! feof($in)) {
                $buf = fread($in, self::CHUNK_BYTES);
                if ($buf === false) {
                    throw new \RuntimeException('read error: '.$src);
                }
                if ($buf !== '') {
                    fwrite($out, $buf);
                    $written += strlen($buf);
                }
                $now = microtime(true);
                if ($now - $lastTick >= self::TICK_SECONDS) {
                    $lastTick = $now;
                    if ($this->halted($item)) {
                        throw new HaltSignal;
                    }
                    $this->touch($item, $written);
                }
            }
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
        }

        if ($this->size($part) !== $expected) {
            throw new \RuntimeException('copy size mismatch: '.$this->size($part).' of '.$expected);
        }

        $this->atomicRename($part, $destAbs);
        $bytes = $this->size($destAbs);
        $this->touch($item, $bytes);

        return ['status' => 'done', 'bytes' => $bytes];
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function client(): Client
    {
        return $this->http ??= new Client;
    }

    private function halted(MediaPullItem $item): bool
    {
        return MediaPull::query()
            ->whereKey($item->pull_id)
            ->where('status', 'halted')
            ->exists();
    }

    /**
     * The progress write, fenced to the claim (the attempts value the lane
     * claimed with). A reclaim returns an item to the pool without reaching
     * its worker; when another lane then claims it, attempts moves on, this
     * write matches no row and the old lane stops at this tick.
     */
    private function touch(MediaPullItem $item, int $bytes): void
    {
        $n = DB::table('media_pull_items')
            ->where('id', $item->id)
            ->where('attempts', (int) $item->attempts)
            ->update(['bytes_done' => $bytes, 'updated_at' => now()]);
        if ($n === 0) {
            $this->assertOwned($item);
        }
        $item->bytes_done = $bytes;
    }

    /** Throws when another lane has claimed the item since this lane did. */
    private function assertOwned(MediaPullItem $item): void
    {
        $attempts = DB::table('media_pull_items')->where('id', $item->id)->value('attempts');
        if ($attempts !== null && (int) $attempts !== (int) $item->attempts) {
            throw new SupersededSignal;
        }
    }

    private function setExpected(MediaPullItem $item, ?int $expected): void
    {
        if ($expected === null || $expected <= 0 || (int) $item->bytes_expected === $expected) {
            return;
        }
        DB::table('media_pull_items')
            ->where('id', $item->id)
            ->update(['bytes_expected' => $expected, 'updated_at' => now()]);
        $item->bytes_expected = $expected;
    }

    private function appendFile(string $seg, string $part): void
    {
        $in = fopen($seg, 'rb');
        $out = fopen($part, 'ab');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            throw new \RuntimeException('cannot append segment to '.$part);
        }
        try {
            while (! feof($in)) {
                $buf = fread($in, self::CHUNK_BYTES);
                if ($buf === false) {
                    throw new \RuntimeException('segment read error');
                }
                if ($buf !== '') {
                    fwrite($out, $buf);
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function atomicRename(string $from, string $to): void
    {
        if (! @rename($from, $to)) {
            throw new \RuntimeException('rename failed: '.$from.' -> '.$to);
        }
    }

    private function size(string $path): int
    {
        return is_file($path) ? (int) filesize($path) : 0;
    }

    private function headerInt(string $value): ?int
    {
        return $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    /** Parse the total from a Content-Range like "bytes 100-999/1000". */
    private function contentRangeTotal(string $value): ?int
    {
        if ($value !== '' && preg_match('#/(\d+)\s*$#', $value, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}

/**
 * Thrown from the transfer's progress tick when the run is halted. Caught in
 * pull() and reported as `pending` so a resume continues the same .part.
 */
class HaltSignal extends \RuntimeException {}

/**
 * Thrown when the lane finds that another lane has claimed its item (the
 * stale-claim reclaim returned it to the pool). Caught in pull() and reported
 * as `superseded`: the old lane records nothing.
 */
class SupersededSignal extends \RuntimeException {}

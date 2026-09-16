<?php

namespace App\Services\Media;

use App\Support\MediaMeta;
use Illuminate\Support\Facades\Cache;

/**
 * MediaLibraryService (W-0448) — the file-side owner of the self-hosted video
 * library. It answers two questions the catalog (MediaMeta) cannot: what is on
 * disk under the library root, and what a website/folder pull must fetch.
 *
 * The library root IS the Subjects folder (same layout as the website and
 * E:\Subjects), so the on-disk path of a film's master is
 * <root>/<Subject>/<Subject>-Silent.mp4, an audio track
 * <root>/<Subject>/audio/<Subject>-<Name>.m4a, a caption
 * <root>/<Subject>/captions/<Subject>-<Name>.vtt. `<Name>` is the language
 * ENGLISH name from the languages table, the same token the media filenames
 * embed. Set MEDIA_DIR=E:/Subjects and the operator's local copy serves with
 * no copy at all.
 *
 * planItems() reads the catalog (registry + uploaded) and computes, per file,
 * the website-relative source path and the root-relative dest, so the pull
 * engine (lane A) can transfer without re-deriving names.
 */
class MediaLibraryService
{
    /** Cache key + TTL for the present() probe (a bounded file stat sweep). */
    private const PRESENT_KEY = 'cga:media:present';

    private const PRESENT_TTL = 30;

    /**
     * The library root — the Subjects folder. Defaults to
     * public_path('media/Subjects'); config('cga.media_local.local_root')
     * (env CGA_MEDIA_LOCAL_ROOT) overrides it for a no-copy local serve and
     * for tests. Always forward-slashed and un-trailing-slashed.
     */
    public function root(): string
    {
        $override = config('cga.media_local.local_root');

        $path = is_string($override) && $override !== ''
            ? $override
            : public_path('media/Subjects');

        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * The website origin the download pull reads from
     * (config('cga.media_local.website_base_url'), env CGA_MEDIA_WEBSITE_URL).
     */
    public function websiteBase(): string
    {
        return (string) config(
            'cga.media_local.website_base_url',
            'https://cosmopolitancoalition.org/wp-content/uploads'
        );
    }

    /**
     * True when at least one registered film's master is on disk. Cached 30 s
     * so a page's per-record availability check does not re-sweep every poll.
     * MediaMeta::baseUrl() gates '/media' on this; it never calls back into
     * baseUrl(), so there is no recursion.
     */
    public function present(): bool
    {
        return (bool) Cache::remember(self::PRESENT_KEY, self::PRESENT_TTL, function (): bool {
            foreach (MediaMeta::catalog() as $video) {
                if ($this->masterExists($video)) {
                    return true;
                }
            }

            return false;
        });
    }

    /** Is this catalog record's master on disk (non-empty)? */
    public function masterExists(array $video): bool
    {
        $subject = (string) ($video['subject'] ?? '');
        $master = (string) ($video['master'] ?? '');

        if ($subject === '' || $master === '') {
            return false;
        }

        return $this->fileHasBytes($this->destAbs($subject.'/'.$master));
    }

    /**
     * Per registered subject: master present, tracks present, tracks expected.
     *
     * @return list<array{id: string, subject: string, master: bool, audio: int, captions: int, expected_audio: int, expected_captions: int}>
     */
    public function inventory(): array
    {
        $out = [];

        foreach (MediaMeta::catalog() as $video) {
            $subject = (string) ($video['subject'] ?? '');

            $audio = 0;
            foreach ($video['audio'] ?? [] as $track) {
                if ($this->trackExists($subject, 'audio', (string) ($track['name'] ?? ''))) {
                    $audio++;
                }
            }

            $captions = 0;
            foreach ($video['captions'] ?? [] as $track) {
                if ($this->trackExists($subject, 'captions', (string) ($track['name'] ?? ''))) {
                    $captions++;
                }
            }

            $out[] = [
                'id'                => $video['id'],
                'subject'           => $subject,
                'master'            => $this->masterExists($video),
                'audio'             => $audio,
                'captions'          => $captions,
                'expected_audio'    => count($video['audio'] ?? []),
                'expected_captions' => count($video['captions'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * The transfer plan: one item per master / audio track / caption track.
     * `dest` is relative to the library root; `source_path` is the
     * website-relative path with the same segments (Subjects/<Subject>/...),
     * so urlFor() builds the download url and the folder copy reads
     * <sourceBase>/<source_path>. An item already on disk (>0 bytes) is marked
     * `skipped`, the rest `pending`.
     *
     * @param  list<string>|null  $subjects  restrict to these subject folders (all when null/empty)
     * @param  list<string>  $kinds
     * @return list<array{subject: string, kind: string, track_name: string|null, dest: string, source_path: string, status: string}>
     */
    public function planItems(?array $subjects = null, array $kinds = ['master', 'audio', 'captions']): array
    {
        $filter = is_array($subjects) && $subjects !== [] ? array_flip($subjects) : null;

        $items = [];

        foreach (MediaMeta::all() as $video) {
            $subject = (string) ($video['subject'] ?? '');
            if ($subject === '') {
                continue;
            }
            if ($filter !== null && ! isset($filter[$subject])) {
                continue;
            }

            if (in_array('master', $kinds, true)) {
                $master = (string) ($video['master'] ?? '');
                if ($master !== '') {
                    $items[] = $this->item(
                        $subject, 'master', null,
                        $subject.'/'.$master,
                        'Subjects/'.$subject.'/'.$master
                    );
                }
            }

            if (in_array('audio', $kinds, true)) {
                foreach ($video['audio'] ?? [] as $track) {
                    $name = (string) ($track['name'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $file = $subject.'-'.$name.'.m4a';
                    $items[] = $this->item(
                        $subject, 'audio', $name,
                        $subject.'/audio/'.$file,
                        'Subjects/'.$subject.'/audio/'.$file
                    );
                }
            }

            if (in_array('captions', $kinds, true)) {
                foreach ($video['captions'] ?? [] as $track) {
                    $name = (string) ($track['name'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $file = $subject.'-'.$name.'.vtt';
                    $items[] = $this->item(
                        $subject, 'captions', $name,
                        $subject.'/captions/'.$file,
                        'Subjects/'.$subject.'/captions/'.$file
                    );
                }
            }
        }

        return $items;
    }

    /**
     * Percent-encode each path segment of a source path and join it to a base
     * (the website origin, or a folder root). rawurlencode leaves the
     * unreserved set alone, so "Affiliate Report-Silent.mp4" becomes
     * "Affiliate%20Report-Silent.mp4", never encoding the hyphen or the dot.
     */
    public function urlFor(string $sourcePath, string $base): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($sourcePath, '/'))));

        return rtrim($base, '/').'/'.$encoded;
    }

    /** The absolute on-disk path of a root-relative dest. */
    public function destAbs(string $rel): string
    {
        return $this->root().'/'.ltrim(str_replace('\\', '/', $rel), '/');
    }

    /** Create a directory (recursively) if it does not exist. */
    public function ensureDir(string $absDir): void
    {
        if ($absDir !== '' && ! is_dir($absDir)) {
            @mkdir($absDir, 0775, true);
        }
    }

    /**
     * @return array{subject: string, kind: string, track_name: string|null, dest: string, source_path: string, status: string}
     */
    private function item(string $subject, string $kind, ?string $trackName, string $dest, string $sourcePath): array
    {
        return [
            'subject'     => $subject,
            'kind'        => $kind,
            'track_name'  => $trackName,
            'dest'        => $dest,
            'source_path' => $sourcePath,
            'status'      => $this->fileHasBytes($this->destAbs($dest)) ? 'skipped' : 'pending',
        ];
    }

    private function trackExists(string $subject, string $kind, string $name): bool
    {
        if ($subject === '' || $name === '') {
            return false;
        }

        $ext = $kind === 'audio' ? 'm4a' : 'vtt';
        $dir = $kind === 'audio' ? 'audio' : 'captions';
        $file = $subject.'-'.$name.'.'.$ext;

        return $this->fileHasBytes($this->destAbs($subject.'/'.$dir.'/'.$file));
    }

    private function fileHasBytes(string $path): bool
    {
        return is_file($path) && (int) @filesize($path) > 0;
    }
}

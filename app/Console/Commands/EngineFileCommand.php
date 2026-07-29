<?php

namespace App\Console\Commands;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * The UI<->CLI parity twin of the ~80 web POSTs that file a constitutional form
 * (ruling 10, the standing parity order). ConstitutionalEngine::file() IS the
 * single write path for EVERY F-* form, so one generic command closes the whole
 * class at once: the engine re-validates identically (role gate, phase windows,
 * state guards, ConstitutionalViolation citations) and seals the chain the same
 * way whether the caller is a controller or this console. It adds NO form — it
 * only drives file() — so the pinned form count is untouched, and it is a second
 * DOOR to the same rails, not a second write path.
 *
 *   php artisan engine:file F-LEG-036 --actor=<uuid> --payload='{"member_id":"..."}'
 *   php artisan engine:file F-ELB-004 --payload='{"election_id":"..."}'   # system filing
 *
 * A null actor is a SYSTEM filing (bypasses role gates but is refused by
 * systemOnly-forbidden handlers); a real actor runs the full role gate. A
 * refusal prints its citation and returns FAILURE — the rejection is ALREADY
 * sealed on the chain by the engine, exactly as the web path records it.
 */
class EngineFileCommand extends Command
{
    protected $signature = 'engine:file
        {form : the constitutional form id, e.g. F-LEG-003 (aliases resolved)}
        {--actor= : the filing user UUID; omit for a SYSTEM filing}
        {--payload= : the form payload as a JSON object}';

    protected $description = 'File any constitutional form through the engine (the CLI twin of the web filing POSTs)';

    public function handle(ConstitutionalEngine $engine): int
    {
        $payload = $this->decodePayload();

        if ($payload === null) {
            return self::FAILURE;
        }

        $actor = null;
        $actorId = $this->option('actor');

        if ($actorId !== null && $actorId !== '') {
            $actor = User::query()->find($actorId);

            if ($actor === null) {
                $this->error("No user [{$actorId}] — pass a valid actor UUID, or omit --actor for a system filing.");

                return self::FAILURE;
            }
        }

        try {
            $result = $engine->file((string) $this->argument('form'), $actor, $payload);
        } catch (ConstitutionalViolation $e) {
            // The engine has ALREADY sealed a rejected=true chain entry with this
            // citation — do not re-file. Surface it, fail the command.
            $this->error('REFUSED: '.$e->getMessage());
            $this->line('  citation: <comment>'.$e->citation.'</comment>');

            return self::FAILURE;
        } catch (InvalidArgumentException $e) {
            // Unknown form id (FormRegistry::canonical) — a typo, not a
            // constitutional matter.
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $e) {
            // A registered form with no handler yet, or a read-only mirror refusing.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Filed {$result->formId} — audit seq {$result->entry->seq} (entry {$result->entry->id}).");

        if ($result->recorded !== []) {
            $this->line('recorded: '.json_encode($result->recorded, JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null  null on a decode error (already reported) */
    private function decodePayload(): ?array
    {
        $raw = $this->option('payload');

        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            $this->error('--payload must be a JSON object, e.g. \'{"jurisdiction_id":"..."}\'.');

            return null;
        }

        return $decoded;
    }
}

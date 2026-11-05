<?php

namespace App\Console\Commands;

use App\Support\SecretVault;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * security:rotate-app-key — replace APP_KEY and re-encrypt everything the old key protects (W-0489).
 *
 * Stores covered: instance_settings.private_key_encrypted, oidc_signing_keys.private_pem_encrypted,
 * web_push_keys.private_key, web_push_subscriptions.subscription, user_secrets.ciphertext (per-user vault),
 * and the broker credential file. A row that cannot be opened with the old key is counted and left alone.
 * The database part runs in one transaction. The new .env is staged before the commit, then renamed in.
 * The command never prints a key. Run `php artisan config:clear` and restart workers afterwards.
 * Sessions end, because the session cookie is signed with the key.
 */
class RotateAppKeyCommand extends Command
{
    protected $signature = 'security:rotate-app-key {--env-path= : .env to update (default: base_path/.env)} {--dry-run : count what would change, write nothing}';

    protected $description = 'Replace APP_KEY and re-encrypt every value the old key protects';

    public function handle(): int
    {
        $cipher = (string) config('app.cipher', 'AES-256-CBC');
        $oldKey = (string) config('app.key');
        if ($oldKey === '') {
            $this->error('APP_KEY is empty. Nothing to rotate.');

            return self::FAILURE;
        }
        $newKey = 'base64:'.base64_encode(Encrypter::generateKey($cipher));

        $old = new Encrypter($this->raw($oldKey), $cipher);
        $new = new Encrypter($this->raw($newKey), $cipher);
        $dry = (bool) $this->option('dry-run');

        $envPath = (string) ($this->option('env-path') ?: base_path('.env'));
        $staged = null;
        if (! $dry) {
            $staged = $this->stageEnv($envPath, $newKey);
        }

        $report = [];
        try {
            DB::transaction(function () use ($old, $new, $dry, &$report, $oldKey, $newKey) {
                $report['instance_settings.private_key_encrypted'] = $this->crypt('instance_settings', 'id', 'private_key_encrypted', $old, $new, $dry);
                $report['oidc_signing_keys.private_pem_encrypted'] = $this->crypt('oidc_signing_keys', 'id', 'private_pem_encrypted', $old, $new, $dry);
                $report['web_push_keys.private_key'] = $this->crypt('web_push_keys', 'id', 'private_key', $old, $new, $dry);
                $report['web_push_subscriptions.subscription'] = $this->crypt('web_push_subscriptions', 'id', 'subscription', $old, $new, $dry);
                $report['user_secrets.ciphertext'] = $this->vault($oldKey, $newKey, $dry);
                if ($dry) {
                    return;
                }
            });
            $report['broker credentials file'] = $this->broker($old, $new, $dry);
        } catch (\Throwable $e) {
            if ($staged) {
                @unlink($staged);
            }
            $this->error('Rotation failed, nothing changed: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($report as $store => [$moved, $skipped]) {
            $this->line(sprintf('%-46s re-encrypted %d, could not open %d', $store, $moved, $skipped));
        }

        if ($dry) {
            $this->info('Dry run. Nothing written.');

            return self::SUCCESS;
        }

        if (! @rename($staged, $envPath)) {
            $this->error('Data is re-encrypted but the staged .env could not replace the real one: '.$staged);

            return self::FAILURE;
        }
        $this->info('APP_KEY replaced in '.$envPath.'. Run config:clear and restart workers.');

        return self::SUCCESS;
    }

    private function raw(string $key): string
    {
        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
    }

    private function stageEnv(string $envPath, string $newKey): string
    {
        $text = (string) @file_get_contents($envPath);
        if (! preg_match('/^APP_KEY=/m', $text)) {
            throw new RuntimeException('.env has no APP_KEY line.');
        }
        $text = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$newKey, $text, 1);
        $tmp = $envPath.'.rotating';
        if (file_put_contents($tmp, $text) === false) {
            throw new RuntimeException('Could not stage the new .env.');
        }
        @chmod($tmp, fileperms($envPath) & 0777);

        return $tmp;
    }

    /** @return array{0:int,1:int} */
    private function crypt(string $table, string $pk, string $col, Encrypter $old, Encrypter $new, bool $dry): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) {
            return [0, 0];
        }
        $moved = $skipped = 0;
        DB::table($table)->whereNotNull($col)->orderBy($pk)->select([$pk, $col])->chunk(200, function ($rows) use ($table, $pk, $col, $old, $new, $dry, &$moved, &$skipped) {
            foreach ($rows as $r) {
                try {
                    $plain = $old->decryptString((string) $r->$col);
                } catch (\Throwable) {
                    $skipped++;
                    continue;
                }
                if (! $dry) {
                    DB::table($table)->where($pk, $r->$pk)->update([$col => $new->encryptString($plain)]);
                }
                $moved++;
            }
        });

        return [$moved, $skipped];
    }

    /** @return array{0:int,1:int} */
    private function vault(string $oldKey, string $newKey, bool $dry): array
    {
        if (! Schema::hasTable('user_secrets')) {
            return [0, 0];
        }
        $moved = $skipped = 0;
        $rows = DB::table('user_secrets')->select(['id', 'user_id', 'ciphertext'])->orderBy('id')->get();
        foreach ($rows as $r) {
            config(['app.key' => $oldKey]);
            $plain = SecretVault::open((string) $r->user_id, (string) $r->ciphertext);
            if ($plain === null) {
                $skipped++;
                continue;
            }
            if (! $dry) {
                config(['app.key' => $newKey]);
                DB::table('user_secrets')->where('id', $r->id)->update(['ciphertext' => SecretVault::seal((string) $r->user_id, $plain)]);
            }
            $moved++;
        }
        config(['app.key' => $oldKey]);

        return [$moved, $skipped];
    }

    /** @return array{0:int,1:int} */
    private function broker(Encrypter $old, Encrypter $new, bool $dry): array
    {
        $path = (string) config('cga.broker.credentials_path', storage_path('app/broker/credentials.json'));
        if (! is_file($path)) {
            return [0, 0];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['domains']) || ! is_array($data['domains'])) {
            return [0, 0];
        }
        $moved = $skipped = 0;
        foreach ($data['domains'] as $domain => $row) {
            if (empty($row['token_encrypted'])) {
                continue;
            }
            try {
                $plain = $old->decryptString((string) $row['token_encrypted']);
            } catch (\Throwable) {
                $skipped++;
                continue;
            }
            $data['domains'][$domain]['token_encrypted'] = $new->encryptString($plain);
            $moved++;
        }
        if (! $dry && $moved > 0) {
            $tmp = $path.'.rotating';
            file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            @chmod($tmp, 0600);
            rename($tmp, $path);
        }

        return [$moved, $skipped];
    }
}

<?php

namespace App\Support;

/**
 * EnvFile (W-0448) — the shared repo-root .env reader/writer.
 *
 * These three helpers were private to SetupController (readEnvValue,
 * normalizePath, writeEnvValues). The Step-2 media library controller writes
 * MEDIA_DIR / MEDIA_SOURCE_DIR the same way SetupController writes ARCHIVE_PATH,
 * so the logic moved here and both controllers call it. Behaviour is identical
 * to the original private methods: SetupController now delegates.
 *
 * The whole repo is bind-mounted into the container, so .env is writable from
 * app code. Every value is written on ONE physical line and any CR/LF/quote is
 * rejected, so a caller-supplied path or URL can never forge extra .env lines.
 */
final class EnvFile
{
    /** Read a single KEY's value from the repo-root .env (unquoted, trimmed), or null if absent. */
    public static function read(string $key): ?string
    {
        $envPath = base_path('.env');
        if (! is_file($envPath)) {
            return null;
        }
        foreach (preg_split('/\r\n|\r|\n/', (string) file_get_contents($envPath)) ?: [] as $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*(.*)$/', $line, $m)) {
                return trim($m[1], " \t\"'");
            }
        }

        return null;
    }

    /** Normalize a host path for .env: Windows backslashes -> forward slashes (docker-compose accepts them cross-platform). */
    public static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }

    /**
     * Upsert KEY=value pairs into the repo-root .env, preserving all other
     * lines. Values with whitespace are double-quoted. Creates .env from
     * .env.example if it is somehow missing.
     *
     * @param  array<string, string>  $kv
     */
    public static function write(array $kv): void
    {
        $envPath = base_path('.env');
        if (! is_file($envPath) && is_file(base_path('.env.example'))) {
            @copy(base_path('.env.example'), $envPath);
        }
        $contents = is_file($envPath) ? (string) file_get_contents($envPath) : '';
        $lines    = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        foreach ($kv as $key => $value) {
            // .env-injection guard: a value is written on ONE physical line, so a
            // CR/LF would let a caller-supplied path/URL forge additional .env
            // lines (e.g. DB_HOST=attacker) that Laravel reads on the next boot.
            // A double-quote would break out of the wrapper the same way. A real
            // filesystem path or URL never contains any of these — reject hard.
            if (preg_match('/[\r\n"]/', $value)) {
                throw new \RuntimeException('value for '.$key.' contains an illegal character (newline or quote)');
            }
            $render = (preg_match('/\s/', $value) ? '"'.$value.'"' : $value);
            $line   = $key.'='.$render;
            $found  = false;
            foreach ($lines as $i => $existing) {
                if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $existing)) {
                    $lines[$i] = $line;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $lines[] = $line;
            }
        }

        $out = rtrim(implode("\n", $lines), "\n")."\n";
        if (@file_put_contents($envPath, $out) === false) {
            throw new \RuntimeException('write failed (permission?)');
        }
    }
}

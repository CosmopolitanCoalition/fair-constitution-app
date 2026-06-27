<?php

namespace Tests\Constitutional;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — `matrix:setup` (K3-C.5). The command generates the matched secret bundle that wires
 * the game-upstream OIDC delegation. THE INVARIANTS: the game↔MAS shared secret is IDENTICAL on both sides
 * (the .env OIDC_MAS_CLIENT_SECRET == the rendered MAS config client_secret); the provider ULID binds the
 * MAS provider id to the game's registered redirect_uri (exact-match must hold); the MAS config disables
 * passwords + carries the upstream_oauth2 block + the pseudonym-only localpart mapping; and no private key
 * material leaks into .env. If these drift, the delegation silently breaks.
 */
class MatrixSetupTest extends TestCase
{
    public function test_setup_generates_a_consistent_matched_bundle(): void
    {
        $base = sys_get_temp_dir().'/cga-matrixsetup-'.bin2hex(random_bytes(6));
        $envPath = $base.'.env';
        $masPath = $base.'.mas.yaml';

        try {
            Artisan::call('matrix:setup', [
                '--issuer' => 'https://boxa.example',
                '--mas-issuer' => 'https://auth.boxa.example',
                '--server-name' => 'boxa.example',
                '--env-path' => $envPath,
                '--mas-config-path' => $masPath,
            ]);

            $env = (string) file_get_contents($envPath);
            $mas = (string) file_get_contents($masPath);

            // The game↔MAS shared secret is IDENTICAL on both sides.
            preg_match('/^OIDC_MAS_CLIENT_SECRET="?([^"\n]+)"?$/m', $env, $envSecret);
            preg_match('/client_secret:\s*(\S+)/', $mas, $masSecret);
            $this->assertNotEmpty($envSecret[1] ?? null);
            $this->assertSame($envSecret[1], $masSecret[1] ?? null, 'the OIDC client secret must match across .env and the MAS config');

            // The provider ULID binds the MAS provider id to the game's redirect_uri (exact-match invariant).
            preg_match('/id:\s*([0-9A-HJKMNP-TV-Z]{26})/', $mas, $ulid);
            $this->assertNotEmpty($ulid[1] ?? null, 'a valid 26-char ULID provider id');
            preg_match('/^OIDC_MAS_REDIRECT_URIS="?([^"\n]+)"?$/m', $env, $redirect);
            $this->assertSame('https://auth.boxa.example/upstream/callback/'.$ulid[1], $redirect[1] ?? null,
                'the registered redirect_uri = MAS callback path + provider ULID');

            // MAS config shape: passwords off, upstream block, pseudonym-only localpart, oidc discovery for deploy.
            $this->assertMatchesRegularExpression('/passwords:\s*\n\s*enabled:\s*false/', $mas);
            $this->assertStringContainsString('upstream_oauth2:', $mas);
            $this->assertStringContainsString('discovery_mode: oidc', $mas);
            $this->assertStringContainsString('template: "{{ user.preferred_username }}"', $mas);
            $this->assertStringContainsString('issuer: https://boxa.example', $mas);

            // No private key material EVER lands in .env (it belongs only in the MAS config, gitignored).
            $this->assertStringNotContainsString('PRIVATE KEY', $env);
            $this->assertStringContainsString('PRIVATE KEY', $mas, 'MAS signing keys are generated into its config');

            // --print-only writes nothing.
            $printPath = $base.'.print.env';
            Artisan::call('matrix:setup', ['--print-only' => true, '--env-path' => $printPath]);
            $this->assertFileDoesNotExist($printPath);
        } finally {
            @unlink($envPath);
            @unlink($masPath);
        }
    }
}

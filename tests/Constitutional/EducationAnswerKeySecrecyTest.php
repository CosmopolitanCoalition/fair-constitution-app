<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use PHPUnit\Framework\TestCase;

/**
 * CONSTITUTIONAL PIN — the K-2 training gate's integrity (operator ruling A5;
 * K2_ENGINE_PLAN §2, the answer-key rail).
 *
 * The act-gate ("acquiring is free, acting asks") is only as real as its
 * comprehension quiz, and the quiz is only as real as its answer keys'
 * secrecy. The failure mode is permanent: a REJECTED F-EDU filing whose
 * payload carried an answer key would seal that key into the append-only,
 * federating audit chain — no delete, no rotation. Two independent layers:
 *
 *   1. Architectural (primary): grading is server-side; answer keys never
 *      enter a form payload, an Inertia prop, or the client bundle at all.
 *   2. Belt-and-suspenders: ConstitutionalEngine::SENSITIVE_KEYS strips
 *      `correct_keys` / `answer_key` / `answers` from every chain entry,
 *      including rejections — landed BEFORE any F-EDU form registered.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class EducationAnswerKeySecrecyTest extends TestCase
{
    /**
     * Layer 2, behaviorally: sanitize() strips the three answer-key
     * variants at any nesting depth and any casing.
     */
    public function test_sanitize_strips_answer_keys_at_any_depth_and_case(): void
    {
        $engine = (new \ReflectionClass(ConstitutionalEngine::class))->newInstanceWithoutConstructor();
        $sanitize = new \ReflectionMethod(ConstitutionalEngine::class, 'sanitize');

        $out = $sanitize->invoke($engine, [
            'module_key' => 'legislature.floor-vote',
            'correct_keys' => ['q1' => 'b'],
            'Answer_Key' => 'b',
            'nested' => [
                'ANSWERS' => ['q1' => 'b', 'q2' => 'a'],
                'score' => 100,
                'deeper' => ['answer_key' => 'x'],
            ],
        ]);

        $this->assertSame(
            ['module_key' => 'legislature.floor-vote', 'nested' => ['score' => 100, 'deeper' => []]],
            $out,
            'An answer key survived sanitize() — a rejected F-EDU filing would seal it into the chain.'
        );
    }

    /**
     * The constant itself carries all three variants (the stripping above
     * is case-lowered membership, so the pinned spellings are lowercase).
     */
    public function test_sensitive_keys_names_all_three_answer_key_variants(): void
    {
        $keys = (new \ReflectionClassConstant(ConstitutionalEngine::class, 'SENSITIVE_KEYS'))->getValue();

        foreach (['correct_keys', 'answer_key', 'answers'] as $variant) {
            $this->assertContains($variant, $keys, "SENSITIVE_KEYS lost '{$variant}' — the K-2 belt-and-suspenders is off.");
        }
    }

    /**
     * Layer 1, source-scanned: no code under app/ touches `correct_keys`
     * outside the allowlisted server-side surfaces. Controllers, resources,
     * serializers, and handlers can therefore never select it into a
     * response or a form payload. Extending the allowlist is a deliberate
     * act reviewed against K2_ENGINE_PLAN §2 — grading code that READS the
     * catalog may join; anything that would SHIP the key may not.
     */
    public function test_no_app_code_touches_correct_keys_outside_the_allowlist(): void
    {
        $root = str_replace('\\', '/', \dirname(__DIR__, 2).'/app');

        $allowed = [
            // The stripping constant necessarily names the key.
            $root.'/Domain/Engine/ConstitutionalEngine.php',
            // The F-EDU handlers name the key only to REFUSE payloads
            // carrying it (their FORBIDDEN_KEYS refusal) — the opposite of
            // shipping it. Their returned audit payloads are fixed-shape and
            // key-free by construction.
            $root.'/Domain/Forms/Handlers/TrainingCompletion.php',
            $root.'/Domain/Forms/Handlers/TrainingMaterialPublication.php',
            // Grading READS the catalog server-side and returns a boolean, a
            // score, and explain keys — never the key (the §2 architectural
            // layer lives exactly here).
            $root.'/Services/Education/GradingService.php',
            // The seeder WRITES the server catalog into the server table —
            // config to DB, nothing client-ward.
            $root.'/Console/Commands/EducationSeedCommand.php',
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if (in_array($path, $allowed, true)) {
                continue;
            }

            // Strip comments — prose may NAME the rail; code may not touch it.
            $code = preg_replace('!/\*.*?\*/!s', '', file_get_contents($path));
            $code = preg_replace('!//.*$!m', '', $code);

            $this->assertStringNotContainsString(
                'correct_keys',
                $code,
                "{$path} touches correct_keys — the answer-key catalog is server-side config, read only by allowlisted grading code (K2_ENGINE_PLAN §2/§8.1)."
            );
        }
    }

    /**
     * Layer 1 on the client plane: the browser bundle never carries an
     * answer key under any spelling. The v3 mockup (learn/lesson.html)
     * ships a client-side answer index — that shortcut is pinned OUT of
     * the real pages here, ahead of their build. The learner's own
     * submitted answers are not secret; the CORRECT ones are, so the scan
     * targets the correct-key spellings, not the word 'answers'.
     */
    public function test_client_source_never_carries_an_answer_key(): void
    {
        $root = str_replace('\\', '/', \dirname(__DIR__, 2).'/resources/js');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! in_array($file->getExtension(), ['js', 'vue', 'ts'], true)) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $code = file_get_contents($path);

            foreach (['correct_keys', 'correctKeys', 'answer_key', 'answerKey'] as $spelling) {
                $this->assertStringNotContainsString(
                    $spelling,
                    $code,
                    "{$path} carries '{$spelling}' — answer keys are server-side only (K2_ENGINE_PLAN §2/§8.1; the mockup's client-side index is the must-not-copy)."
                );
            }
        }
    }
}

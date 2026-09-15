<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Catalogue pin for the php-refusals-a lane.
 *
 * Verifies the lane's user-visible refusal strings are wired for translation:
 *  (1) no literal-message ConstitutionalViolation throw survives outside __().
 *  (2) every __('...') literal in the lane files is a key in lang/en.json
 *      whose value equals the key.
 *  (3) lang/en.json parses and its keys are sorted.
 *
 * DB-free. Reads source text only.
 */
class PhpCatalogue_php_refusals_aTest extends TestCase
{
    /** @var list<string> */
    private const LANE_FILES = [
        'app/Domain/Forms/Handlers/AdvocateCaseFiling.php',
        'app/Domain/Forms/Handlers/AdvocateRegistration.php',
        'app/Domain/Forms/Handlers/AgendaSetting.php',
        'app/Domain/Forms/Handlers/AppealFiling.php',
        'app/Domain/Forms/Handlers/AssetRegistration.php',
        'app/Domain/Forms/Handlers/AttendanceCompulsionOrder.php',
        'app/Domain/Forms/Handlers/AttendanceRegistration.php',
        'app/Domain/Forms/Handlers/BillIntroduction.php',
        'app/Domain/Forms/Handlers/BillReferralToFloor.php',
        'app/Domain/Forms/Handlers/BoardElectionAdministration.php',
        'app/Domain/Forms/Handlers/BoardGovernorNomination.php',
        'app/Domain/Forms/Handlers/BoardMemberRemovalRequest.php',
        'app/Domain/Forms/Handlers/BudgetAct.php',
        'app/Domain/Forms/Handlers/CaseFiling.php',
        'app/Domain/Forms/Handlers/CommitteeAgendaSetting.php',
        'app/Domain/Forms/Handlers/CommitteeChairVote.php',
        'app/Domain/Forms/Handlers/CommitteeMeetingAdjourn.php',
        'app/Domain/Forms/Handlers/CommitteeMeetingOpen.php',
        'app/Domain/Forms/Handlers/CommitteeReportFiling.php',
        'app/Domain/Forms/Handlers/CommitteeVoteCast.php',
        'app/Domain/Forms/Handlers/Concerns/ResolvesChairActor.php',
        'app/Domain/Forms/Handlers/ConstitutionalFinding.php',
        'app/Domain/Forms/Handlers/DepartmentInvestigationOrder.php',
        'app/Domain/Forms/Handlers/DepartmentPolicyProposal.php',
        'app/Domain/Forms/Handlers/DepartmentReportFiling.php',
        'app/Domain/Forms/Handlers/DepartmentRuleImplementation.php',
        'app/Domain/Forms/Handlers/EmergencyPowersDeclaration.php',
        'app/Domain/Forms/Handlers/EmergencyPowersRenewal.php',
        'app/Domain/Forms/Handlers/EmergencyPowersReview.php',
        'app/Domain/Forms/Handlers/ExecutiveOfficeCreationAct.php',
        'app/Domain/Forms/Handlers/ExecutiveOrder.php',
        'app/Domain/Forms/Handlers/FloorVoteCast.php',
        'app/Domain/Forms/Handlers/FundsTransfer.php',
        'app/Domain/Forms/Handlers/InternalRestructuring.php',
        'app/Domain/Forms/Handlers/JudicialRemedyApplication.php',
        'app/Domain/Forms/Handlers/JudiciaryOverrideVote.php',
    ];

    public function test_no_literal_message_constitutional_violation_outside_translation(): void
    {
        $offenders = [];

        foreach (self::LANE_FILES as $rel) {
            $src = $this->source($rel);
            $offset = 0;

            while (($pos = strpos($src, 'new ConstitutionalViolation(', $offset)) !== false) {
                $open = $pos + strlen('new ConstitutionalViolation(') - 1; // points at '('
                $arg = ltrim($this->firstArg($src, $open));
                $offset = $pos + 1;

                $first = $arg === '' ? '' : $arg[0];
                $isLiteral = $first === "'" || $first === '"';
                $isSprintf = strncmp($arg, 'sprintf(', 8) === 0;

                if ($isLiteral || $isSprintf) {
                    $line = substr_count(substr($src, 0, $pos), "\n") + 1;
                    $offenders[] = $rel.':'.$line;
                }
            }
        }

        $this->assertSame([], $offenders, "Literal-message ConstitutionalViolation throws outside __():\n".implode("\n", $offenders));
    }

    public function test_every_translation_literal_is_a_catalog_key(): void
    {
        $catalog = $this->catalog();
        $missing = [];

        foreach (self::LANE_FILES as $rel) {
            $src = $this->source($rel);
            $offset = 0;

            while (($pos = strpos($src, '__(', $offset)) !== false) {
                // Only a bare __( call, not a longer identifier ending in __(.
                $before = $pos > 0 ? $src[$pos - 1] : ' ';
                $offset = $pos + 1;
                if (ctype_alnum($before) || $before === '_' || $before === '$' || $before === '>') {
                    continue;
                }

                $open = $pos + strlen('__(') - 1; // points at '('
                $arg = ltrim($this->firstArg($src, $open));

                $first = $arg === '' ? '' : $arg[0];
                if ($first !== "'" && $first !== '"') {
                    continue; // non-literal first argument
                }

                $key = $this->decodeLiteral($this->leadingLiteral($arg));

                if (! array_key_exists($key, $catalog)) {
                    $missing[] = $rel.' :: MISSING KEY :: '.$key;
                } elseif ($catalog[$key] !== $key) {
                    $missing[] = $rel.' :: VALUE != KEY :: '.$key;
                }
            }
        }

        $this->assertSame([], $missing, "Translation literals not backed by lang/en.json:\n".implode("\n", $missing));
    }

    public function test_catalog_parses_and_keys_are_sorted(): void
    {
        $path = base_path('lang/en.json');
        $this->assertFileExists($path, 'lang/en.json is missing.');

        $raw = file_get_contents($path);
        $data = json_decode($raw, true);

        $this->assertIsArray($data, 'lang/en.json is not valid JSON.');

        $keys = array_keys($data);
        $sorted = $keys;
        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $keys, 'lang/en.json keys are not sorted (SORT_STRING).');
    }

    /**
     * The first argument text of a call. $open points at the '(' that opens
     * the argument list. Respects nested parens/brackets and string literals,
     * returning the text up to the first top-level comma (or the closing ')').
     */
    private function firstArg(string $src, int $open): string
    {
        $i = $open + 1;
        $start = $i;
        $depth = 0;
        $n = strlen($src);

        while ($i < $n) {
            $c = $src[$i];

            if ($c === "'" || $c === '"') {
                $i = $this->skipString($src, $i);
                continue;
            }
            if ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                break;
            }
            $i++;
        }

        return substr($src, $start, $i - $start);
    }

    /** Return the index just past the string literal that begins at $i. */
    private function skipString(string $src, int $i): int
    {
        $q = $src[$i];
        $j = $i + 1;
        $n = strlen($src);

        while ($j < $n) {
            $c = $src[$j];
            if ($c === '\\') {
                $j += 2;
                continue;
            }
            if ($c === $q) {
                return $j + 1;
            }
            $j++;
        }

        return $n;
    }

    /** The leading string literal (with quotes) of an expression. */
    private function leadingLiteral(string $arg): string
    {
        $end = $this->skipString($arg, 0);

        return substr($arg, 0, $end);
    }

    /** Decode a PHP string literal to its runtime value. */
    private function decodeLiteral(string $lit): string
    {
        $body = substr($lit, 1, -1);

        if ($lit[0] === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }

        // Double-quoted: decode the escapes our catalog could contain.
        return str_replace(['\\"', '\\\\', '\\n', '\\t'], ['"', '\\', "\n", "\t"], $body);
    }

    private function source(string $rel): string
    {
        $path = base_path($rel);
        $this->assertFileExists($path, "Lane file is missing: {$rel}");

        return (string) file_get_contents($path);
    }

    /** @return array<string,string> */
    private function catalog(): array
    {
        $path = base_path('lang/en.json');
        $this->assertFileExists($path, 'lang/en.json is missing.');

        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data, 'lang/en.json is not valid JSON.');

        /** @var array<string,string> $data */
        return $data;
    }
}

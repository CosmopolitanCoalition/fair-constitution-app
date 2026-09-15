<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Catalogue pin for lane php-refusals-b.
 *
 * The lane wraps every literal refusal message in __() and records each string
 * in lang/en.json. This test is the standing guard.
 *
 * (1) No lane file throws a literal-message ConstitutionalViolation outside __().
 * (2) Every __() literal key in the lane files is a lang/en.json key whose value
 *     equals the key.
 * (3) lang/en.json parses and its keys are sorted.
 *
 * DB-free. It reads source text only.
 */
class PhpCatalogue_php_refusals_bTest extends TestCase
{
    /** @var string[] */
    private const LANE_FILES = [
        'app/Domain/Forms/Handlers/ManualDistrictDraw.php',
        'app/Domain/Forms/Handlers/MarketplaceListingOrder.php',
        'app/Domain/Forms/Handlers/MemberPriorityFacilitation.php',
        'app/Domain/Forms/Handlers/MotionSubmission.php',
        'app/Domain/Forms/Handlers/OpinionRulingFiling.php',
        'app/Domain/Forms/Handlers/OrganizationDissolution.php',
        'app/Domain/Forms/Handlers/OrganizationMarketParticipation.php',
        'app/Domain/Forms/Handlers/OrganizationMembershipApplication.php',
        'app/Domain/Forms/Handlers/OrganizationProfileManagement.php',
        'app/Domain/Forms/Handlers/OrganizationStaffDelegation.php',
        'app/Domain/Forms/Handlers/OwnershipTransferInitiation.php',
        'app/Domain/Forms/Handlers/PetitionConstitutionalReview.php',
        'app/Domain/Forms/Handlers/PublicPrivateConversionRequest.php',
        'app/Domain/Forms/Handlers/PublicRecordStatement.php',
        'app/Domain/Forms/Handlers/QuorumCountPublication.php',
        'app/Domain/Forms/Handlers/ReferendumActModification.php',
        'app/Domain/Forms/Handlers/ReferendumDelegation.php',
        'app/Domain/Forms/Handlers/RemedyRecommendation.php',
        'app/Domain/Forms/Handlers/RemovalPresiding.php',
        'app/Domain/Forms/Handlers/RemovalVote.php',
        'app/Domain/Forms/Handlers/ResidentAgreement.php',
        'app/Domain/Forms/Handlers/SentencingOrder.php',
        'app/Domain/Forms/Handlers/SessionCall.php',
        'app/Domain/Forms/Handlers/SessionMinutesPublication.php',
        'app/Domain/Forms/Handlers/SocialRemoval.php',
        'app/Domain/Forms/Handlers/SocialTestimonyFiling.php',
        'app/Domain/Forms/Handlers/TieBreakingVote.php',
        'app/Domain/Forms/Handlers/VacancyDeclaration.php',
        'app/Domain/Forms/Handlers/WarrantIssuance.php',
        'app/Domain/Forms/Handlers/WorkApplication.php',
        'app/Domain/Forms/Handlers/WorkerBoardElectionAdministration.php',
        'app/Domain/Forms/Handlers/WorkerRegistration.php',
        'app/Services/BillService.php',
        'app/Services/CertificationService.php',
        'app/Services/ChamberVoteService.php',
        'app/Services/ConstitutionalValidator.php',
    ];

    private function catalog(): array
    {
        $path = base_path('lang/en.json');
        $this->assertFileExists($path, 'lang/en.json is missing.');
        $raw = file_get_contents($path);
        $json = json_decode($raw, true);
        $this->assertIsArray($json, 'lang/en.json does not parse to an object: '.json_last_error_msg());

        return $json;
    }

    /** Decode a T_CONSTANT_ENCAPSED_STRING token to its runtime value. */
    private function decodeConstString(string $raw): string
    {
        $q = $raw[0];
        $body = substr($raw, 1, -1);
        if ($q === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }
        $out = '';
        $n = strlen($body);
        $map = ['n' => "\n", 't' => "\t", 'r' => "\r", 'v' => "\v", 'f' => "\f", 'e' => "\e", '\\' => '\\', '"' => '"', '$' => '$'];
        for ($i = 0; $i < $n; $i++) {
            $c = $body[$i];
            if ($c === '\\' && $i + 1 < $n) {
                $x = $body[$i + 1];
                if (isset($map[$x])) {
                    $out .= $map[$x];
                    $i++;
                    continue;
                }
                // PHP keeps a backslash placed before any other character.
                $out .= '\\';
                continue;
            }
            $out .= $c;
        }

        return $out;
    }

    private const SKIP = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    private function nextMeaningful(array $toks, int $i): int
    {
        $n = count($toks);
        while ($i < $n && is_array($toks[$i]) && in_array($toks[$i][0], self::SKIP, true)) {
            $i++;
        }

        return $i;
    }

    public function test_no_literal_message_constitutional_violation_outside_translator(): void
    {
        $offenders = [];
        foreach (self::LANE_FILES as $rel) {
            $path = base_path($rel);
            $this->assertFileExists($path, "lane file missing: $rel");
            $toks = token_get_all(file_get_contents($path));
            $n = count($toks);
            for ($i = 0; $i < $n; $i++) {
                $t = $toks[$i];
                if (! (is_array($t) && $t[0] === T_NEW)) {
                    continue;
                }
                $j = $this->nextMeaningful($toks, $i + 1);
                if (! ($j < $n && is_array($toks[$j]) && in_array($toks[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true))) {
                    continue;
                }
                $name = $toks[$j][1];
                if (! str_ends_with($name, 'ConstitutionalViolation')) {
                    continue;
                }
                $k = $this->nextMeaningful($toks, $j + 1);
                if (! ($k < $n && $toks[$k] === '(')) {
                    continue;
                }
                $m = $this->nextMeaningful($toks, $k + 1);
                if ($m < $n && is_array($toks[$m]) && $toks[$m][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $offenders[] = $rel.':'.$toks[$m][2];
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Literal-message ConstitutionalViolation throws outside __():\n  ".implode("\n  ", $offenders)
        );
    }

    public function test_every_translator_key_is_in_the_catalog(): void
    {
        $catalog = $this->catalog();
        $missing = [];
        $seen = 0;
        foreach (self::LANE_FILES as $rel) {
            $path = base_path($rel);
            $toks = token_get_all(file_get_contents($path));
            $n = count($toks);
            for ($i = 0; $i < $n; $i++) {
                $t = $toks[$i];
                if (! (is_array($t) && $t[0] === T_STRING && $t[1] === '__')) {
                    continue;
                }
                $j = $this->nextMeaningful($toks, $i + 1);
                if (! ($j < $n && $toks[$j] === '(')) {
                    continue;
                }
                $k = $this->nextMeaningful($toks, $j + 1);
                if (! ($k < $n && is_array($toks[$k]) && $toks[$k][0] === T_CONSTANT_ENCAPSED_STRING)) {
                    $missing[] = "$rel:".(is_array($toks[$k]) ? $toks[$k][2] : '?')." __() first argument is not a literal string";
                    continue;
                }
                $seen++;
                $key = $this->decodeConstString($toks[$k][1]);
                if (! array_key_exists($key, $catalog)) {
                    $missing[] = "$rel:{$toks[$k][2]} key not in catalog: [$key]";
                } elseif ($catalog[$key] !== $key) {
                    $missing[] = "$rel:{$toks[$k][2]} catalog value != key for: [$key]";
                }
            }
        }

        $this->assertGreaterThan(0, $seen, 'no __() calls found in lane files');
        $this->assertSame([], $missing, "catalog gaps:\n  ".implode("\n  ", $missing));
    }

    public function test_catalog_parses_and_keys_are_sorted(): void
    {
        $catalog = $this->catalog();
        $keys = array_keys($catalog);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys, 'lang/en.json keys are not sorted (SORT_STRING).');
        foreach ($catalog as $k => $v) {
            // Lines flattened from lang/en/*.php (php artisan i18n:lang-flatten) carry a
            // dotted group key and the English text; the identity rule is for literal-string lines.
            if (preg_match('/^(auth|pagination|passwords|validation)\./', $k)) {
                continue;
            }
            $this->assertSame($k, $v, "catalog value must equal key for: [$k]");
        }
    }
}

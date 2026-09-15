<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Catalogue pin for lane php-refusals-c (namespace php).
 *
 * Gates the PHP side of the i18n catalogue for this lane's 36 service files:
 *  1. No ConstitutionalViolation throw carries a literal message outside __().
 *  2. Every __('literal') first argument in these files is a key in lang/en.json
 *     whose value equals the key.
 *  3. lang/en.json parses and its keys are sorted (SORT_STRING, the generation order).
 *
 * DB-free: the test only reads and tokenizes source. It runs no query.
 */
class PhpCatalogue_php_refusals_cTest extends TestCase
{
    /** The lane's page list, in wiring order. */
    private const FILES = [
        'app/Services/ElectionLifecycleService.php',
        'app/Services/EmergencyPowerService.php',
        'app/Services/EnactmentService.php',
        'app/Services/Executive/BoardGovernorService.php',
        'app/Services/Executive/DepartmentService.php',
        'app/Services/Executive/ExecutiveFormationService.php',
        'app/Services/Executive/GrantService.php',
        'app/Services/Federation/CapabilityProber.php',
        'app/Services/Federation/CapabilityService.php',
        'app/Services/Federation/TransportService.php',
        'app/Services/Identity/MeshRoleGrantService.php',
        'app/Services/Judiciary/ConstitutionalChallengeService.php',
        'app/Services/Judiciary/JudicialSeatService.php',
        'app/Services/Judiciary/JudiciaryOverrideService.php',
        'app/Services/Jurisdictions/BorderSettlementService.php',
        'app/Services/Jurisdictions/DisintermediationService.php',
        'app/Services/Jurisdictions/RestorationService.php',
        'app/Services/Jurisdictions/UnionService.php',
        'app/Services/Legislature/ChamberActService.php',
        'app/Services/Legislature/CommitteeService.php',
        'app/Services/Legislature/OversightService.php',
        'app/Services/Legislature/SpeakerService.php',
        'app/Services/Matrix/CarveoutEmitterService.php',
        'app/Services/MultiJurisdictionVoteService.php',
        'app/Services/Organizations/CgcIpRegisterService.php',
        'app/Services/Organizations/OrgBoardElectionService.php',
        'app/Services/Organizations/OrgConversionService.php',
        'app/Services/Organizations/OrgMembershipService.php',
        'app/Services/Organizations/OrgRegistryService.php',
        'app/Services/Organizations/OrgTransferService.php',
        'app/Services/PeerUpgradeAgreementService.php',
        'app/Services/PetitionService.php',
        'app/Services/ReferendumService.php',
        'app/Services/ResidencyService.php',
        'app/Services/SessionService.php',
        'app/Services/Social/SocialSpaceService.php',
    ];

    private function decodeLiteral(string $lit): string
    {
        if ($lit[0] === "'") {
            return preg_replace("/\\\\(['\\\\])/", '$1', substr($lit, 1, -1));
        }
        $map = ["\\n" => "\n", "\\t" => "\t", "\\r" => "\r", "\\v" => "\v", "\\f" => "\f", "\\\"" => "\"", "\\\$" => "\$", "\\\\" => "\\", "\\e" => "\e"];
        return strtr(substr($lit, 1, -1), $map);
    }

    /** Byte offset of every token, so a first-argument span can be located. */
    private function offsets(array $tokens): array
    {
        $offsets = []; $pos = 0;
        foreach ($tokens as $idx => $tk) { $offsets[$idx] = $pos; $pos += strlen(is_array($tk) ? $tk[1] : $tk); }
        return $offsets;
    }

    /** Indices of the first-argument tokens of the ConstitutionalViolation call opened at $callTokenIndex. */
    private function firstArgIndices(array $tokens, int $callTokenIndex): array
    {
        $n = count($tokens);
        $j = $callTokenIndex + 1;
        while ($j < $n && !(is_string($tokens[$j]) && $tokens[$j] === '(')) $j++;
        if ($j >= $n) return [];
        $depth = 0; $out = [];
        for ($k = $j + 1; $k < $n; $k++) {
            $tk = $tokens[$k]; $s = is_array($tk) ? $tk[1] : $tk;
            $isCurly = is_array($tk) && ($tk[0] === T_CURLY_OPEN || $tk[0] === T_DOLLAR_OPEN_CURLY_BRACES);
            if ((is_string($tk) && ($s === '(' || $s === '[' || $s === '{')) || $isCurly) $depth++;
            elseif (is_string($tk) && ($s === ')' || $s === ']' || $s === '}')) { if ($depth === 0) break; $depth--; }
            elseif (is_string($tk) && $s === ',' && $depth === 0) break;
            $out[] = $k;
        }
        return $out;
    }

    public function test_no_literal_message_constitutional_violation_outside_translate(): void
    {
        $offenders = [];
        foreach (self::FILES as $rel) {
            $path = base_path($rel);
            $src = file_get_contents($path);
            $tokens = token_get_all($src);
            $n = count($tokens);
            for ($i = 0; $i < $n; $i++) {
                $t = $tokens[$i];
                if (!(is_array($t) && $t[0] === T_STRING && $t[1] === 'ConstitutionalViolation')) continue;
                $p = $i - 1; while ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) $p--;
                if (!(is_array($tokens[$p]) && $tokens[$p][0] === T_NEW)) continue;
                $line = $t[2];
                $argIdx = $this->firstArgIndices($tokens, $i);
                // Walk the first-argument tokens; flag any string literal not inside a __(...) call.
                $parenStack = []; // bool per open paren: is it a __( paren
                $pendingTrans = false;
                foreach ($argIdx as $ci) {
                    $tk = $tokens[$ci];
                    if (is_array($tk) && $tk[0] === T_STRING && $tk[1] === '__') { $pendingTrans = true; continue; }
                    if (is_array($tk) && $tk[0] === T_WHITESPACE) continue;
                    if (is_string($tk) && $tk === '(') { $parenStack[] = $pendingTrans; $pendingTrans = false; continue; }
                    if (is_string($tk) && $tk === ')') { array_pop($parenStack); continue; }
                    $pendingTrans = false;
                    if (is_array($tk) && ($tk[0] === T_CONSTANT_ENCAPSED_STRING || $tk[0] === T_ENCAPSED_AND_WHITESPACE)) {
                        $insideTrans = in_array(true, $parenStack, true);
                        if (!$insideTrans) $offenders[] = "$rel:$line";
                    }
                }
            }
        }
        $this->assertSame([], array_values(array_unique($offenders)),
            "ConstitutionalViolation messages still literal (outside __()):\n" . implode("\n", array_unique($offenders)));
    }

    public function test_every_translate_key_is_in_catalog(): void
    {
        $catalogPath = base_path('lang/en.json');
        $this->assertFileExists($catalogPath, 'lang/en.json is missing');
        $catalog = json_decode(file_get_contents($catalogPath), true);
        $this->assertIsArray($catalog, 'lang/en.json did not parse to an array');

        $missing = [];
        foreach (self::FILES as $rel) {
            $tokens = token_get_all(file_get_contents(base_path($rel)));
            $n = count($tokens);
            for ($i = 0; $i < $n; $i++) {
                $t = $tokens[$i];
                if (!(is_array($t) && $t[0] === T_STRING && $t[1] === '__')) continue;
                $j = $i + 1; while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
                if (!($j < $n && is_string($tokens[$j]) && $tokens[$j] === '(')) continue;
                $k = $j + 1; while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) $k++;
                if (!($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_CONSTANT_ENCAPSED_STRING)) continue;
                $key = $this->decodeLiteral($tokens[$k][1]);
                if (!array_key_exists($key, $catalog)) { $missing[] = "$rel: key absent: $key"; continue; }
                if ($catalog[$key] !== $key) { $missing[] = "$rel: value != key: $key"; }
            }
        }
        $this->assertSame([], $missing, "Catalog gaps:\n" . implode("\n", $missing));
    }

    public function test_catalog_parses_and_keys_sorted(): void
    {
        $catalogPath = base_path('lang/en.json');
        $raw = file_get_contents($catalogPath);
        $catalog = json_decode($raw, true);
        $this->assertIsArray($catalog, 'lang/en.json did not parse');
        $keys = array_keys($catalog);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys, 'lang/en.json keys are not sorted (SORT_STRING)');
    }
}

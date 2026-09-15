<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * DB-free source pins for gap lane fix-boardroom-read (operator ruling
 * 2026-09-15, rubric boardroom-page-access answer B). A public body's board
 * reads for every resident; a private organization's board keeps its 403.
 */
final class Wiring_fix_boardroom_readTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function read(string $relative): string
    {
        $path = $this->root().'/'.$relative;
        self::assertFileExists($path, $relative.' must exist');

        return (string) file_get_contents($path);
    }

    public function test_board_room_access_isPublic_uses_department_and_cgc_rule(): void
    {
        $src = $this->read('app/Services/Rooms/BoardRoomAccess.php');
        self::assertMatchesRegularExpression('/function\s+isPublic\s*\(\s*Board\s+\$board\s*\)\s*:\s*bool/', $src,
            'isPublic(Board): bool must exist');
        self::assertStringContainsString('BOARDABLE_DEPARTMENTS', $src,
            'a department board is a public body');
        self::assertStringContainsString('is_cgc', $src,
            'a CGC organization board is a public body');
    }

    public function test_controller_board_branches_on_isPublic_and_passes_canJoin(): void
    {
        $src = $this->read('app/Http/Controllers/Rooms/InstitutionRoomController.php');
        self::assertStringContainsString('$this->boards->isPublic($board)', $src,
            'board() must consult isPublic');
        self::assertMatchesRegularExpression('/if\s*\(\s*!\s*\$this->boards->isPublic\(\$board\)\s*\)\s*\{\s*\$this->boards->assertMayJoin/s', $src,
            'assertMayJoin stays only for a non-public board');
        self::assertMatchesRegularExpression("/'canJoin'\s*=>/", $src,
            'the page must carry the canJoin prop');
    }
}

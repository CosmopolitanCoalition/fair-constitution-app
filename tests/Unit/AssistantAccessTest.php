<?php

namespace Tests\Unit;

use App\Services\Assistant\AssistantAccess;
use App\Services\Node\NodeChoices;
use PHPUnit\Framework\TestCase;

/** W-0522 - the assistant never holds more than its owner allows. No database. */
class AssistantAccessTest extends TestCase
{
    private function access(): AssistantAccess
    {
        return new AssistantAccess(new NodeChoices());
    }

    public function test_defaults_allow_a_read_tool_on_an_owned_store(): void
    {
        $d = $this->access()->decideFrom([], 'agenda');

        $this->assertTrue($d['allowed']);
        $this->assertFalse($d['needs_approval']);
    }

    public function test_removing_the_read_right_on_the_store_denies_the_tool(): void
    {
        $choices = ['stores' => ['stores' => ['calendar' => ['assistant' => []]]]];
        $d = $this->access()->decideFrom($choices, 'agenda');

        $this->assertFalse($d['allowed']);
        $this->assertSame('missing-right-read', $d['reason']);
    }

    public function test_a_write_tool_needs_the_write_right(): void
    {
        $readOnly = ['stores' => ['stores' => ['tasks' => ['assistant' => ['read']]]]];

        $this->assertSame('missing-right-write', $this->access()->decideFrom($readOnly, 'tasks')['reason']);
        $this->assertTrue($this->access()->decideFrom([], 'tasks')['allowed']);
    }

    public function test_a_tool_the_person_switched_off_is_denied(): void
    {
        $d = $this->access()->decideFrom(['tools' => ['off' => ['contacts' => true]]], 'contacts');

        $this->assertSame('switched-off', $d['reason']);
    }

    public function test_tools_without_a_handler_and_unknown_tools_are_refused(): void
    {
        $this->assertSame('not-built', $this->access()->decideFrom([], 'schedule')['reason']);
        $this->assertSame('not-built', $this->access()->decideFrom([], 'admin')['reason']);
        $this->assertSame('unknown-tool', $this->access()->decideFrom([], 'nope')['reason']);
    }
}

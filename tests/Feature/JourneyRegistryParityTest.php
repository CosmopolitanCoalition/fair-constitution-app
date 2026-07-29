<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * PIN — the journeys registry must not drift from the server config.
 *
 * There are two sources for a journey arc and they answer different questions:
 * `config/cga/journeys.php` is the SERVER source (it validates step marking, and
 * its `status` decides whether an arc is walkable), while
 * `resources/js/registry/journeys.js` is the CLIENT DISPLAY source (class label,
 * your-part copy, rooms, earn copy). The registry file says so at the top: "keep
 * the three in sync."
 *
 * They drifted, silently, and the failure mode is ugly rather than loud:
 * Journey.vue does `JOURNEYS_BY_ID[props.journey.id] ?? null` and then falls back
 * per-field, so a MISSING arc still renders — it just renders the raw
 * interaction-class id ("people") where a human label belongs, plus the generic
 * your-part and earn lines. `become-a-resident` — the first arc a new player ever
 * walks — was in that state. A stale `status` is the mirror defect: three arcs sat
 * at 'planned' with dead "Phase L/M" labels long after Phase L+M shipped.
 *
 * Nothing here validates copy or opinion; only that the two files agree on WHICH
 * arcs exist and WHETHER each is live. Adding an arc means adding it to both.
 */
class JourneyRegistryParityTest extends TestCase
{
    private function registrySource(): string
    {
        $path = base_path('resources/js/registry/journeys.js');
        $this->assertFileExists($path, 'the client journeys registry is missing');

        return (string) file_get_contents($path);
    }

    /** @return array<string, array{title: string, status: string, cls: string}> */
    private function serverArcs(): array
    {
        $arcs = [];
        foreach ((array) config('cga.journeys') as $id => $spec) {
            if (is_array($spec) && isset($spec['title'], $spec['status'])) {
                $arcs[$id] = $spec;
            }
        }

        $this->assertNotEmpty($arcs, 'config/cga/journeys.php declares no arcs');

        return $arcs;
    }

    public function test_every_server_arc_exists_in_the_client_registry(): void
    {
        $js = $this->registrySource();
        $missing = [];

        foreach (array_keys($this->serverArcs()) as $id) {
            if (! str_contains($js, "id: '{$id}'")) {
                $missing[] = $id;
            }
        }

        $this->assertSame([], $missing, 'arcs live server-side but absent from registry/journeys.js, '
            .'so Journey.vue will render their raw class id where a label belongs: '.implode(', ', $missing));
    }

    public function test_no_arc_disagrees_about_being_live(): void
    {
        $js = $this->registrySource();
        $disagreements = [];

        foreach ($this->serverArcs() as $id => $spec) {
            $offset = strpos($js, "id: '{$id}'");
            if ($offset === false) {
                continue; // covered by the previous test — one failure, not two
            }

            // The entry's own slice, up to the next arc's `id:` or the array end.
            $next = strpos($js, "\n        id: '", $offset + 1);
            $slice = $next === false
                ? substr($js, $offset)
                : substr($js, $offset, $next - $offset);

            if (! preg_match("/status: '([a-z-]+)'/", $slice, $m)) {
                $disagreements[] = "{$id}: registry declares no status";
                continue;
            }

            if ($m[1] !== $spec['status']) {
                $disagreements[] = "{$id}: server '{$spec['status']}' vs registry '{$m[1]}'";
            }

            // A live arc must not carry a "Planned · Phase N" label — that string
            // is what the sitemap and journey cards render for unbuilt surfaces.
            if ($spec['status'] === 'live' && preg_match("/phase: '/", $slice)) {
                $disagreements[] = "{$id}: live server-side but still carries a phase label";
            }
        }

        $this->assertSame([], $disagreements, 'journeys registry disagrees with config/cga/journeys.php: '
            .implode(' | ', $disagreements));
    }
}

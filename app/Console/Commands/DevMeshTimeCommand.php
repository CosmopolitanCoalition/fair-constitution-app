<?php

namespace App\Console\Commands;

use App\Http\Middleware\DevTimeControlsEnabled;
use App\Services\Dev\DemoMeshTimeCoordinator;
use Illuminate\Console\Command;

/**
 * Demo-mesh time coordination — the CLI twin of the Demo-flyout mesh controls
 * and the /dev/clock/coordinator endpoint (parity law). Reads the coordination
 * state and, for the operator, sets it.
 *
 *   php artisan dev:mesh-time                     # who coordinates, who follows
 *   php artisan dev:mesh-time --self              # THIS node coordinates
 *   php artisan dev:mesh-time --set=<server_id>   # follow another node
 *   php artisan dev:mesh-time --tolerate-skew     # assert §4 skew tolerance
 *   php artisan dev:mesh-time --strict            # withdraw it (the default)
 *
 * Behind the SAME base gate as the rest of the playtest controls: it answers
 * only on a demo-mode node (RULED §10 item 4). A coordinator's `dev:clock-advance
 * --apply` publishes the mesh record automatically; a follower's is refused with
 * the coordinator named — this command is where that relationship is set and seen.
 */
class DevMeshTimeCommand extends Command
{
    protected $signature = 'dev:mesh-time
                            {--self : Make THIS node the mesh time coordinator}
                            {--set= : Follow the node with this server_id (its advances replay here)}
                            {--tolerate-skew : Assert §4 skew tolerance — advance this node independently}
                            {--strict : Withdraw skew tolerance (the default, fail-closed direction)}';

    protected $description = 'Playtest control: see or set the demo-mesh time coordinator';

    public function handle(DemoMeshTimeCoordinator $mesh): int
    {
        if ($reason = DevTimeControlsEnabled::refusalReason()) {
            $this->error($reason);

            return self::FAILURE;
        }

        try {
            if ($this->option('tolerate-skew') && $this->option('strict')) {
                $this->error('Choose one: --tolerate-skew or --strict, not both.');

                return self::FAILURE;
            }

            if ($this->option('tolerate-skew')) {
                $mesh->setSkewTolerance(true);
            } elseif ($this->option('strict')) {
                $mesh->setSkewTolerance(false);
            }

            if ($this->option('self') && $this->option('set')) {
                $this->error('Choose one: --self or --set=<server_id>, not both.');

                return self::FAILURE;
            }

            if ($this->option('self')) {
                $mesh->setCoordinator(null);
            } elseif ($this->option('set')) {
                $mesh->setCoordinator((string) $this->option('set'));
            }
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->render($mesh->status());

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $status
     */
    private function render(array $status): void
    {
        $role = $status['role'];
        $coord = $status['coordinator'];

        $this->line('');
        $this->line(match ($role) {
            'coordinator' => '  <options=bold;fg=green>This node is the demo-mesh time COORDINATOR.</>',
            'follower'    => '  <options=bold;fg=yellow>This node FOLLOWS '.$coord['label'].'.</>',
            default       => '  <options=bold>This node is SOLO — no demo peers to coordinate.</>',
        });
        $this->line('');
        $this->line('  Coordinator      '.($coord['is_self'] ? 'this node' : $coord['label']));
        $this->line('  Declared-demo peers  '.$status['demo_peers']);
        $this->line('  Skew tolerated (§4)  '.($status['skew_tolerated'] ? 'YES — independent advance allowed' : 'no (strict)'));

        if ($status['local_advance_refusal'] !== null) {
            $this->line('');
            $this->line('  <fg=yellow>Local advance is refused here:</>');
            $this->line('  <fg=gray>'.$status['local_advance_refusal'].'</>');
        }

        if ($status['recent'] !== []) {
            $this->line('');
            $this->line('  Recent advances:');
            $this->table(
                ['advance', 'days', 'origin', 'issued by', 'applied'],
                array_map(static fn (array $a): array => [
                    substr((string) $a['advance_id'], 0, 8),
                    $a['days'],
                    $a['origin'],
                    $a['issued_by'] ? substr((string) $a['issued_by'], 0, 8) : '—',
                    $a['applied_at'],
                ], $status['recent']),
            );
        }

        $this->line('');
    }
}

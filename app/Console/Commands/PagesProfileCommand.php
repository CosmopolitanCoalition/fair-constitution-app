<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * THE PAGE PROFILER (operator order 2026-11-01, the slow-page hunt). Requests every GET page
 * of the app inside this process as a signed-in operator and records, per route: wall time,
 * database queries, database time, the slowest query, response size and status. Read only:
 * GET requests, no write routes, no dev or logout routes. One route at a time, with the result
 * written after each one so a long run shows its progress and a kill loses one route.
 *
 *   php artisan pages:profile --list                 # the inventory, nothing requested
 *   php artisan pages:profile                        # profile every page
 *   php artisan pages:profile --only=/elections      # routes whose URI starts with this
 *   php artisan pages:profile --api                  # include /api GET routes
 *
 * Output: a table sorted slowest first, and storage/logs/pages_profile.jsonl.
 */
class PagesProfileCommand extends Command
{
    protected $signature = 'pages:profile
        {--list : Print the inventory and request nothing}
        {--only= : Only routes whose URI starts with this}
        {--api : Include /api routes}
        {--dump : Print every query of each requested page (use with --only)}
        {--user= : Operator email (default: the first operator)}
        {--slug=usa-3-mecklenburg-2 : Jurisdiction slug used for {jurisdiction} routes}';

    protected $description = 'Time every GET page: queries, database time, slowest query.';

    /** Route prefixes that are never requested: writes, dev tools, auth, infrastructure. */
    private const SKIP = ['dev/', 'logout', 'horizon', 'telescope', 'sanctum', 'storage/', 'up', '_', 'broadcasting', 'api/dev', 'api/setup'];

    public function handle(): int
    {
        $routes = $this->inventory();

        if ($this->option('list')) {
            foreach ($routes as $r) {
                $this->line(sprintf('%-6s %s', $r['skip'] ?? 'GET', $r['uri']));
            }
            $this->info(count(array_filter($routes, fn ($r) => ! isset($r['skip']))).' requestable, '.count(array_filter($routes, fn ($r) => isset($r['skip']))).' skipped.');

            return self::SUCCESS;
        }

        $email = $this->option('user');
        $user = $email
            ? User::query()->where('email', $email)->first()
            : User::query()->where('is_operator', true)->first();
        if ($user === null) {
            $this->error('No operator user found.');

            return self::FAILURE;
        }
        Auth::guard('web')->setUser($user);

        $kernel = app(Kernel::class);
        $log = storage_path('logs/pages_profile.jsonl');
        file_put_contents($log, '');
        $results = [];

        // One listener for the whole run. A listener added per route would stack, and every
        // earlier one would keep appending to the current route's list.
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = ['ms' => $q->time, 'sql' => $q->sql];
        });

        foreach ($routes as $r) {
            if (isset($r['skip'])) {
                continue;
            }

            $queries = [];

            $started = microtime(true);
            $status = 0;
            $bytes = 0;
            try {
                $request = Request::create($r['url'], 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
                $response = $kernel->handle($request);
                $status = $response->getStatusCode();
                $bytes = strlen((string) $response->getContent());
                $kernel->terminate($request, $response);
            } catch (\Throwable $e) {
                $status = 500;
            }
            $ms = (int) ((microtime(true) - $started) * 1000);

            $inOrder = $queries;
            usort($queries, fn ($a, $b) => $b['ms'] <=> $a['ms']);
            $row = [
                'url'      => $r['url'],
                'status'   => $status,
                'ms'       => $ms,
                'queries'  => count($queries),
                'db_ms'    => (int) array_sum(array_column($queries, 'ms')),
                'bytes'    => $bytes,
                'slowest'  => $queries === [] ? null : ['ms' => (int) $queries[0]['ms'], 'sql' => mb_substr(preg_replace('/\s+/', ' ', $queries[0]['sql']), 0, 220)],
            ];
            $results[] = $row;
            file_put_contents($log, json_encode($row)."\n", FILE_APPEND);
            $this->line(sprintf('%5dms %3d q %5dms db  %d  %s', $ms, $row['queries'], $row['db_ms'], $status, $r['url']));
            if ($this->option('dump')) {
                foreach ($inOrder as $q) {
                    $this->line(sprintf('      %4dms  %s', (int) $q['ms'], mb_substr(preg_replace('/\s+/', ' ', $q['sql']), 0, 200)));
                }
            }
        }

        usort($results, fn ($a, $b) => $b['ms'] <=> $a['ms']);
        $this->newLine();
        $this->info('Slowest 15:');
        foreach (array_slice($results, 0, 15) as $row) {
            $this->line(sprintf('%6dms  %3d q  %6dms db  %s', $row['ms'], $row['queries'], $row['db_ms'], $row['url']));
            if ($row['slowest'] !== null) {
                $this->line(sprintf('          slowest query %dms: %s', $row['slowest']['ms'], $row['slowest']['sql']));
            }
        }

        return self::SUCCESS;
    }

    /** @return list<array{uri:string,url:string,skip?:string}> */
    private function inventory(): array
    {
        $only = (string) $this->option('only');
        $slug = (string) $this->option('slug');
        $out = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = $route->uri();
            if ($only !== '' && ! str_starts_with('/'.$uri, $only)) {
                continue;
            }

            $skip = null;
            foreach (self::SKIP as $prefix) {
                if (str_starts_with($uri, $prefix)) {
                    $skip = 'SKIP';
                }
            }
            if (! $this->option('api') && str_starts_with($uri, 'api/')) {
                $skip = 'API';
            }

            // Fill {jurisdiction} with the sample slug. Any other parameter is not guessed.
            $url = $uri;
            if (preg_match_all('/\{(\w+)\??\}/', $uri, $m)) {
                foreach ($m[1] as $name) {
                    if ($name === 'jurisdiction') {
                        $url = preg_replace('/\{jurisdiction\??\}/', $slug, $url);
                    }
                }
                if (preg_match('/\{\w+\??\}/', $url)) {
                    $skip = $skip ?? 'PARAM';
                }
            }

            $out[] = ['uri' => $uri, 'url' => '/'.ltrim($url, '/'), 'skip' => $skip] + [];
            if ($skip === null) {
                unset($out[array_key_last($out)]['skip']);
            }
        }

        usort($out, fn ($a, $b) => strcmp($a['uri'], $b['uri']));

        return $out;
    }
}

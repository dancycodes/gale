<?php

namespace Dancycodes\Gale\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * gale:routes Artisan Command
 *
 * Lists all routes registered via Gale's attribute-based route discovery system.
 * Output is similar to Laravel's route:list but filtered to show only Gale-discovered
 * routes. Supports filtering by HTTP method, URI path pattern, route name, and
 * controller class. Also supports JSON output for tooling integration.
 *
 * Routes are identified by the 'gale' marker added to their action array during
 * registration by RouteRegistrar::registerRoutes().
 *
 * @see \Dancycodes\Gale\Routing\RouteRegistrar
 */
class GaleRoutesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gale:routes
        {--method= : Filter by HTTP method (GET, POST, PUT, PATCH, DELETE)}
        {--path= : Filter by URI path pattern (substring match)}
        {--name= : Filter by route name pattern (substring match)}
        {--controller= : Filter by controller class name (partial or full)}
        {--json : Output routes as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all routes registered via Gale attribute-based discovery';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $routes = $this->getGaleRoutes();

        $routes = $this->applyFilters($routes);

        if ($routes->isEmpty()) {
            $this->components->warn('No Gale routes found.');

            return Command::SUCCESS;
        }

        if ($this->option('json')) {
            $this->outputJson($routes);
        } else {
            $this->outputTable($routes);
        }

        return Command::SUCCESS;
    }

    /**
     * Collect all Gale-discovered routes from the Laravel route collection.
     *
     * Routes are identified by the 'gale' marker added to their action array
     * during registration in RouteRegistrar::registerRoutes().
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function getGaleRoutes(): Collection
    {
        /** @var \Illuminate\Routing\RouteCollection $routeCollection */
        $routeCollection = app('router')->getRoutes();

        return collect($routeCollection->getRoutes())
            ->filter(fn (Route $route) => ! empty($route->action['gale']))
            ->map(fn (Route $route) => $this->formatRoute($route))
            ->values();
    }

    /**
     * Format a single route into a display-ready array.
     *
     * @return array<string, mixed>
     */
    protected function formatRoute(Route $route): array
    {
        $methods = $route->methods();

        // Remove HEAD from methods (Laravel always adds HEAD alongside GET)
        $methods = array_filter($methods, fn (string $m) => $m !== 'HEAD');

        $action = $route->getAction();
        $controller = $action['controller'] ?? null;

        // Normalize controller@method format
        if (is_array($action['uses'] ?? null)) {
            [$class, $method] = $action['uses'];
            $controller = $class.'@'.$method;
        } elseif (is_string($action['uses'] ?? null)) {
            $controller = $action['uses'];
        }

        // Shorten controller namespace for readability in table view
        $shortController = $controller ? $this->shortenController($controller) : '—';

        $middleware = $route->gatherMiddleware();

        return [
            'method' => implode('|', array_values($methods)),
            'uri' => '/'.$route->uri(),
            'name' => $route->getName() ?? '—',
            'middleware' => implode(', ', $middleware) ?: '—',
            'action' => $shortController,
            'action_full' => $controller ?? '—',
        ];
    }

    /**
     * Shorten a fully-qualified controller class to a readable format.
     *
     * Removes common namespace prefixes for cleaner table output.
     * Full name is preserved in 'action_full' for JSON output.
     */
    protected function shortenController(string $controller): string
    {
        // Convert App\Http\Controllers\Foo\BarController@method → Foo\BarController@method
        return preg_replace('/^App\\\\Http\\\\Controllers\\\\/', '', $controller) ?? $controller;
    }

    /**
     * Apply CLI filter options to the routes collection.
     *
     * All filters use AND logic — a route must pass every specified filter.
     *
     * @param  Collection<int, array<string, mixed>>  $routes
     * @return Collection<int, array<string, mixed>>
     */
    protected function applyFilters(Collection $routes): Collection
    {
        $method = $this->option('method');
        $path = $this->option('path');
        $name = $this->option('name');
        $controller = $this->option('controller');

        if ($method) {
            $method = strtoupper($method);
            $routes = $routes->filter(
                fn (array $route) => Str::contains($route['method'], $method)
            );
        }

        if ($path) {
            $routes = $routes->filter(
                fn (array $route) => Str::contains($route['uri'], $path)
            );
        }

        if ($name) {
            $routes = $routes->filter(
                fn (array $route) => $route['name'] !== '—' && Str::contains($route['name'], $name)
            );
        }

        if ($controller) {
            $routes = $routes->filter(function (array $route) use ($controller) {
                $full = $route['action_full'];

                // Match against full class name or just the basename
                $basename = class_basename(Str::before($full, '@'));

                return Str::contains($full, $controller) || Str::contains($basename, $controller);
            });
        }

        return $routes->values();
    }

    /**
     * Render routes as a formatted table.
     *
     * @param  Collection<int, array<string, mixed>>  $routes
     */
    protected function outputTable(Collection $routes): void
    {
        $this->components->twoColumnDetail(
            '<fg=green;options=bold>Gale Routes</>',
            '<fg=gray>'.$routes->count().' route'.($routes->count() === 1 ? '' : 's').'</>'
        );

        $this->newLine();

        $this->table(
            ['Method', 'URI', 'Name', 'Middleware', 'Action'],
            $routes->map(fn (array $route) => [
                $this->colorizeMethod($route['method']),
                $route['uri'],
                $route['name'],
                $route['middleware'],
                $route['action'],
            ])->all()
        );
    }

    /**
     * Render routes as JSON to stdout.
     *
     * @param  Collection<int, array<string, mixed>>  $routes
     */
    protected function outputJson(Collection $routes): void
    {
        $output = $routes->map(fn (array $route) => [
            'method' => $route['method'],
            'uri' => $route['uri'],
            'name' => $route['name'] !== '—' ? $route['name'] : null,
            'middleware' => $route['middleware'] !== '—' ? explode(', ', $route['middleware']) : [],
            'action' => $route['action_full'],
        ])->values()->all();

        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Colorize HTTP method string for terminal output.
     *
     * Uses standard color conventions: GET=green, POST=yellow,
     * PUT/PATCH=blue, DELETE=red.
     */
    protected function colorizeMethod(string $method): string
    {
        $colors = [
            'GET' => 'green',
            'POST' => 'yellow',
            'PUT' => 'blue',
            'PATCH' => 'blue',
            'DELETE' => 'red',
        ];

        $parts = explode('|', $method);

        $colored = array_map(function (string $part) use ($colors) {
            $color = $colors[$part] ?? 'white';

            return "<fg={$color}>{$part}</>";
        }, $parts);

        return implode('<fg=gray>|</>', $colored);
    }
}

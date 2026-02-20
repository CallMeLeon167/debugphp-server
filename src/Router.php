<?php

/**
 * This file is part of the DebugPHP Server.
 *
 * (c) Leon Schmidt <kontakt@callmeleon.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @see https://github.com/CallMeLeon167/debugphp-server
 */

declare(strict_types=1);

namespace DebugPHP\Server;

/**
 * Simple HTTP router.
 *
 * Allows registering routes by HTTP method and URL pattern.
 * URL placeholders are automatically extracted as parameters
 * and passed to the matching controller method.
 */
final class Router
{
    /**
     * All registered routes.
     *
     * Each route is an array with:
     * - method:   HTTP method (GET, POST, DELETE)
     * - pattern:  Regex pattern to match against the URL
     * - callback: Callable to invoke on match
     * - params:   Names of URL parameters (extracted from {name} placeholders)
     *
     * @var list<array{method: string, pattern: string, callback: callable, params: list<string>}>
     */
    private array $routes = [];

    /**
     * Registers a GET route.
     *
     * @param string   $path     The URL path (e.g. "/" or "/api/session/{id}").
     * @param callable $callback The callback to invoke.
     */
    public function get(string $path, callable $callback): void
    {
        $this->add('GET', $path, $callback);
    }

    /**
     * Registers a POST route.
     *
     * @param string   $path     The URL path.
     * @param callable $callback The callback to invoke.
     */
    public function post(string $path, callable $callback): void
    {
        $this->add('POST', $path, $callback);
    }

    /**
     * Registers a DELETE route.
     *
     * @param string   $path     The URL path.
     * @param callable $callback The callback to invoke.
     */
    public function delete(string $path, callable $callback): void
    {
        $this->add('DELETE', $path, $callback);
    }

    /**
     * Dispatches the incoming request against all registered routes.
     *
     * Matches the current URL and HTTP method against each route.
     * If a route matches, its callback is invoked with extracted URL parameters.
     * If no route matches, a 404 JSON response is sent.
     *
     * @param Request $request The current HTTP request.
     */
    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path   = $request->path();

        // Answer CORS preflight requests immediately
        if ($method === 'OPTIONS') {
            http_response_code(204);

            return;
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $path, $matches) !== 1) {
                continue;
            }

            // Extract URL parameters (e.g. {id} → 'abc123')
            $params = [];
            foreach ($route['params'] as $name) {
                $params[] = $matches[$name] ?? '';
            }

            // Invoke the callback and pass URL parameters
            ($route['callback'])(...$params);

            return;
        }

        $this->sendNotFound();
    }

    /**
     * Adds a route and converts the path into a regex pattern.
     *
     * Supports two placeholder types:
     *   {name}      → (?P<name>[a-f0-9]{32})  matches 32-char hex session IDs
     *   {name:int}  → (?P<name>\d+)            matches numeric entry IDs
     *
     * @param string   $method   HTTP method (GET, POST, DELETE).
     * @param string   $path     The URL path with optional {placeholders}.
     * @param callable $callback The callback to invoke on match.
     */
    private function add(string $method, string $path, callable $callback): void
    {
        $params = [];

        $pattern = preg_replace_callback(
            '/\{([a-z_]+)(?::([a-z]+))?\}/',
            static function (array $matches) use (&$params): string {
                /** @var list<string> $params */
                $params[] = $matches[1];

                $type = $matches[2] ?? 'hex';

                return match ($type) {
                    'int'   => '(?P<' . $matches[1] . '>\d+)',
                    default => '(?P<' . $matches[1] . '>[a-f0-9]{32})',
                };
            },
            $path,
        );

        $this->routes[] = [
            'method'   => $method,
            'pattern'  => '#^' . ($pattern ?? $path) . '$#',
            'callback' => $callback,
            'params'   => $params,
        ];
    }

    /**
     * Sends a 404 JSON response.
     */
    private function sendNotFound(): void
    {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Not found']);
    }
}

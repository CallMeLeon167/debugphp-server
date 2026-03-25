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
 * Represents an incoming HTTP request.
 *
 * Encapsulates all information about the current request:
 * HTTP method, URL path, and the decoded JSON body. Provides
 * clean accessor methods instead of accessing $_SERVER and
 * php://input directly throughout the codebase.
 *
 * Created via the static factory method:
 *   $request = Request::fromGlobals();
 */
final class Request
{
    /**
     * @param string               $method HTTP method (GET, POST, etc.).
     * @param string               $path   Normalised URL path (no trailing slash, basePath stripped).
     * @param array<string, mixed> $body   Decoded JSON body or empty array.
     */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $body,
    ) {}

    /**
     * Creates a Request instance from the current PHP superglobals.
     *
     * Reads the HTTP method and URL from $_SERVER, decodes the JSON
     * body from php://input, strips the configured basePath, and
     * normalises the path (removes trailing slash).
     *
     * @return self The current HTTP request.
     */
    public static function fromGlobals(): self
    {
        $methodRaw = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $method = strtoupper(is_string($methodRaw) ? $methodRaw : 'GET');

        $rawUri  = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = is_string($rawUri) ? $rawUri : '/';
        $rawPath = parse_url($uri, PHP_URL_PATH);
        $path    = is_string($rawPath) ? $rawPath : '/';

        $baseUrl = Config::baseUrl();

        if ($baseUrl !== '' && str_starts_with($path, $baseUrl)) {
            $path = substr($path, strlen($baseUrl));

            if ($path === '') {
                $path = '/';
            }
        }

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $body = [];
        $raw  = file_get_contents('php://input');

        if (is_string($raw) && $raw !== '') {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $body = $decoded;
            }
        }

        return new self($method, $path, $body);
    }

    /**
     * Returns the HTTP method (e.g. "GET", "POST", "DELETE").
     *
     * @return string The HTTP method in uppercase.
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Returns the normalised URL path with basePath stripped (e.g. "/api/session").
     *
     * @return string The URL path.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Returns the entire decoded JSON body.
     *
     * @return array<string, mixed> The request body.
     */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * Reads a string value from the request body.
     *
     * @param string $key     The key to look up in the JSON body.
     * @param string $default Fallback value if the key is missing or not a string.
     *
     * @return string The value or the default.
     */
    public function getString(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * Reads an integer value from the request body.
     *
     * @param string $key     The key to look up in the JSON body.
     * @param int    $default Fallback value if the key is missing or not numeric.
     *
     * @return int The value or the default.
     */
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->body[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Returns the raw value for a key from the request body.
     *
     * @param string $key The key to look up.
     *
     * @return mixed The value or null if the key is missing.
     */
    public function get(string $key): mixed
    {
        return $this->body[$key] ?? null;
    }

    /**
     * Checks whether a key exists in the request body.
     *
     * @param string $key The key to check.
     *
     * @return bool True if the key is present.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }
}

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

// ─── Routing ─────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

match (true) {
    // Dashboard
    $method === 'GET' && $path === '/'
    => $controller->dashboard(),

    // Sessions
    $method === 'POST' && $path === '/api/session'
    => $controller->createSession(),

    // Delete session
    $method === 'DELETE' && preg_match('#^/api/session/([a-f0-9]{32})$#', $path, $m) === 1
    => $controller->deleteSession($m[1]),

    // Debug entries
    $method === 'POST' && $path === '/api/debug'
    => $controller->storeEntry(),

    // Clear entries
    $method === 'POST' && $path === '/api/clear'
    => $controller->clearEntries(),

    // SSE Stream
    $method === 'GET' && preg_match('#^/api/stream/([a-f0-9]{32})$#', $path, $m) === 1
    => $stream->handle($m[1]),

    // 404
    default => (function (): void {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Not found']);
    })(),
};

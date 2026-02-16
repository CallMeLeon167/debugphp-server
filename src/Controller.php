<?php

/**
 * This file is part of the DebugPHP Server.
 *
 * (c) Leon Schmidt <leon@kontakt@callmeleon.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @see https://github.com/CallMeLeon167/debugphp-server
 */

declare(strict_types=1);

namespace DebugPHP\Server;

/**
 * Handles all HTTP requests for the DebugPHP server.
 *
 * Contains route handlers for the dashboard, session management,
 * and debug entry processing. Each public method corresponds to
 * one or more routes defined in index.php.
 */
final class Controller
{
    /**
     * The database instance for all queries.
     */
    private Database $db;

    /**
     * Creates a new controller instance.
     *
     * @param Database $db The database instance.
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ─── Dashboard ───────────────────────────────────────────

    /**
     * Renders the dashboard HTML page.
     *
     * GET /
     */
    public function dashboard(): void
    {
        $templatePath = __DIR__ . '/../templates/dashboard.html';

        if (!file_exists($templatePath)) {
            $this->sendJson(['error' => 'Dashboard template not found'], 500);

            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        readfile($templatePath);
    }

    // ─── Sessions ────────────────────────────────────────────

    /**
     * Creates a new debug session.
     *
     * POST /api/session
     *
     * Response: 201 Created with session data (id, created_at, expires_at).
     */
    public function createSession(): void
    {
        $session = $this->db->createSession();

        $this->sendJson($session, 201);
    }

    /**
     * Deletes a session and all its entries.
     *
     * DELETE /api/session/{id}
     *
     * Response: 200 OK or 404 Not Found.
     *
     * @param string $id The session ID to delete.
     */
    public function deleteSession(string $id): void
    {
        $deleted = $this->db->deleteSession($id);

        if (!$deleted) {
            $this->sendJson(['error' => 'Session not found'], 404);

            return;
        }

        $this->sendJson(['deleted' => true]);
    }

    // ─── Debug Entries ───────────────────────────────────────

    /**
     * Stores a new debug entry.
     *
     * POST /api/debug
     *
     * Expects a JSON body with:
     * - session:   (string) Session ID
     * - data:      (mixed)  The debug data
     * - label:     (string) Optional label
     * - color:     (string) Optional color
     * - type:      (string) Optional type
     * - origin:    (object) Optional {file, line}
     * - timestamp: (float)  Optional microtime
     *
     * Response: 201 Created with entry ID.
     */
    public function storeEntry(): void
    {
        $body = $this->getJsonBody();

        if ($body === null) {
            $this->sendJson(['error' => 'Invalid JSON body'], 400);

            return;
        }

        $sessionId = $this->getString($body, 'session');

        if ($sessionId === '') {
            $this->sendJson(['error' => 'Missing session ID'], 400);

            return;
        }

        $session = $this->db->findSession($sessionId);

        if ($session === null) {
            $this->sendJson(['error' => 'Session not found or expired'], 404);

            return;
        }

        /** @var mixed */
        $data = $body['data'] ?? '';

        /** @var array{file?: string, line?: int} */
        $origin = isset($body['origin']) && is_array($body['origin']) ? $body['origin'] : [];

        $entryId = $this->db->insertEntry(
            sessionId: $sessionId,
            data: $data,
            label: $this->getString($body, 'label'),
            color: $this->getString($body, 'color', 'gray'),
            type: $this->getString($body, 'type', 'info'),
            originFile: $this->getString($origin, 'file'),
            originLine: isset($origin['line']) ? (int) $origin['line'] : 0,
            timestamp: isset($body['timestamp']) ? (float) $body['timestamp'] : microtime(true),
        );

        $this->sendJson(['id' => $entryId], 201);
    }

    /**
     * Clears all entries for a session.
     *
     * POST /api/clear
     *
     * Expects a JSON body with:
     * - session: (string) Session ID
     *
     * Response: 200 OK with count of deleted entries.
     */
    public function clearEntries(): void
    {
        $body = $this->getJsonBody();

        if ($body === null) {
            $this->sendJson(['error' => 'Invalid JSON body'], 400);

            return;
        }

        $sessionId = $this->getString($body, 'session');

        if ($sessionId === '') {
            $this->sendJson(['error' => 'Missing session ID'], 400);

            return;
        }

        $count = $this->db->clearEntries($sessionId);

        $this->sendJson(['cleared' => $count]);
    }

    // ─── Helpers ─────────────────────────────────────────────

    /**
     * Reads and decodes the JSON request body.
     *
     * @return array<string, mixed>|null The decoded body, or null on failure.
     */
    private function getJsonBody(): ?array
    {
        $raw = file_get_contents('php://input');

        if ($raw === false || $raw === '') {
            return null;
        }

        /** @var mixed */
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> */
        return $decoded;
    }

    /**
     * Safely extracts a string value from an associative array.
     *
     * @param array<string, mixed> $data    The source array.
     * @param string               $key     The key to extract.
     * @param string               $default Fallback value if key is missing or not a string.
     *
     * @return string The extracted string value.
     */
    private function getString(array $data, string $key, string $default = ''): string
    {
        if (!isset($data[$key])) {
            return $default;
        }

        return is_string($data[$key]) ? $data[$key] : $default;
    }

    /**
     * Sends a JSON response with the given HTTP status code.
     *
     * @param array<string, mixed>|list<mixed> $data   The response data.
     * @param int                              $status The HTTP status code (default: 200).
     */
    private function sendJson(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}

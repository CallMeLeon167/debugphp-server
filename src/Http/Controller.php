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

namespace DebugPHP\Server\Http;

use DebugPHP\Server\Database\EntryRepository;
use DebugPHP\Server\Database\MetricRepository;
use DebugPHP\Server\Database\SessionRepository;
use DebugPHP\Server\Request;

/**
 * Handles all HTTP requests for the DebugPHP API.
 *
 * Each public method corresponds to a route registered in Application::registerRoutes().
 * The controller reads input from the Request object, delegates work to the
 * repositories, and sends a JSON response.
 */
final class Controller
{
    /**
     * Creates a new controller.
     *
     * @param SessionRepository $sessions Repository for session operations.
     * @param EntryRepository   $entries  Repository for entry operations.
     * @param MetricRepository  $metrics  Repository for metric operations.
     */
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly EntryRepository $entries,
        private readonly MetricRepository $metrics,
    ) {}

    // ─── Dashboard ───────────────────────────────────────────

    /**
     * Serves the dashboard HTML page.
     *
     * GET /
     */
    public function dashboard(): void
    {
        $templatePath = __DIR__ . '/../../templates/dashboard.php';

        if (!file_exists($templatePath)) {
            $this->json(['error' => 'Dashboard template not found'], 500);

            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        include $templatePath;
    }

    // ─── Sessions ────────────────────────────────────────────

    /**
     * Creates a new debug session.
     *
     * POST /api/session
     * Response: 201 with session data (id, created_at, expires_at).
     */
    public function createSession(): void
    {
        $session = $this->sessions->create();

        $this->json($session, 201);
    }

    /**
     * Deletes a session and all its associated entries and metrics.
     *
     * DELETE /api/session/{id}
     * Response: 200 OK or 404 Not Found.
     *
     * @param string $id The session ID from the URL.
     */
    public function deleteSession(string $id): void
    {
        if (!$this->sessions->delete($id)) {
            $this->json(['error' => 'Session not found'], 404);

            return;
        }

        $this->json(['deleted' => true]);
    }

    // ─── Debug Entries ───────────────────────────────────────

    /**
     * Stores a new debug entry.
     *
     * POST /api/debug
     *
     * Expected JSON body:
     *   session    (string) Session ID
     *   request_id (string) Request lifecycle ID from Debug::init()
     *   data       (mixed)  The debug data
     *   label      (string) Optional display label
     *   color      (string) Optional color (default: gray)
     *   type       (string) Optional type  (default: info)
     *   origin     (object) Optional {file, path, line}
     *   timestamp  (float)  Optional, defaults to microtime(true)
     * 
     * Response: 201 with the ID of the new entry.
     */
    public function storeEntry(): void
    {
        $request   = Request::fromGlobals();
        $sessionId = $request->getString('session');

        if ($sessionId === '') {
            $this->json(['error' => 'Missing session ID'], 400);

            return;
        }

        if ($this->sessions->find($sessionId) === null) {
            $this->json(['error' => 'Session not found or expired'], 404);

            return;
        }

        $rawOrigin = $request->get('origin');

        /** @var array<string, mixed> $origin */
        $origin = is_array($rawOrigin) ? $rawOrigin : [];

        $requestId = $request->getString('request_id');

        if ($requestId !== '') {
            $this->metrics->softDeleteStale($sessionId, $requestId);
        }

        $meta = [
            'label' => $request->getString('label'),
            'color' => $request->getString('color', 'gray'),
            'type'  => $request->getString('type', 'info'),
            'origin' => [
                'file' => is_string($origin['file'] ?? null) ? $origin['file'] : '',
                'path' => is_string($origin['path'] ?? null) ? $origin['path'] : '',
                'line' => is_int($origin['line'] ?? null) ? $origin['line'] : 0,
            ],
        ];

        $rawTimestamp = $request->get('timestamp');
        $timestamp = is_numeric($rawTimestamp) ? (float) $rawTimestamp : microtime(true);

        $entryId = $this->entries->insert(
            sessionId: $sessionId,
            requestId: $requestId,
            data: $request->get('data') ?? '',
            meta: $meta,
            timestamp: $request->has('timestamp') ? $timestamp : microtime(true),
        );

        $this->json(['id' => $entryId], 201);
    }

    /**
     * Deletes a single debug entry by its ID.
     *
     * DELETE /api/entry/{id:int}
     *
     * Expected JSON body:
     *   session (string) Session ID — ensures only the owning session can delete
     *
     * Response: 200 { deleted: true } or 400/404 on error.
     *
     * @param string $id The entry ID from the URL (numeric string).
     */
    public function deleteEntry(string $id): void
    {
        $entryId = (int) $id;

        if ($entryId <= 0) {
            $this->json(['error' => 'Invalid entry ID'], 400);

            return;
        }

        $request   = Request::fromGlobals();
        $sessionId = $request->getString('session');

        if ($sessionId === '') {
            $this->json(['error' => 'Missing session ID'], 400);

            return;
        }

        if ($this->sessions->find($sessionId) === null) {
            $this->json(['error' => 'Session not found or expired'], 404);

            return;
        }

        if (!$this->entries->deleteById($entryId, $sessionId)) {
            $this->json(['error' => 'Entry not found'], 404);

            return;
        }

        $this->json(['deleted' => true]);
    }

    /**
     * Clears all entries for a session.
     *
     * POST /api/clear
     *
     * Expected JSON body:
     *   session (string) Session ID
     *
     * Response: 200 with the count of deleted entries.
     */
    public function clearEntries(): void
    {
        $request   = Request::fromGlobals();
        $sessionId = $request->getString('session');

        if ($sessionId === '') {
            $this->json(['error' => 'Missing session ID'], 400);

            return;
        }

        $count = $this->entries->deleteBySession($sessionId);

        $this->json(['cleared' => $count]);
    }

    // ─── Metrics ─────────────────────────────────────────────

    /**
     * Writes or updates a toolbar metric and cleans up stale metrics.
     *
     * POST /api/metric
     *
     * Expected JSON body:
     *   session    (string)      Session ID
     *   key        (string)      Metric name (e.g. "Memory", "HomeTemplate")
     *   value      (string|null) Optional value — null displays only the key
     *   request_id (string)      Request lifecycle ID generated by Debug::init()
     *
     * After upserting the metric, all metrics for this session that carry a
     * different request_id are soft-deleted. The SSE stream will detect those
     * and push metric:remove events to the dashboard automatically.
     *
     * Response: 200 OK or 400/404 on error.
     */
    public function storeMetric(): void
    {
        $request   = Request::fromGlobals();
        $sessionId = $request->getString('session');

        if ($sessionId === '') {
            $this->json(['error' => 'Missing session ID'], 400);

            return;
        }

        if ($this->sessions->find($sessionId) === null) {
            $this->json(['error' => 'Session not found or expired'], 404);

            return;
        }

        $key = $request->getString('key');

        if ($key === '') {
            $this->json(['error' => 'Missing metric key'], 400);

            return;
        }

        $requestId = $request->getString('request_id');

        if ($requestId === '') {
            $this->json(['error' => 'Missing request_id'], 400);

            return;
        }

        $rawValue = $request->get('value');
        $value    = is_string($rawValue) || is_null($rawValue)
            ? $rawValue
            : (is_scalar($rawValue) ? (string) $rawValue : '');

        $this->metrics->upsert($sessionId, $key, $value, $requestId);
        $this->metrics->softDeleteStale($sessionId, $requestId);

        $this->json(['stored' => true]);
    }

    // ─── Helpers ─────────────────────────────────────────────

    /**
     * Sends a JSON response with the given HTTP status code.
     *
     * @param array<string, mixed>|list<mixed> $data   The response data.
     * @param int                              $status HTTP status code (default: 200).
     */
    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}

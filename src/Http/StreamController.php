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
use DebugPHP\Server\Database\SessionRepository;

/**
 * Server-Sent Events (SSE) stream controller.
 *
 * Keeps an HTTP connection open and pushes new debug entries
 * to the dashboard in real-time. The browser connects using
 * the native EventSource API.
 *
 * This class is intentionally separated from Controller because SSE requires
 * a long-running connection with a polling loop — fundamentally different
 * from a normal request/response cycle.
 *
 * GET /api/stream/{id}
 */
final class StreamController
{
    /**
     * Polling interval in seconds between database checks.
     */
    private const POLL_INTERVAL = 1;

    /**
     * Maximum connection lifetime in seconds before forcing a reconnect.
     * Prevents zombie connections from accumulating on the server.
     */
    private const MAX_LIFETIME = 300;

    /**
     * Creates a new stream controller.
     *
     * @param SessionRepository $sessions Repository for session lookups.
     * @param EntryRepository   $entries  Repository for fetching new entries.
     */
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly EntryRepository $entries,
    ) {}

    /**
     * Starts the SSE stream for a given session.
     *
     * Sets the required HTTP headers, then enters a polling loop that
     * fetches new entries from the database every second and pushes them
     * to the connected browser.
     *
     * Emitted event types:
     *   connected  - Sent once on initial connection
     *   entry      - Sent for each new debug entry
     *   expired    - Sent when the session has expired
     *   reconnect  - Sent after MAX_LIFETIME (browser will auto-reconnect)
     *   : heartbeat - Comment sent every cycle to keep the connection alive
     *
     * @param string $sessionId The session ID from the URL.
     */
    public function handle(string $sessionId): void
    {
        if ($this->sessions->find($sessionId) === null) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Session not found or expired']);

            return;
        }

        $this->setHeaders();
        $this->disableBuffering();

        // On browser reconnect: read last known entry ID from the request header
        $lastId = isset($_SERVER['HTTP_LAST_EVENT_ID'])
            ? (int) $_SERVER['HTTP_LAST_EVENT_ID']
            : 0;

        $this->sendEvent('connected', [
            'session'      => $sessionId,
            'resumed_from' => $lastId,
        ]);

        $startTime = time();

        while (true) {
            if (connection_aborted() !== 0) {
                break;
            }

            // Max lifetime reached — force a reconnect
            if ((time() - $startTime) >= self::MAX_LIFETIME) {
                $this->sendEvent('reconnect', ['reason' => 'max_lifetime']);

                break;
            }

            // Session still valid?
            if ($this->sessions->find($sessionId) === null) {
                $this->sendEvent('expired', ['session' => $sessionId]);

                break;
            }

            // Fetch and push new entries
            $newEntries = $this->entries->findNewerThan($sessionId, $lastId);

            foreach ($newEntries as $entry) {
                /** @var mixed */
                $decodedData = unserialize(base64_decode($entry['data']));

                $this->sendEvent('entry', [
                    'id'        => $entry['id'],
                    'data'      => $decodedData,
                    'label'     => $entry['label'],
                    'color'     => $entry['color'],
                    'type'      => $entry['type'],
                    'origin'    => [
                        'file' => $entry['origin_file'],
                        'path' => $entry['origin_path'],
                        'line' => $entry['origin_line'],
                    ],
                    'timestamp' => $entry['timestamp'],
                ], (int) $entry['id']);

                $lastId = (int) $entry['id'];
            }

            echo ": heartbeat\n\n";
            $this->flush();

            sleep(self::POLL_INTERVAL);
        }
    }

    /**
     * Sends a single SSE event to the client.
     *
     * @param string               $event The event name (used with addEventListener on the client).
     * @param array<string, mixed> $data  The event data, JSON-encoded.
     * @param int|null             $id    Optional event ID for Last-Event-ID resume support.
     */
    private function sendEvent(string $event, array $data, ?int $id = null): void
    {
        if ($id !== null) {
            echo "id: {$id}\n";
        }

        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n\n";

        $this->flush();
    }

    /**
     * Sets the required HTTP headers for an SSE connection.
     */
    private function setHeaders(): void
    {
        http_response_code(200);
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
    }

    /**
     * Disables all output buffering layers so data is sent to the client immediately.
     */
    private function disableBuffering(): void
    {
        @ini_set('output_buffering', 'Off');
        @ini_set('zlib.output_compression', '0');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        ob_implicit_flush(true);
    }

    /**
     * Flushes the output buffer to deliver data to the client immediately.
     */
    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}

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
 * Server-Sent Events (SSE) stream handler.
 *
 * Keeps an HTTP connection open and pushes new debug entries
 * to the dashboard in real-time. The browser connects using
 * the native EventSource API.
 *
 * This class is separated from the Controller because SSE requires
 * a long-running connection with a while loop, which is fundamentally
 * different from a normal request/response cycle.
 */
final class Stream
{
    /**
     * The polling interval in seconds between database checks.
     */
    private const POLL_INTERVAL = 1;

    /**
     * The maximum connection duration in seconds before forcing a reconnect.
     * Prevents zombie connections from piling up.
     */
    private const MAX_LIFETIME = 300;

    /**
     * The database instance for querying new entries.
     */
    private Database $db;

    /**
     * Creates a new stream handler.
     *
     * @param Database $db The database instance.
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Starts the SSE stream for a given session.
     *
     * Sets appropriate headers, then enters a polling loop that
     * checks for new entries every second. New entries are pushed
     * as SSE "message" events with JSON-encoded data.
     *
     * The stream also sends:
     * - A "connected" event on initial connection.
     * - A heartbeat comment every cycle to keep the connection alive.
     * - An automatic disconnect after MAX_LIFETIME seconds.
     *
     * The browser's EventSource will automatically reconnect using
     * the Last-Event-ID header to resume where it left off.
     *
     * @param string $sessionId The session ID to stream entries for.
     */
    public function handle(string $sessionId): void
    {
        $session = $this->db->findSession($sessionId);

        if ($session === null) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Session not found or expired']);

            return;
        }

        $this->setHeaders();
        $this->disableBuffering();

        // Resume from Last-Event-ID if the browser is reconnecting
        $lastId = 0;

        if (isset($_SERVER['HTTP_LAST_EVENT_ID'])) {
            $lastId = (int) $_SERVER['HTTP_LAST_EVENT_ID'];
        }

        // Send initial connected event
        $this->sendEvent('connected', [
            'session' => $sessionId,
            'resumed_from' => $lastId,
        ]);

        $startTime = time();

        while (true) {
            // Check if client disconnected
            if (connection_aborted() !== 0) {
                break;
            }

            // Force reconnect after max lifetime
            if ((time() - $startTime) >= self::MAX_LIFETIME) {
                $this->sendEvent('reconnect', ['reason' => 'max_lifetime']);

                break;
            }

            // Check if session still exists
            $session = $this->db->findSession($sessionId);

            if ($session === null) {
                $this->sendEvent('expired', ['session' => $sessionId]);

                break;
            }

            // Fetch new entries
            $entries = $this->db->getEntriesAfter($sessionId, $lastId);

            foreach ($entries as $entry) {
                /** @var mixed */
                $decodedData = json_decode($entry['data'], true);

                $this->sendEvent('entry', [
                    'id' => $entry['id'],
                    'data' => $decodedData,
                    'label' => $entry['label'],
                    'color' => $entry['color'],
                    'type' => $entry['type'],
                    'origin' => [
                        'file' => $entry['origin_file'],
                        'line' => $entry['origin_line'],
                    ],
                    'timestamp' => $entry['timestamp'],
                ], (int) $entry['id']);

                $lastId = (int) $entry['id'];
            }

            // Heartbeat to keep connection alive
            echo ": heartbeat\n\n";

            $this->flush();

            sleep(self::POLL_INTERVAL);
        }
    }

    /**
     * Sends a single SSE event to the client.
     *
     * @param string               $event The event name (used in addEventListener on the client).
     * @param array<string, mixed> $data  The event data, will be JSON-encoded.
     * @param int|null             $id    Optional event ID for resume support.
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
     * Disables all output buffering layers to ensure
     * data is sent to the client immediately.
     */
    private function disableBuffering(): void
    {
        // Disable PHP's implicit flush
        @ini_set('output_buffering', 'Off');
        @ini_set('zlib.output_compression', '0');

        // Clean all existing output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Enable implicit flush
        ob_implicit_flush(true);
    }

    /**
     * Flushes the output buffer to send data immediately.
     */
    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}

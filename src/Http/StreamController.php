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

use DebugPHP\Server\Storage\EntryRepository;
use DebugPHP\Server\Storage\EnvironmentRepository;
use DebugPHP\Server\Storage\MetricRepository;
use DebugPHP\Server\Storage\SessionRepository;

/**
 * Server-Sent Events (SSE) stream controller.
 *
 * Keeps an HTTP connection open and pushes new debug entries as well as
 * toolbar metric updates and removals to the dashboard in real-time.
 *
 * GET /api/stream/{id}
 *
 * Emitted event types:
 *   connected      - Sent once on initial connection
 *   entry          - Sent for each new debug entry
 *   metric         - Sent for each new or updated toolbar metric
 *   metric:remove  - Sent when a metric is no longer present in the current request
 *   environment    - Sent once on connect with the client's PHP runtime info
 *   expired        - Sent when the session has expired
 *   reconnect      - Sent after MAX_LIFETIME (browser will auto-reconnect)
 *   : heartbeat    - Comment sent every cycle to keep the connection alive
 */
final class StreamController
{
    /**
     * Polling interval in seconds between storage checks.
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
     * @param SessionRepository     $sessions     Repository for session lookups.
     * @param EntryRepository       $entries      Repository for fetching new entries.
     * @param MetricRepository      $metrics      Repository for toolbar metrics.
     * @param EnvironmentRepository $environments Repository for environment data.
     */
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly EntryRepository $entries,
        private readonly MetricRepository $metrics,
        private readonly EnvironmentRepository $environments,
    ) {}

    /**
     * Starts the SSE stream for a given session.
     *
     * Sets the required HTTP headers, pushes environment and metrics once on
     * connect, then enters a polling loop that:
     *   1. Fetches and pushes new debug entries
     *   2. Fetches and pushes updated metrics (metric event)
     *   3. Fetches and pushes soft-deleted metrics (metric:remove event),
     *      then hard-deletes them from storage
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
        $lastIdHeader = $_SERVER['HTTP_LAST_EVENT_ID'] ?? null;
        $lastId = is_numeric($lastIdHeader) ? (int) $lastIdHeader : 0;

        $this->sendEvent('connected', [
            'session'      => $sessionId,
            'resumed_from' => $lastId,
        ]);

        $environment = $this->environments->find($sessionId);
        if ($environment !== null) {
            $this->sendEvent('environment', $environment);
        }

        $existingMetrics = $this->metrics->findBySession($sessionId);
        foreach ($existingMetrics as $metric) {
            $this->sendEvent('metric', [
                'key'   => $metric['key'],
                'value' => $metric['value'],
            ]);
        }

        $startTime       = time();
        $lastMetricCheck = date('Y-m-d H:i:s');
        $lastRemoveCheck = date('Y-m-d H:i:s', time() - 1);

        $lastEnvHash = $environment !== null
            ? md5(json_encode($environment, JSON_THROW_ON_ERROR))
            : null;

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

            // ── 1. New debug entries ─────────────────────────
            $newEntries = $this->entries->findNewerThan($sessionId, $lastId);

            foreach ($newEntries as $entry) {
                /** @var mixed $decodedData */
                $decodedData = json_decode($entry['data'], true);

                /** @var array{label: string, color: string, type: string, origin: array{file: string, path: string, line: int}}|null $meta */
                $meta = json_decode($entry['meta'], true);

                $label  = $meta !== null ? $meta['label']  : '';
                $color  = $meta !== null ? $meta['color']  : 'gray';
                $type   = $meta !== null ? $meta['type']   : 'info';
                $origin = $meta !== null ? $meta['origin'] : ['file' => '', 'path' => '', 'line' => 0];

                $this->sendEvent('entry', [
                    'id'         => $entry['id'],
                    'request_id' => $entry['request_id'],
                    'data'       => $decodedData,
                    'label'      => $label,
                    'color'      => $color,
                    'type'       => $type,
                    'origin'     => [
                        'file' => $origin['file'],
                        'path' => $origin['path'],
                        'line' => $origin['line'],
                    ],
                    'timestamp'  => $entry['timestamp'],
                ], (int) $entry['id']);

                $lastId = (int) $entry['id'];
            }

            // ── 2. Updated metrics ───────────────────────────
            $updatedMetrics = $this->metrics->findUpdatedAfter($sessionId, $lastMetricCheck);

            if ($updatedMetrics !== []) {
                $lastMetricCheck = date('Y-m-d H:i:s');

                foreach ($updatedMetrics as $metric) {
                    $this->sendEvent('metric', [
                        'key'   => $metric['key'],
                        'value' => $metric['value'],
                    ]);
                }
            }

            // ── 3. Removed metrics ───────────────────────────
            $removedMetrics = $this->metrics->findRemoved($sessionId, $lastRemoveCheck);

            if ($removedMetrics !== []) {
                $lastRemoveCheck = date('Y-m-d H:i:s');

                foreach ($removedMetrics as $metric) {
                    $this->sendEvent('metric:remove', [
                        'key' => $metric['key'],
                    ]);
                }

                $this->metrics->hardDeleteRemoved($sessionId);
            }

            // ── 4. Environment changes ───────────────────────
            $currentEnv = $this->environments->find($sessionId);
            $currentHash = $currentEnv !== null
                ? md5(json_encode($currentEnv, JSON_THROW_ON_ERROR))
                : null;

            if ($currentHash !== $lastEnvHash && $currentEnv !== null) {
                $this->sendEvent('environment', $currentEnv);
                $lastEnvHash = $currentHash;
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
            ob_end_flush();
        }
    }

    /**
     * Flushes the output buffer to ensure data is sent immediately.
     */
    private function flush(): void
    {
        if (function_exists('ob_flush')) {
            @ob_flush();
        }

        flush();
    }
}

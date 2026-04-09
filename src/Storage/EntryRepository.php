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

namespace DebugPHP\Server\Storage;

/**
 * File-based storage for debug entries.
 *
 * Each entry is stored as a separate JSON file inside the session's entries directory.
 * A counter file (.counter) per session provides auto-incrementing IDs.
 *
 * File naming uses zero-padded 8-digit IDs (e.g. 00000001.json) for natural sort order.
 */
final class EntryRepository
{
    /**
     * The storage path manager.
     *
     * @var StoragePath
     */
    private StoragePath $storage;

    /**
     * Creates a new repository with the given storage path.
     *
     * @param StoragePath $storage The storage path manager.
     */
    public function __construct(StoragePath $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Inserts a new debug entry.
     *
     * Atomically increments the counter file to generate a unique ID,
     * then writes the entry data as a JSON file.
     *
     * @param string $sessionId  The session this entry belongs to.
     * @param string $requestId  The request lifecycle ID from Debug::init().
     * @param mixed  $data       The debug data (any PHP value, JSON-encoded for storage).
     * @param array{
     *     label:  string,
     *     color:  string,
     *     type:   string,
     *     origin: array{file: string, path: string, line: int}
     * }            $meta        Entry metadata.
     * @param float  $timestamp  Microtime timestamp from the client.
     *
     * @return int The ID of the newly created entry.
     */
    public function insert(
        string $sessionId,
        string $requestId,
        mixed $data,
        array $meta,
        float $timestamp,
    ): int {
        $entriesDir = $this->storage->entriesDir($sessionId);

        if (!is_dir($entriesDir)) {
            mkdir($entriesDir, 0755, true);
        }

        $entryId = $this->nextId($sessionId);

        $entry = [
            'id'         => $entryId,
            'session_id' => $sessionId,
            'request_id' => $requestId,
            'data'       => $data,
            'meta'       => $meta,
            'timestamp'  => $timestamp,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        file_put_contents(
            $this->storage->entryFile($sessionId, $entryId),
            json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            LOCK_EX,
        );

        return $entryId;
    }

    /**
     * Returns all entries for a session that are newer than the given ID.
     *
     * Used by the SSE stream to push only new entries to the dashboard.
     * The $afterId parameter allows resuming after a connection drop.
     *
     * @param string $sessionId The session to query.
     * @param int    $afterId   Only return entries with an ID greater than this value.
     *
     * @return list<array{id: int, session_id: string, request_id: string, data: string, meta: string, timestamp: float, created_at: string}>
     */
    public function findNewerThan(string $sessionId, int $afterId): array
    {
        $entriesDir = $this->storage->entriesDir($sessionId);

        if (!is_dir($entriesDir)) {
            return [];
        }

        $files = glob($entriesDir . '/*.json');

        if ($files === false || $files === []) {
            return [];
        }

        sort($files, SORT_STRING);

        /** @var list<array{id: int, session_id: string, request_id: string, data: string, meta: string, timestamp: float, created_at: string}> $results */
        $results = [];

        foreach ($files as $file) {
            $basename = basename($file, '.json');
            $id       = (int) $basename;

            if ($id <= $afterId) {
                continue;
            }

            $content = file_get_contents($file);

            if ($content === false) {
                continue;
            }

            /** @var array{id: int, session_id: string, request_id: string, data: mixed, meta: array{label: string, color: string, type: string, origin: array{file: string, path: string, line: int}}, timestamp: float, created_at: string} $entry */
            $entry = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            // Return data and meta as JSON strings to match the original interface
            $results[] = [
                'id'         => $entry['id'],
                'session_id' => $entry['session_id'],
                'request_id' => $entry['request_id'],
                'data'       => json_encode($entry['data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'meta'       => json_encode($entry['meta'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'timestamp'  => $entry['timestamp'],
                'created_at' => $entry['created_at'],
            ];
        }

        return $results;
    }

    /**
     * Deletes a single entry by its ID, scoped to the given session.
     *
     * The session_id check ensures a client can only delete entries
     * belonging to their own session — no cross-session deletion possible.
     *
     * @param int    $entryId   The entry ID to delete.
     * @param string $sessionId The session the entry must belong to.
     *
     * @return bool True if the entry was found and deleted.
     */
    public function deleteById(int $entryId, string $sessionId): bool
    {
        $file = $this->storage->entryFile($sessionId, $entryId);

        if (!is_file($file)) {
            return false;
        }

        return unlink($file);
    }

    /**
     * Deletes all entries for a session.
     *
     * The session itself remains active — only its entries are removed.
     * The counter is reset to 0 so new entries start from 1 again.
     *
     * @param string $sessionId The session whose entries should be cleared.
     *
     * @return int The number of deleted entries.
     */
    public function deleteBySession(string $sessionId): int
    {
        $entriesDir = $this->storage->entriesDir($sessionId);

        if (!is_dir($entriesDir)) {
            return 0;
        }

        $files = glob($entriesDir . '/*.json');

        if ($files === false) {
            return 0;
        }

        $count = 0;

        foreach ($files as $file) {
            if (unlink($file)) {
                $count++;
            }
        }

        // Reset the counter
        $counterFile = $this->storage->counterFile($sessionId);
        if (is_file($counterFile)) {
            file_put_contents($counterFile, '0', LOCK_EX);
        }

        return $count;
    }

    /**
     * Atomically increments and returns the next entry ID for a session.
     *
     * Uses file locking (flock) to prevent race conditions when multiple
     * requests write entries to the same session concurrently.
     *
     * @param string $sessionId The session ID.
     *
     * @return int The next unique entry ID.
     */
    private function nextId(string $sessionId): int
    {
        $counterFile = $this->storage->counterFile($sessionId);

        $handle = fopen($counterFile, 'c+');

        if ($handle === false) {
            throw new \RuntimeException('Failed to open counter file: ' . $counterFile);
        }

        flock($handle, LOCK_EX);

        $content = stream_get_contents($handle);
        $current = $content !== false && $content !== '' ? (int) $content : 0;
        $next    = $current + 1;

        fseek($handle, 0);
        ftruncate($handle, 0);
        fwrite($handle, (string) $next);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $next;
    }
}

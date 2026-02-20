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

namespace DebugPHP\Server\Database;

use PDO;

/**
 * Database access for debug entries.
 *
 * Handles all database operations for entries — the actual debug data
 * that is sent by the DebugPHP client package via Debug::send().
 */
final class EntryRepository
{
    /**
     * The PDO connection for database queries.
     */
    private PDO $pdo;

    /**
     * Creates a new repository with the given connection.
     *
     * @param Connection $connection The database connection.
     */
    public function __construct(Connection $connection)
    {
        $this->pdo = $connection->pdo();
    }

    /**
     * Inserts a new debug entry.
     *
     * @param string  $sessionId  The session this entry belongs to.
     * @param mixed   $data       The debug data (any PHP value, JSON-encoded for storage).
     * @param string  $label      Display label (e.g. "SQL", "Error").
     * @param string  $color      Display color (e.g. "blue", "red").
     * @param string  $type       Entry type (e.g. "info", "sql", "error").
     * @param string  $originFile Source file from which Debug::send() was called.
     * @param string  $originPath Source path from which Debug::send() was called.
     * @param int     $originLine Line number in the source file.
     * @param float   $timestamp  Microtime timestamp from the client.
     *
     * @return int The ID of the newly created entry.
     */
    public function insert(
        string $sessionId,
        mixed $data,
        string $label,
        string $color,
        string $type,
        string $originFile,
        string $originPath,
        int $originLine,
        float $timestamp,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO entries
                (session_id, data, label, color, type, origin_file, origin_path, origin_line, timestamp, created_at)
             VALUES
                (:session_id, :data, :label, :color, :type, :origin_file, :origin_path, :origin_line, :timestamp, NOW())'
        );

        $stmt->execute([
            'session_id'  => $sessionId,
            'data'        => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'label'       => $label,
            'color'       => $color,
            'type'        => $type,
            'origin_file' => $originFile,
            'origin_path' => $originPath,
            'origin_line' => $originLine,
            'timestamp'   => $timestamp,
        ]);

        return (int) $this->pdo->lastInsertId();
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
     * @return list<array{id: int, session_id: string, data: string, label: string, color: string, type: string, origin_file: string, origin_path: string, origin_line: int, timestamp: float, created_at: string}>
     */
    public function findNewerThan(string $sessionId, int $afterId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, session_id, data, label, color, type, origin_file, origin_path, origin_line, timestamp, created_at
             FROM entries
             WHERE session_id = :session_id AND id > :after_id
             ORDER BY id ASC'
        );
        $stmt->execute([
            'session_id' => $sessionId,
            'after_id'   => $afterId,
        ]);

        /** @var list<array{id: int, session_id: string, data: string, label: string, color: string, type: string, origin_file: string, origin_path: string, origin_line: int, timestamp: float, created_at: string}> */
        return $stmt->fetchAll();
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
        $stmt = $this->pdo->prepare(
            'DELETE FROM entries WHERE id = :id AND session_id = :session_id'
        );
        $stmt->execute([
            'id'         => $entryId,
            'session_id' => $sessionId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Deletes all entries for a session.
     *
     * The session itself remains active — only its entries are removed.
     *
     * @param string $sessionId The session whose entries should be cleared.
     *
     * @return int The number of deleted entries.
     */
    public function deleteBySession(string $sessionId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM entries WHERE session_id = :session_id');
        $stmt->execute(['session_id' => $sessionId]);

        return $stmt->rowCount();
    }
}

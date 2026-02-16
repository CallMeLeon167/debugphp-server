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

use PDO;
use PDOStatement;

/**
 * Database layer for the DebugPHP server.
 *
 * Provides all database operations for sessions and debug entries
 * using PDO with prepared statements. Connects to a MySQL/MariaDB
 * database configured via environment variables.
 */
final class Database
{
    /**
     * The PDO connection instance.
     */
    private PDO $pdo;

    /**
     * Creates a new database connection using environment variables.
     *
     * Expects the following environment variables to be set:
     * - DB_HOST: Database host (default: localhost)
     * - DB_PORT: Database port (default: 3306)
     * - DB_DATABASE: Database name (default: debugphp)
     * - DB_USERNAME: Database username (default: root)
     * - DB_PASSWORD: Database password (default: empty)
     */
    public function __construct()
    {
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $database = $_ENV['DB_DATABASE'] ?? 'debugphp';
        $username = $_ENV['DB_USERNAME'] ?? 'root';
        $password = $_ENV['DB_PASSWORD'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Returns the raw PDO instance.
     *
     * Used by setup.php to run schema creation queries.
     *
     * @return PDO The PDO connection.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    // ─── Sessions ────────────────────────────────────────────

    /**
     * Creates a new debug session.
     *
     * Generates a unique 32-character hex ID and sets the expiration
     * based on the SESSION_LIFETIME_HOURS environment variable.
     *
     * Also triggers cleanup of expired sessions.
     *
     * @return array{id: string, created_at: string, expires_at: string} The created session.
     */
    public function createSession(): array
    {
        $this->cleanupExpiredSessions();

        $id = bin2hex(random_bytes(16));
        $lifetimeHours = (int) ($_ENV['SESSION_LIFETIME_HOURS'] ?? 24);
        $createdAt = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + ($lifetimeHours * 3600));

        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (id, created_at, expires_at) VALUES (:id, :created_at, :expires_at)'
        );
        $stmt->execute([
            'id' => $id,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ]);

        return [
            'id' => $id,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Finds a session by its ID.
     *
     * Only returns sessions that have not yet expired.
     *
     * @param string $id The session ID to look up.
     *
     * @return array{id: string, created_at: string, expires_at: string}|null The session, or null if not found/expired.
     */
    public function findSession(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, created_at, expires_at FROM sessions WHERE id = :id AND expires_at > NOW()'
        );
        $stmt->execute(['id' => $id]);

        /** @var array{id: string, created_at: string, expires_at: string}|false */
        $result = $stmt->fetch();

        return $result !== false ? $result : null;
    }

    /**
     * Deletes a session and all its associated entries.
     *
     * Entries are automatically removed via the ON DELETE CASCADE
     * foreign key constraint.
     *
     * @param string $id The session ID to delete.
     *
     * @return bool True if the session was found and deleted.
     */
    public function deleteSession(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Removes all sessions that have passed their expiration time.
     *
     * Associated entries are automatically removed via CASCADE.
     * Called automatically when creating new sessions.
     */
    public function cleanupExpiredSessions(): void
    {
        $this->pdo->exec('DELETE FROM sessions WHERE expires_at <= NOW()');
    }

    // ─── Entries ─────────────────────────────────────────────

    /**
     * Inserts a new debug entry for the given session.
     *
     * @param string               $sessionId  The session this entry belongs to.
     * @param array<string, mixed> $data       The debug data as an associative array.
     * @param string               $label      The entry label (e.g. "SQL", "Error").
     * @param string               $color      The display color (e.g. "blue", "red").
     * @param string               $type       The entry type (e.g. "info", "sql", "error").
     * @param string               $originFile The source file where Debug::send() was called.
     * @param int                  $originLine The source line number.
     * @param float                $timestamp  The microtime timestamp from the client.
     *
     * @return int The ID of the newly created entry.
     */
    public function insertEntry(
        string $sessionId,
        mixed $data,
        string $label,
        string $color,
        string $type,
        string $originFile,
        int $originLine,
        float $timestamp,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO entries (session_id, data, label, color, type, origin_file, origin_line, timestamp, created_at)
             VALUES (:session_id, :data, :label, :color, :type, :origin_file, :origin_line, :timestamp, NOW())'
        );

        $stmt->execute([
            'session_id' => $sessionId,
            'data' => json_encode($data),
            'label' => $label,
            'color' => $color,
            'type' => $type,
            'origin_file' => $originFile,
            'origin_line' => $originLine,
            'timestamp' => $timestamp,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Retrieves all entries for a session that are newer than the given ID.
     *
     * Used by the SSE stream to only push new entries to the dashboard.
     *
     * @param string $sessionId The session to query.
     * @param int    $afterId   Only return entries with an ID greater than this.
     *
     * @return list<array{id: int, session_id: string, data: string, label: string, color: string, type: string, origin_file: string, origin_line: int, timestamp: float, created_at: string}>
     */
    public function getEntriesAfter(string $sessionId, int $afterId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, session_id, data, label, color, type, origin_file, origin_line, timestamp, created_at
             FROM entries
             WHERE session_id = :session_id AND id > :after_id
             ORDER BY id ASC'
        );
        $stmt->execute([
            'session_id' => $sessionId,
            'after_id' => $afterId,
        ]);

        /** @var list<array{id: int, session_id: string, data: string, label: string, color: string, type: string, origin_file: string, origin_line: int, timestamp: float, created_at: string}> */
        return $stmt->fetchAll();
    }

    /**
     * Returns the total number of entries for a session.
     *
     * @param string $sessionId The session to count entries for.
     *
     * @return int The entry count.
     */
    public function countEntries(string $sessionId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) as total FROM entries WHERE session_id = :session_id'
        );
        $stmt->execute(['session_id' => $sessionId]);

        /** @var array{total: int|string} */
        $result = $stmt->fetch();

        return (int) $result['total'];
    }

    /**
     * Deletes all entries for a session.
     *
     * The session itself remains active.
     *
     * @param string $sessionId The session to clear.
     *
     * @return int The number of deleted entries.
     */
    public function clearEntries(string $sessionId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM entries WHERE session_id = :session_id');
        $stmt->execute(['session_id' => $sessionId]);

        return $stmt->rowCount();
    }
}

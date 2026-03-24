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

use DebugPHP\Server\Config;
use PDO;

/**
 * Database access for debug sessions.
 *
 * A "repository" is responsible for all database operations of a
 * specific entity — in this case, sessions.
 *
 * This keeps a clear separation between what a session is (data)
 * and how it is persisted (database).
 */
final class SessionRepository
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
     * Creates a new debug session.
     *
     * Generates a random 32-character hex ID and sets the expiry time
     * based on the configured session lifetime.
     *
     * Also cleans up expired sessions before inserting (housekeeping).
     *
     * @return array{id: string, created_at: string, expires_at: string} The created session.
     */
    public function create(): array
    {
        $this->deleteExpired();

        $id        = bin2hex(random_bytes(16));
        $createdAt = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + (Config::sessionLifetimeHours() * 3600));

        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (id, created_at, expires_at) VALUES (:id, :created_at, :expires_at)'
        );
        $stmt->execute([
            'id'         => $id,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ]);

        return [
            'id'         => $id,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Finds a session by its ID.
     *
     * Only returns sessions that have not yet expired.
     *
     * @param string $id The session ID (32-character hex string).
     *
     * @return array{id: string, created_at: string, expires_at: string}|null The session or null if not found/expired.
     */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, created_at, expires_at
             FROM sessions
             WHERE id = :id AND expires_at > NOW()'
        );
        $stmt->execute(['id' => $id]);

        /** @var array{id: string, created_at: string, expires_at: string}|false */
        $result = $stmt->fetch();

        return $result !== false ? $result : null;
    }

    /**
     * Deletes a session by its ID.
     *
     * Associated entries are removed automatically via ON DELETE CASCADE.
     *
     * @param string $id The session ID to delete.
     *
     * @return bool True if the session was found and deleted.
     */
    public function delete(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Deletes all sessions that have passed their expiry time.
     *
     * Associated entries are removed automatically via CASCADE.
     * Called automatically whenever a new session is created.
     */
    public function deleteExpired(): void
    {
        $this->pdo->exec('DELETE FROM sessions WHERE expires_at <= NOW()');
    }
}

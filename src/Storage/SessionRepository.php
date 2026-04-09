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

use DebugPHP\Server\Config;

/**
 * File-based storage for debug sessions.
 *
 * Each session is stored as a single JSON file in the sessions directory.
 * Expired sessions are cleaned up automatically when a new session is created.
 */
final class SessionRepository
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
     * Creates a new debug session.
     *
     * Generates a random 32-character hex ID and sets the expiry time
     * based on the configured session lifetime.
     *
     * Also cleans up expired sessions before creating (housekeeping).
     *
     * @return array{id: string, created_at: string, expires_at: string} The created session.
     */
    public function create(): array
    {
        $this->deleteExpired();

        $id        = bin2hex(random_bytes(16));
        $createdAt = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + (Config::sessionLifetimeHours() * 3600));

        $session = [
            'id'         => $id,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ];

        file_put_contents(
            $this->storage->sessionFile($id),
            json_encode($session, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX,
        );

        // Ensure the entries directory for this session exists
        $entriesDir = $this->storage->entriesDir($id);
        if (!is_dir($entriesDir)) {
            mkdir($entriesDir, 0755, true);
        }

        return $session;
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
        $file = $this->storage->sessionFile($id);

        if (!is_file($file)) {
            return null;
        }

        $content = file_get_contents($file);

        if ($content === false) {
            return null;
        }

        /** @var array{id: string, created_at: string, expires_at: string} $session */
        $session = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        // Check expiry
        if (strtotime($session['expires_at']) <= time()) {
            $this->delete($id);

            return null;
        }

        return $session;
    }

    /**
     * Deletes a session by its ID.
     *
     * Also removes all associated entries and metrics files.
     *
     * @param string $id The session ID to delete.
     *
     * @return bool True if the session file existed and was deleted.
     */
    public function delete(string $id): bool
    {
        $file   = $this->storage->sessionFile($id);
        $existed = is_file($file);

        if ($existed) {
            unlink($file);
        }

        // Clean up entries directory
        $entriesDir = $this->storage->entriesDir($id);
        if (is_dir($entriesDir)) {
            $this->removeDirectory($entriesDir);
        }

        // Clean up metrics file
        $metricsFile = $this->storage->metricsFile($id);
        if (is_file($metricsFile)) {
            unlink($metricsFile);
        }

        // Clean up environment file
        $environmentFile = $this->storage->environmentFile($id);
        if (is_file($environmentFile)) {
            unlink($environmentFile);
        }

        return $existed;
    }

    /**
     * Deletes all sessions that have passed their expiry time.
     *
     * Associated entries and metrics are removed together with the session.
     * Called automatically whenever a new session is created.
     *
     * @return void
     */
    public function deleteExpired(): void
    {
        $files = glob($this->storage->sessionsDir() . '/*.json');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $content = file_get_contents($file);

            if ($content === false) {
                continue;
            }

            /** @var array{id: string, expires_at: string} $session */
            $session = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (strtotime($session['expires_at']) <= time()) {
                $this->delete($session['id']);
            }
        }
    }

    /**
     * Recursively removes a directory and all its contents.
     *
     * @param string $dir The absolute path to the directory.
     *
     * @return void
     */
    private function removeDirectory(string $dir): void
    {
        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}

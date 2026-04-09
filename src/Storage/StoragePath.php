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
 * Manages all file-storage paths and ensures directories exist.
 *
 * This is the single source of truth for where data lives on disk.
 * All repository classes receive this instance instead of a database connection.
 *
 * Directory structure:
 *   {basePath}/sessions/{id}.json
 *   {basePath}/entries/{id}/              (one dir per session)
 *   {basePath}/entries/{id}/.counter      (auto-increment counter)
 *   {basePath}/entries/{id}/{entryId}.json
 *   {basePath}/metrics/{id}.json
 */
final class StoragePath
{
    /**
     * Absolute path to the storage root directory.
     *
     * @var string
     */
    private readonly string $basePath;

    /**
     * Creates a new storage path manager.
     *
     * Reads the storage directory from the STORAGE_PATH environment variable
     * or falls back to a "data" directory in the project root.
     * Ensures all required subdirectories exist.
     */
    public function __construct()
    {
        $configured = isset($_ENV['STORAGE_PATH']) && is_string($_ENV['STORAGE_PATH'])
            ? $_ENV['STORAGE_PATH']
            : 'data';

        // Resolve relative paths against the project root
        if (!str_starts_with($configured, '/')) {
            $configured = dirname(__DIR__, 2) . '/' . $configured;
        }

        $this->basePath = rtrim($configured, '/');

        $this->ensureDirectories();
    }

    /**
     * Returns the absolute path to the storage root.
     *
     * @return string
     */
    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Returns the absolute path to the sessions directory.
     *
     * @return string
     */
    public function sessionsDir(): string
    {
        return $this->basePath . '/sessions';
    }

    /**
     * Returns the absolute path to the session file.
     *
     * @param string $sessionId The 32-character hex session ID.
     *
     * @return string
     */
    public function sessionFile(string $sessionId): string
    {
        return $this->sessionsDir() . '/' . $sessionId . '.json';
    }

    /**
     * Returns the absolute path to the entries directory for a given session.
     *
     * @param string $sessionId The 32-character hex session ID.
     *
     * @return string
     */
    public function entriesDir(string $sessionId): string
    {
        return $this->basePath . '/entries/' . $sessionId;
    }

    /**
     * Returns the absolute path to the auto-increment counter file for a session.
     *
     * @param string $sessionId The 32-character hex session ID.
     *
     * @return string
     */
    public function counterFile(string $sessionId): string
    {
        return $this->entriesDir($sessionId) . '/.counter';
    }

    /**
     * Returns the absolute path to a single entry file.
     *
     * @param string $sessionId The session ID.
     * @param int    $entryId   The numeric entry ID.
     *
     * @return string
     */
    public function entryFile(string $sessionId, int $entryId): string
    {
        return $this->entriesDir($sessionId) . '/' . str_pad((string) $entryId, 8, '0', STR_PAD_LEFT) . '.json';
    }

    /**
     * Returns the absolute path to the metrics file for a given session.
     *
     * @param string $sessionId The 32-character hex session ID.
     *
     * @return string
     */
    public function metricsFile(string $sessionId): string
    {
        return $this->basePath . '/metrics/' . $sessionId . '.json';
    }

    /**
     * Returns the absolute path to the metrics directory.
     *
     * @return string
     */
    public function metricsDir(): string
    {
        return $this->basePath . '/metrics';
    }

    /**
     * Ensures all required subdirectories exist.
     *
     * Called automatically in the constructor. Safe to call multiple times.
     *
     * @return void
     */
    private function ensureDirectories(): void
    {
        $dirs = [
            $this->basePath,
            $this->sessionsDir(),
            $this->basePath . '/entries',
            $this->metricsDir(),
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
}

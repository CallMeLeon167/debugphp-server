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
 * File-based storage for environment data.
 *
 * Each session has at most one environment file that stores static runtime
 * information about the connected PHP application (version, SAPI, OS, etc.).
 *
 * Written once per Debug::init() call on the client side. Each init() overwrites
 * the previous data entirely — no merging, no history.
 *
 * The SSE stream reads this file once on connect and pushes it as an
 * "environment" event to the dashboard.
 */
final class EnvironmentRepository
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
     * Saves environment data for a session.
     *
     * Overwrites any previously stored environment for this session.
     *
     * @param string                $sessionId The session this environment belongs to.
     * @param array<string, mixed> $data      The environment key-value pairs.
     *
     * @return void
     */
    public function save(string $sessionId, array $data): void
    {
        file_put_contents(
            $this->storage->environmentFile($sessionId),
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            LOCK_EX,
        );
    }

    /**
     * Reads the environment data for a session.
     *
     * Returns null if no environment has been stored yet.
     *
     * @param string $sessionId The session ID.
     *
     * @return array<string, string>|null The environment data or null if not found.
     */
    public function find(string $sessionId): ?array
    {
        $file = $this->storage->environmentFile($sessionId);

        if (!is_file($file)) {
            return null;
        }

        $content = file_get_contents($file);

        if ($content === false || $content === '') {
            return null;
        }

        /** @var array<string, string> */
        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Deletes the environment file for a session.
     *
     * Called automatically when a session is deleted via cascade cleanup.
     *
     * @param string $sessionId The session ID.
     *
     * @return void
     */
    public function delete(string $sessionId): void
    {
        $file = $this->storage->environmentFile($sessionId);

        if (is_file($file)) {
            unlink($file);
        }
    }
}

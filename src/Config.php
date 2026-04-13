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

namespace DebugPHP\Server;

/**
 * Application configuration.
 *
 * Initialized once in Application::__construct() via Config::init().
 * After that, values are accessible statically from anywhere:
 *
 *   Config::baseUrl()
 *   Config::sessionLifetimeHours()
 *   Config::version()
 *   Config::storagePath()
 */
final class Config
{
    /** @var string */
    private string $version = '0.2.0';

    /** @var self */
    private static self $instance;

    /**
     * The root URL path of the application (no trailing slash).
     *
     * Root install:  ""
     * Subdirectory:  "/debugphp"
     *
     * @var string
     */
    private string $baseUrl;

    /** @var int */
    private int $sessionLifetimeHours;

    /** @var string */
    private string $storagePath;

    private function __construct()
    {
        $sessionLifetime = isset($_ENV['SESSION_LIFETIME_HOURS']) && is_numeric($_ENV['SESSION_LIFETIME_HOURS'])
            ? (int) $_ENV['SESSION_LIFETIME_HOURS']
            : 24;

        $this->sessionLifetimeHours = $sessionLifetime;

        $this->storagePath = isset($_ENV['STORAGE_PATH']) && is_string($_ENV['STORAGE_PATH'])
            ? $_ENV['STORAGE_PATH']
            : 'data';

        $scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
            ? $_SERVER['SCRIPT_NAME']
            : '/index.php';

        $dir = dirname($scriptName);

        $this->baseUrl = $dir === '/' || $dir === '\\' ? '' : $dir;
    }

    /**
     * Initializes the config. Must be called once before any getter is used.
     * Called automatically by Application::__construct().
     *
     * @return void
     */
    public static function init(): void
    {
        self::$instance = new self();
    }

    /**
     * Returns the root URL path of the application (no trailing slash).
     *
     * @return string
     */
    public static function baseUrl(): string
    {
        return self::instance()->baseUrl;
    }

    /**
     * Returns the session lifetime in hours.
     *
     * @return int
     */
    public static function sessionLifetimeHours(): int
    {
        return self::instance()->sessionLifetimeHours;
    }

    /**
     * Returns the configured storage path.
     *
     * @return string
     */
    public static function storagePath(): string
    {
        return self::instance()->storagePath;
    }

    /**
     * Returns the application version.
     *
     * @return string
     */
    public static function version(): string
    {
        return self::instance()->version;
    }

    /**
     * Returns the singleton instance. Throws if init() was not called.
     *
     * @return self
     */
    private static function instance(): self
    {
        if (!isset(self::$instance)) {
            throw new \LogicException('Config::init() must be called before accessing config values.');
        }

        return self::$instance;
    }
}

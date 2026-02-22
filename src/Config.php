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
 *   Config::siteUrl()
 *   Config::appName()
 *   Config::sessionLifetimeHours()
 */
final class Config
{
    private static self $instance;

    private string $siteUrl;
    private string $appName;
    private int $sessionLifetimeHours;

    private function __construct()
    {
        $siteUrl = isset($_ENV['SITE_URL']) && is_string($_ENV['SITE_URL']) ? $_ENV['SITE_URL'] : 'http://localhost';
        $appName = isset($_ENV['APP_NAME']) && is_string($_ENV['APP_NAME']) ? $_ENV['APP_NAME'] : 'DebugPHP';
        $sessionLifetime = isset($_ENV['SESSION_LIFETIME_HOURS']) && is_numeric($_ENV['SESSION_LIFETIME_HOURS'])
            ? (int) $_ENV['SESSION_LIFETIME_HOURS']
            : 24;

        $this->siteUrl = rtrim($siteUrl, '/');
        $this->appName = $appName;
        $this->sessionLifetimeHours = $sessionLifetime;
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
     * Returns the base URL of the application (no trailing slash).
     *
     * @return string
     */
    public static function siteUrl(): string
    {
        return self::instance()->siteUrl;
    }

    /**
     * Returns the application name.
     *
     * @return string
     */
    public static function appName(): string
    {
        return self::instance()->appName;
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

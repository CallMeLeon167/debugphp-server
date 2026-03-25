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
 *   Config::basePath()
 *   Config::appName()
 *   Config::sessionLifetimeHours()
 */
final class Config
{
    /** @var self */
    private static self $instance;

    /** @var string */
    private string $siteUrl;

    /** @var string */
    private string $basePath;

    /** @var string */
    private string $appName;

    /** @var int */
    private int $sessionLifetimeHours;

    private function __construct()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'];

        $siteUrl = isset($_ENV['SITE_URL']) && is_string($_ENV['SITE_URL']) ? $_ENV['SITE_URL'] : $protocol . '://' . $host;
        $appName = isset($_ENV['APP_NAME']) && is_string($_ENV['APP_NAME']) ? $_ENV['APP_NAME'] : 'DebugPHP';
        $sessionLifetime = isset($_ENV['SESSION_LIFETIME_HOURS']) && is_numeric($_ENV['SESSION_LIFETIME_HOURS'])
            ? (int) $_ENV['SESSION_LIFETIME_HOURS']
            : 24;

        $this->siteUrl = rtrim($siteUrl, '/');
        $this->appName = $appName;
        $this->sessionLifetimeHours = $sessionLifetime;

        $parsed = parse_url($this->siteUrl, PHP_URL_PATH);
        $this->basePath = is_string($parsed) ? rtrim($parsed, '/') : '';
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
     * Returns the path prefix for subdirectory installations (no trailing slash).
     *
     * Root install:  ""
     * Subdirectory:  "/debugphp"
     *
     * @return string
     */
    public static function basePath(): string
    {
        return self::instance()->basePath;
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

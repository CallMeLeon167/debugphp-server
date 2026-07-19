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

// ─── Autoloader ──────────────────────────────────────────
$autoloader = __DIR__ . '/vendor/autoload.php';

if (!file_exists($autoloader)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = rtrim(dirname($requestPath), '/');
    header("Location: {$base}/setup/");
    exit;
}

require_once $autoloader;

use DebugPHP\Server\Application;
use DebugPHP\Server\Environment;

// ─── Check for .env ──────────────────────────────────────
$envPath = __DIR__ . '/.env';
$hasExternalConfig = Environment::get('STORAGE_PATH') !== null;

if (!file_exists($envPath) && !$hasExternalConfig) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = rtrim(dirname($requestPath), '/');
    header("Location: {$base}/setup/");
    exit;
}

// ─── Load environment variables ──────────────────────────
if (file_exists($envPath)) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// ─── Run the application ─────────────────────────────────
(new Application())->run();

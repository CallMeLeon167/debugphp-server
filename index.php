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
    header('Location: /setup/');
    exit;
}

require_once $autoloader;

// ─── Check for .env ──────────────────────────────────────
if (!file_exists(__DIR__ . '/.env')) {
    header('Location: /setup/');
    exit;
}

// ─── Load environment variables ──────────────────────────
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// ─── Run the application ─────────────────────────────────
use DebugPHP\Server\Application;

(new Application())->run();

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
$autloader = __DIR__ . '/vendor/autoload.php';
if (file_exists($autloader)) {
    require_once $autloader;
} else {
    header('Location: /setup/');
    exit;
}

// ─── Environment ─────────────────────────────────────────
// Redirect to setup wizard if no .env file exists
if (!file_exists(__DIR__ . '/.env')) {
    header('Location: /setup/');
    exit;
}

// ─── .env ───────────────────────────────────────────
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// ─── Bootstrap ───────────────────────────────────────────
use DebugPHP\Server\Controller;
use DebugPHP\Server\Database;
use DebugPHP\Server\Stream;

$db = new Database();
$controller = new Controller($db);
$stream = new Stream($db);

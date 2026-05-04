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
 *
 * ─────────────────────────────────────────────────────────
 * SETUP WIZARD — Entry Point
 *
 * Handles routing only:
 *   POST  → delegates to SetupManager, returns JSON
 *   GET   → renders template.php
 *
 * To re-run the setup wizard after initial configuration,
 * set ALLOW_SETUP to true below.
 * ─────────────────────────────────────────────────────────
 */

declare(strict_types=1);

use DebugPHP\Server\Config;
use DebugPHP\Server\Environment;

/**
 * Set to true to unlock the setup wizard after initial configuration.
 */
const ALLOW_SETUP = false;

// ─── Autoloader ──────────────────────────────────────────
$autoloader = __DIR__ . '/../vendor/autoload.php';

if (!file_exists($autoloader)) {
    require_once __DIR__ . '/template.php';
    renderComposerNotice();
    exit;
}

require_once $autoloader;

Config::init();

require_once __DIR__ . '/SetupManager.php';
require_once __DIR__ . '/template.php';

// ─── Bootstrap SetupManager ──────────────────────────────
$setup = new SetupManager();

/** @var array<string, string> $loadedEnv */
$loadedEnv = Environment::only(['STORAGE_PATH', 'SESSION_LIFETIME_HOURS', 'SESSION_ID']);

if ($setup->envExists()) {
    $dotenv    = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    /** @var array<string, string|null> $rawEnv */
    $rawEnv = $dotenv->load();

    foreach ($rawEnv as $key => $value) {
        if (is_string($value)) {
            $loadedEnv[$key] = $value;
        }
    }
}

$isConfigured = $setup->isConfigured($loadedEnv);

// ─── Block if already configured ─────────────────────────
// @phpstan-ignore identical.alwaysTrue
if ($isConfigured && ALLOW_SETUP === false) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Setup is locked. Set ALLOW_SETUP to true in setup/index.php to reconfigure.',
        ]);
        exit;
    }

    renderConfiguredScreen();
    exit;
}

// ─── POST: Handle AJAX actions ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $raw  = file_get_contents('php://input');
    $body = is_string($raw) ? json_decode($raw, true) : null;

    if (!is_array($body) || !isset($body['action'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    /** @var array<string, mixed> $body */
    $result = match ($body['action']) {
        'test'     => $setup->testStorage($body),
        'save_env' => $setup->saveEnv($body),
        'setup'    => $setup->createDirectories($body),
        default    => ['success' => false, 'message' => 'Unknown action.'],
    };

    echo json_encode($result);
    exit;
}

// ─── GET: Render the wizard ───────────────────────────────
$formValues = $setup->loadFormValues($loadedEnv);
renderWizard($setup->envExists(), $formValues);

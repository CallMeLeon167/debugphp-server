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

use DebugPHP\Server\Config;

/** @var string $setupBase */
$setupBase = (string) Config::baseUrl();

/** @var string $appBase */
$appBase = dirname($setupBase);
$appBase = $appBase === '/' || $appBase === '\\' ? '' : $appBase;

/**
 * Escapes a string for safe HTML output.
 *
 * @param string $value The raw value.
 *
 * @return string The escaped value.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Renders the <head> tag used by all wizard screens.
 *
 * @return void
 */
function renderHead(): void
{
    /** @var string $appBase */
    /** @var string $setupBase */
    global $appBase, $setupBase;
?>

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>DebugPHP — Setup</title>
        <link href="<?= e($appBase) ?>/assets/fonts/fonts.css" rel="stylesheet">
        <link rel="stylesheet" href="<?= e($setupBase) ?>/assets/styles.css">
        <script>
            window.__DEBUGPHP_BASE = <?= json_encode($setupBase, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) ?>;
        </script>
    </head>
<?php
}

/**
 * Renders the "composer install required" screen.
 *
 * @return void
 */
function renderComposerNotice(): void
{
    /** @var string $setupBase */
    global $setupBase;
?>
    <!DOCTYPE html>
    <html lang="en">

    <?php renderHead(); ?>

    <body>
        <div class="card configured-card">
            <div class="icon">&#9888;</div>
            <h2>DebugPHP not ready yet</h2>
            <p>To setup DebugPHP, please run</p>
            <div class="hint space-m"><code>composer install</code></div>
            <p>in the project root. This will install all necessary dependencies.</p>
            <br>
            <a href="<?= e($setupBase) ?>/setup/" class="btn btn-primary">Refresh Page</a>
        </div>
    </body>

    </html>
<?php
}

/**
 * Renders the "already configured" screen.
 *
 * @return void
 */
function renderConfiguredScreen(): void
{
    /** @var string $appBase */
    global $appBase;
?>
    <!DOCTYPE html>
    <html lang="en">

    <?php renderHead(); ?>

    <body>
        <div class="card configured-card">
            <div class="icon">&#10003;</div>
            <h2>DebugPHP is configured and ready!</h2>
            <p>Your server is set up and the storage directories exist.</p>
            <p>Everything is working — you can start debugging.</p>
            <div class="hint">
                Need to reconfigure? Set<br>
                <code>ALLOW_SETUP</code> to <code>true</code> in <code>setup/index.php</code>
            </div>
            <a href="<?= e($appBase) ?>/" class="btn-open-dashboard">Open Dashboard &rarr;</a>
        </div>
    </body>

    </html>
<?php
}

/**
 * Renders the full setup wizard.
 *
 * @param bool                  $envExists  Whether a .env file already exists.
 * @param array<string, string> $values     Current form values (from .env or defaults).
 *
 * @return void
 */
function renderWizard(bool $envExists, array $values): void
{
    /** @var string $appBase */
    /** @var string $setupBase */
    global $appBase, $setupBase;
?>
    <!DOCTYPE html>
    <html lang="en">

    <?php renderHead(); ?>

    <body>

        <div class="wizard">
            <div class="wizard-header">
                <div class="wizard-logo">DebugPHP</div>
                <div class="wizard-subtitle">Setup Wizard</div>
            </div>

            <!-- Step indicator -->
            <div class="steps-indicator">
                <div class="step-dot active" id="stepDot1">1</div>
                <div class="step-line" id="stepLine1"></div>
                <div class="step-dot" id="stepDot2">&#10003;</div>
            </div>

            <!-- Step 1: Configuration -->
            <div id="step1" class="card">
                <div class="card-title">Configuration</div>
                <div class="card-desc">
                    <p>Enter your storage settings below.</p>
                    <?php if ($envExists): ?>
                        <span class="env-badge exists">&#10003; .env file found — values loaded</span>
                    <?php else: ?>
                        <span class="env-badge missing">&#9888; No .env file — please fill in your settings</span>
                    <?php endif; ?>
                </div>

                <!-- Storage -->
                <div class="section-label">Storage</div>
                <div class="form-row full">
                    <div class="form-group">
                        <label class="form-label">Storage Path</label>
                        <input class="form-input" id="storagePath" type="text"
                            value="<?= e($values['storage_path']) ?>" placeholder="data">
                    </div>
                </div>

                <div class="form-divider"></div>

                <!-- Session ID Mode -->
                <div class="section-label">Session ID Mode</div>
                <div class="form-row full">
                    <div class="form-group">
                        <label class="form-label">
                            <input type="radio" name="sessionMode" value="random"
                                <?= ($values['session_mode'] ?? 'random') === 'random' ? 'checked' : '' ?>
                                onchange="toggleSessionIdInput()">
                            Random (new ID per dashboard load)
                        </label>
                        <label class="form-label" style="margin-top: 8px;">
                            <input type="radio" name="sessionMode" value="static"
                                <?= ($values['session_mode'] ?? 'random') === 'static' ? 'checked' : '' ?>
                                onchange="toggleSessionIdInput()">
                            Static (fixed Session ID)
                        </label>
                    </div>
                </div>

                <div class="form-row full" id="staticSessionIdRow"
                    style="display: <?= ($values['session_mode'] ?? 'random') === 'static' ? 'block' : 'none' ?>">
                    <div class="form-group">
                        <label class="form-label">Static Session ID</label>
                        <input class="form-input" id="sessionId" type="text"
                            value="<?= e($values['session_id']) ?>"
                            placeholder="e.g. my-debug-session">
                        <small style="color: var(--text-muted); font-size: 13px;">
                            Leave empty to auto-generate a static ID on first request
                        </small>
                    </div>
                </div>

                <!-- Session -->
                <div class="section-label">Session</div>
                <div class="form-row full">
                    <div class="form-group">
                        <label class="form-label">Session Lifetime (hours)</label>
                        <input class="form-input" id="sessionLifetime" type="number"
                            value="<?= e($values['session_lifetime']) ?>" placeholder="24" min="1"
                            style="max-width:200px;">
                    </div>
                </div>

                <div id="alertTest" class="alert"></div>

                <div class="btn-row">
                    <button class="btn btn-secondary" id="btnTest" onclick="testStorage()">
                        <span class="btn-text">&#128268; Test Storage</span>
                        <span class="spinner"></span>
                    </button>
                    <button class="btn btn-primary" id="btnSetup" onclick="runSetup()" disabled>
                        <span class="btn-text">Save &amp; Setup &rarr;</span>
                        <span class="spinner"></span>
                    </button>
                </div>
            </div>

            <!-- Step 2: Done -->
            <div id="step2" class="card" style="display:none;">
                <div class="success-screen">
                    <div class="success-icon">&#9889;</div>
                    <h2>You're all set!</h2>
                    <p>Your DebugPHP server is configured and ready to go.</p>
                    <p>Open the dashboard and create your first debug session.</p>
                    <a href="<?= e($appBase) ?>/" class="btn-open-dashboard">Open Dashboard &rarr;</a>
                </div>
            </div>

        </div>

        <script src="<?= e($setupBase) ?>/assets/script.js"></script>
    </body>

    </html>
<?php
}

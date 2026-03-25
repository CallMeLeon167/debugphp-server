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

/**
 * Escapes a value for safe output inside an HTML attribute or text node.
 * 
 * @param string $value The value to escape.
 * @return string The escaped value.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Renders the <head> section of the HTML pages.
 * 
 * @return void
 */
function renderHead(): void
{
?>

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>DebugPHP — Setup</title>
        <link href="<?= Config::basePath() ?>/assets/fonts/fonts.css" rel="stylesheet">
        <link rel="stylesheet" href="<?= Config::basePath() ?>/assets/styles.css">
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
            <a href="<?= Config::siteUrl() ?>/setup/" class="btn btn-primary">Refresh Page</a>
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
?>
    <!DOCTYPE html>
    <html lang="en">

    <?php renderHead(); ?>

    <body>
        <div class="card configured-card">
            <div class="icon">&#10003;</div>
            <h2>DebugPHP is configured and ready!</h2>
            <p>Your server is set up and the database tables exist.</p>
            <p>Everything is working — you can start debugging.</p>
            <div class="hint">
                Need to reconfigure? Set<br>
                <code>ALLOW_SETUP</code> to <code>true</code> in <code>setup/index.php</code>
            </div>
            <a href="<?= Config::siteUrl() ?>" class="btn-open-dashboard">Open Dashboard &rarr;</a>
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
 * @return void
 */
function renderWizard(bool $envExists, array $values): void
{
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
                <div class="step-dot" id="stepDot2">2</div>
                <div class="step-line" id="stepLine2"></div>
                <div class="step-dot" id="stepDot3">&#10003;</div>
            </div>

            <!-- Step 1: Configuration -->
            <div id="step1" class="card">
                <div class="card-title">Configuration</div>
                <div class="card-desc">
                    <p>Enter your application settings and database credentials below.</p>
                    <?php if ($envExists): ?>
                        <span class="env-badge exists">&#10003; .env file found — values loaded</span>
                    <?php else: ?>
                        <span class="env-badge missing">&#9888; No .env file — please fill in your settings</span>
                    <?php endif; ?>
                </div>

                <!-- Application -->
                <div class="section-label">Application</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Site URL</label>
                        <input class="form-input" id="siteUrl" type="url"
                            value="<?= e($values['site_url']) ?>" placeholder="http://localhost">
                    </div>
                    <div class="form-group">
                        <label class="form-label">App Name</label>
                        <input class="form-input" id="appName" type="text"
                            value="<?= e($values['app_name']) ?>" placeholder="DebugPHP">
                    </div>
                </div>

                <div class="form-divider"></div>

                <!-- Database -->
                <div class="section-label">Database</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Host</label>
                        <input class="form-input" id="dbHost" type="text"
                            value="<?= e($values['db_host']) ?>" placeholder="localhost">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Port</label>
                        <input class="form-input" id="dbPort" type="text"
                            value="<?= e($values['db_port']) ?>" placeholder="3306">
                    </div>
                </div>
                <div class="form-row full">
                    <div class="form-group">
                        <label class="form-label">Database Name</label>
                        <input class="form-input" id="dbDatabase" type="text"
                            value="<?= e($values['db_database']) ?>" placeholder="debugphp">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input class="form-input" id="dbUsername" type="text"
                            value="<?= e($values['db_username']) ?>" placeholder="root">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input class="form-input" id="dbPassword" type="password"
                            value="<?= e($values['db_password']) ?>" placeholder="••••••••">
                    </div>
                </div>

                <div class="form-divider"></div>

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
                    <button class="btn btn-secondary" id="btnTest" onclick="testConnection()">
                        <span class="btn-text">&#128268; Test Connection</span>
                        <span class="spinner"></span>
                    </button>
                    <button class="btn btn-primary" id="btnNext" onclick="saveEnvAndNext()" disabled>
                        <span class="btn-text">Save &amp; Continue &rarr;</span>
                        <span class="spinner"></span>
                    </button>
                </div>
            </div>

            <!-- Step 2: Create Tables -->
            <div id="step2" class="card" style="display:none;">
                <div class="card-title">Create Database Tables</div>
                <div class="card-desc">
                    Your .env file has been saved. Click the button below to create the required tables.
                </div>
                <div class="code-box">
                    <span class="kw">CREATE TABLE</span> <span class="val">sessions</span> (...)<br>
                    <span class="kw">CREATE TABLE</span> <span class="val">entries</span> (...) <br>
                    <span class="kw">CREATE TABLE</span> <span class="val">metrics</span> (...)
                </div>
                <div id="alertSetup" class="alert"></div>
                <div class="btn-row">
                    <button class="btn btn-secondary" onclick="goBack()">&larr; Back</button>
                    <button class="btn btn-primary" id="btnSetup" onclick="runSetup()">
                        <span class="btn-text">Run Setup</span>
                        <span class="spinner"></span>
                    </button>
                </div>
            </div>

            <!-- Step 3: Done -->
            <div id="step3" class="card" style="display:none;">
                <div class="success-screen">
                    <div class="success-icon">&#9889;</div>
                    <h2>You're all set!</h2>
                    <p>Your DebugPHP server is configured and ready to go.</p>
                    <p>Open the dashboard and create your first debug session.</p>
                    <a href="<?= Config::siteUrl() ?>" class="btn-open-dashboard">Open Dashboard &rarr;</a>
                </div>
            </div>

        </div>

        <script src="<?= Config::siteUrl() ?>/setup/assets/script.js"></script>
    </body>

    </html>
<?php
}

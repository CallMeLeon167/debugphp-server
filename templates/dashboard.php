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

use DebugPHP\Server\Config;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DebugPHP — Dashboard</title>
    <link href="<?= Config::baseUrl() ?>/assets/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= Config::baseUrl() ?>/assets/css/dashboard.css">
    <script>
        window.__DEBUGPHP_BASE = <?= json_encode(Config::baseUrl(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) ?>;
    </script>
</head>

<body>

    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <a href="https://debugphp.dev" class="topbar-logo" target="_blank">
                Debug<span class="php-highlight">PHP</span>
            </a>
            <div class="topbar-divider"></div>
            <div class="session-info" id="sessionInfo">
                <div class="session-status"></div>
                <span class="session-id">Session: <strong id="sessionId">connecting...</strong></span>
            </div>
            <div class="topbar-divider"></div>
            <div class="topbar-metrics" id="topbarMetrics"></div>
        </div>
        <div class="topbar-right">

            <!-- Editor Picker -->
            <div class="editor-picker" id="editorPicker">
                <button class="topbar-btn editor-picker-btn" id="editorPickerBtn" title="Open files in editor">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-code">
                        <polyline points="16 18 22 12 16 6"></polyline>
                        <polyline points="8 6 2 12 8 18"></polyline>
                    </svg>
                    <span id="editorPickerLabel">Editor</span>
                </button>
                <div class="editor-dropdown" id="editorDropdown" style="display:none;">
                    <div class="editor-dropdown-header">Open files in</div>
                    <div id="editorOptionsList"></div>
                </div>
            </div>

            <button class="topbar-btn" id="autoClearBtn" title="Auto-clear entries on each new PHP request">
                &#8635; Auto-clear
            </button>
            <button class="topbar-btn" id="pauseBtn">&#10074;&#10074; Pause</button>
            <button class="topbar-btn danger" id="clearBtn">&#128465; Clear</button>
            <button class="topbar-btn new-session" id="newSessionBtn">+ New Session</button>
        </div>
    </div>

    <!-- Main Layout -->
    <div class="main-layout">

        <!-- Sidebar -->
        <aside class="sidebar">

            <!-- Type Filter (dynamic — only "All" is static, types appear as entries arrive) -->
            <div class="sidebar-header">
                <div class="sidebar-title">
                    Type
                </div>
                <div class="filter-chips" id="typeFilterChips">
                    <span class="chip active" data-filter="all">All</span>
                </div>
            </div>

            <!-- Label Filter (dynamic, hidden until labels arrive) -->
            <div class="sidebar-header sidebar-label-section" id="labelFilterSection" style="display:none;">
                <div class="sidebar-title">
                    Label
                </div>
                <div class="filter-chips" id="labelFilterChips"></div>
            </div>

            <div class="sidebar-search">
                <input class="search-input" id="searchInput" type="text" placeholder="Search entries...">
            </div>
            <div class="sidebar-stats">
                <div class="sidebar-title">Statistics</div>
                <div class="stat-row">
                    <span class="stat-label">Entries</span>
                    <span class="stat-value" id="statTotal" style="color:var(--text-primary)">0</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Errors</span>
                    <span class="stat-value" id="statErrors" style="color:var(--red)">0</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">SQL Queries</span>
                    <span class="stat-value" id="statSql" style="color:var(--purple)">0</span>
                </div>
            </div>
            <div class="sidebar-footer">
                <div class="session-timer" id="sessionTimer">Connecting...</div>
                <div class="version-info">DebugPHP-Server v<?= Config::version() ?></div>
            </div>
        </aside>

        <!-- Debug Panel -->
        <div class="debug-panel">
            <div class="debug-toolbar" id="debugToolbar">
            </div>

            <div class="debug-log" id="debugLog">
                <!-- Empty State -->
                <div class="empty-state" id="emptyState">
                    <div class="icon">&#9889;</div>
                    <h3>Waiting for debug data...</h3>
                    <p>
                        Connect your PHP app to this session<br>
                        and start sending with <code style="color:var(--accent)">Debug::send()</code>
                    </p>
                </div>
            </div>
        </div>

        <!-- Detail Panel -->
        <aside class="detail-panel hidden" id="detailPanel">
            <div class="detail-resize-handle" id="detailResizeHandle"></div>
            <div class="detail-header">
                <h3>Entry Details</h3>
                <button class="detail-close" id="detailClose">&times;</button>
            </div>
            <div class="detail-body" id="detailBody"></div>
        </aside>

    </div>

    <script src="<?= Config::baseUrl() ?>/assets/js/dashboard.js"></script>
</body>

</html>
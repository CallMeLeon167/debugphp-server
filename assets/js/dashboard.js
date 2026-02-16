/**
 * DebugPHP Dashboard
 *
 * (c) Leon Schmidt — MIT License
 * https://github.com/CallMeLeon167/debugphp-server
 */

(function () {
    'use strict';

    // ─── State ──────────────────────────────────────────────
    let sessionId = null;
    let eventSource = null;
    let paused = false;
    let autoScroll = true;
    let activeFilter = 'all';
    let searchQuery = '';
    let selectedEntryId = null;
    let stats = { total: 0, errors: 0, sql: 0 };

    // ─── DOM Elements ───────────────────────────────────────
    const dom = {
        sessionId: document.getElementById('sessionId'),
        sessionInfo: document.getElementById('sessionInfo'),
        debugLog: document.getElementById('debugLog'),
        emptyState: document.getElementById('emptyState'),
        visibleCount: document.getElementById('visibleCount'),
        autoScrollToggle: document.getElementById('autoScrollToggle'),
        searchInput: document.getElementById('searchInput'),
        pauseBtn: document.getElementById('pauseBtn'),
        detailPanel: document.getElementById('detailPanel'),
        detailBody: document.getElementById('detailBody'),
        statTotal: document.getElementById('statTotal'),
        statErrors: document.getElementById('statErrors'),
        statSql: document.getElementById('statSql'),
        sessionTimer: document.getElementById('sessionTimer'),
    };

    // ─── Color Mapping ──────────────────────────────────────
    const colorMap = {
        red: 'c-red',
        blue: 'c-blue',
        green: 'c-green',
        orange: 'c-orange',
        purple: 'c-purple',
        gray: 'c-gray',
    };

    const labelMap = {
        info: 'label-info',
        sql: 'label-sql',
        error: 'label-error',
        timer: 'label-timer',
        success: 'label-success',
        cache: 'label-cache',
        table: 'label-table',
    };

    // ─── API ────────────────────────────────────────────────

    /**
     * Creates a new debug session via the API.
     *
     * @returns {Promise<Object>} The session data.
     */
    async function createSession() {
        const response = await fetch('/api/session', { method: 'POST' });
        return response.json();
    }

    /**
     * Sends a clear command for the current session.
     */
    async function clearSession() {
        if (!sessionId) return;

        await fetch('/api/clear', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session: sessionId }),
        });
    }

    // ─── SSE Connection ─────────────────────────────────────

    /**
     * Connects to the SSE stream for the given session.
     *
     * @param {string} id - The session ID.
     */
    function connectStream(id) {
        if (eventSource) {
            eventSource.close();
        }

        eventSource = new EventSource('/api/stream/' + id);

        eventSource.addEventListener('connected', function () {
            dom.sessionInfo.classList.remove('disconnected');
        });

        eventSource.addEventListener('entry', function (e) {
            if (paused) return;

            var data = JSON.parse(e.data);
            addEntry(data);
        });

        eventSource.addEventListener('expired', function () {
            dom.sessionInfo.classList.add('disconnected');
        });

        eventSource.addEventListener('reconnect', function () {
            // EventSource will auto-reconnect
        });

        eventSource.onerror = function () {
            dom.sessionInfo.classList.add('disconnected');
        };
    }

    // ─── Entry Rendering ────────────────────────────────────

    /**
     * Adds a debug entry to the log.
     *
     * @param {Object} entry - The entry data from the server.
     */
    function addEntry(entry) {
        // Hide empty state
        dom.emptyState.style.display = 'none';

        var el = document.createElement('div');
        el.className = 'log-entry';
        el.dataset.type = entry.type || 'info';
        el.dataset.id = String(entry.id);

        var colorClass = colorMap[entry.color] || 'c-gray';
        var labelClass = labelMap[entry.type] || 'label-info';
        var time = formatTimestamp(entry.timestamp);
        var label = entry.label || entry.type || 'info';
        var origin = '';

        if (entry.origin && entry.origin.file) {
            origin = entry.origin.file + (entry.origin.line ? ':' + entry.origin.line : '');
        }

        var content = formatData(entry.data);

        el.innerHTML =
            '<div class="entry-color ' + colorClass + '"></div>' +
            '<div class="entry-body">' +
            '<div class="entry-header">' +
            '<span class="entry-label ' + labelClass + '">' + escapeHtml(label) + '</span>' +
            '<span class="entry-time">' + time + '</span>' +
            (origin ? '<span class="entry-origin">' + escapeHtml(origin) + '</span>' : '') +
            '</div>' +
            '<div class="entry-content">' + content + '</div>' +
            '</div>';

        el.addEventListener('click', function () {
            selectEntry(entry, el);
        });

        dom.debugLog.appendChild(el);

        // Apply filters
        if (activeFilter !== 'all' && entry.type !== activeFilter) {
            el.style.display = 'none';
        }

        if (searchQuery && !el.textContent.toLowerCase().includes(searchQuery)) {
            el.style.display = 'none';
        }

        // Update stats
        stats.total++;
        if (entry.type === 'error') stats.errors++;
        if (entry.type === 'sql') stats.sql++;
        updateStats();

        // Auto-scroll
        if (autoScroll) {
            dom.debugLog.scrollTop = dom.debugLog.scrollHeight;
        }
    }

    /**
     * Formats debug data for display in the log.
     *
     * @param {*} data - The debug data.
     * @returns {string} HTML string.
     */
    function formatData(data) {
        if (data === null || data === undefined) {
            return '<span style="color:var(--text-muted)">null</span>';
        }

        if (typeof data === 'string') {
            return '<span style="color:var(--accent)">"' + escapeHtml(data) + '"</span>';
        }

        if (typeof data === 'number' || typeof data === 'boolean') {
            return '<span style="color:var(--orange)">' + String(data) + '</span>';
        }

        if (typeof data === 'object') {
            var json = JSON.stringify(data, null, 2);
            return '<div class="json-preview">' + syntaxHighlight(json) + '</div>';
        }

        return escapeHtml(String(data));
    }

    /**
     * Applies syntax highlighting to a JSON string.
     *
     * @param {string} json - The JSON string.
     * @returns {string} HTML with syntax highlighting.
     */
    function syntaxHighlight(json) {
        json = escapeHtml(json);
        return json.replace(
            /("(\\u[\da-fA-F]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g,
            function (match) {
                var cls = 'color:var(--orange)'; // number
                if (/^"/.test(match)) {
                    if (/:$/.test(match)) {
                        cls = 'color:var(--blue)'; // key
                    } else {
                        cls = 'color:var(--accent)'; // string
                    }
                } else if (/true|false/.test(match)) {
                    cls = 'color:var(--purple)'; // boolean
                } else if (/null/.test(match)) {
                    cls = 'color:var(--text-muted)'; // null
                }
                return '<span style="' + cls + '">' + match + '</span>';
            }
        );
    }

    // ─── Detail Panel ───────────────────────────────────────

    /**
     * Shows entry details in the side panel.
     *
     * @param {Object} entry - The entry data.
     * @param {HTMLElement} el - The clicked DOM element.
     */
    function selectEntry(entry, el) {
        // Deselect previous
        var prev = dom.debugLog.querySelector('.log-entry.selected');
        if (prev) prev.classList.remove('selected');

        el.classList.add('selected');
        selectedEntryId = entry.id;

        dom.detailPanel.classList.remove('hidden');

        var origin = '';
        if (entry.origin && entry.origin.file) {
            origin = entry.origin.file;
        }

        var dataJson = typeof entry.data === 'object'
            ? JSON.stringify(entry.data, null, 2)
            : String(entry.data);

        dom.detailBody.innerHTML =
            '<div class="detail-section">' +
            '<div class="detail-meta-grid">' +
            '<div class="detail-meta-item"><div class="dm-label">Type</div><div class="dm-value">' + escapeHtml(entry.type || 'info') + '</div></div>' +
            '<div class="detail-meta-item"><div class="dm-label">Time</div><div class="dm-value">' + formatTimestamp(entry.timestamp) + '</div></div>' +
            '<div class="detail-meta-item"><div class="dm-label">File</div><div class="dm-value">' + escapeHtml(origin || 'unknown') + '</div></div>' +
            '<div class="detail-meta-item"><div class="dm-label">Line</div><div class="dm-value">' + (entry.origin ? entry.origin.line : 0) + '</div></div>' +
            '</div>' +
            '</div>' +
            '<div class="detail-section">' +
            '<div class="detail-label">Data</div>' +
            '<div class="detail-json">' + syntaxHighlight(escapeHtml(dataJson)) + '</div>' +
            '</div>';
    }

    // ─── Filters ────────────────────────────────────────────

    /**
     * Applies the active filter and search query to all entries.
     */
    function applyFilters() {
        var entries = dom.debugLog.querySelectorAll('.log-entry');
        var visible = 0;

        entries.forEach(function (entry) {
            var matchesFilter = activeFilter === 'all' || entry.dataset.type === activeFilter;
            var matchesSearch = !searchQuery || entry.textContent.toLowerCase().includes(searchQuery);
            var show = matchesFilter && matchesSearch;

            entry.style.display = show ? 'flex' : 'none';
            if (show) visible++;
        });

        dom.visibleCount.textContent = String(visible);
    }

    // ─── Helpers ────────────────────────────────────────────

    /**
     * Formats a UNIX timestamp to HH:MM:SS.mmm.
     *
     * @param {number} ts - The timestamp.
     * @returns {string} The formatted time string.
     */
    function formatTimestamp(ts) {
        var d = new Date(ts * 1000);
        var h = String(d.getHours()).padStart(2, '0');
        var m = String(d.getMinutes()).padStart(2, '0');
        var s = String(d.getSeconds()).padStart(2, '0');
        var ms = String(d.getMilliseconds()).padStart(3, '0');
        return h + ':' + m + ':' + s + '.' + ms;
    }

    /**
     * Escapes HTML special characters.
     *
     * @param {string} str - The input string.
     * @returns {string} The escaped string.
     */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Updates the statistics display.
     */
    function updateStats() {
        dom.statTotal.textContent = String(stats.total);
        dom.statErrors.textContent = String(stats.errors);
        dom.statSql.textContent = String(stats.sql);
        dom.visibleCount.textContent = String(stats.total);
    }

    // ─── Event Listeners ────────────────────────────────────

    // Filter chips
    document.querySelectorAll('.chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            document.querySelectorAll('.chip').forEach(function (c) {
                c.classList.remove('active');
            });
            chip.classList.add('active');
            activeFilter = chip.dataset.filter;
            applyFilters();
        });
    });

    // Search
    dom.searchInput.addEventListener('input', function (e) {
        searchQuery = e.target.value.toLowerCase();
        applyFilters();
    });

    // Pause
    dom.pauseBtn.addEventListener('click', function () {
        paused = !paused;
        dom.pauseBtn.classList.toggle('active', paused);
        dom.pauseBtn.innerHTML = paused ? '&#9654; Resume' : '&#10074;&#10074; Pause';
    });

    // Clear
    document.getElementById('clearBtn').addEventListener('click', function () {
        clearSession();
        dom.debugLog.innerHTML = '';
        dom.emptyState.style.display = 'flex';
        dom.detailPanel.classList.add('hidden');
        stats = { total: 0, errors: 0, sql: 0 };
        updateStats();
    });

    // New session
    document.getElementById('newSessionBtn').addEventListener('click', function () {
        dom.debugLog.innerHTML = '';
        dom.emptyState.style.display = 'flex';
        dom.detailPanel.classList.add('hidden');
        stats = { total: 0, errors: 0, sql: 0 };
        updateStats();
        startNewSession();
    });

    // Auto-scroll toggle
    dom.autoScrollToggle.addEventListener('click', function () {
        autoScroll = !autoScroll;
        dom.autoScrollToggle.classList.toggle('on', autoScroll);
    });

    // Detail panel close
    document.getElementById('detailClose').addEventListener('click', function () {
        dom.detailPanel.classList.add('hidden');
        var prev = dom.debugLog.querySelector('.log-entry.selected');
        if (prev) prev.classList.remove('selected');
    });

    // ─── Session Timer ──────────────────────────────────────
    let sessionExpiresAt = null;

    function updateSessionTimer() {
        if (!sessionExpiresAt) return;

        var now = new Date();
        var diff = sessionExpiresAt - now;

        if (diff <= 0) {
            dom.sessionTimer.textContent = 'Session expired';
            return;
        }

        var hours = Math.floor(diff / 3600000);
        var minutes = Math.floor((diff % 3600000) / 60000);
        var seconds = Math.floor((diff % 60000) / 1000);

        dom.sessionTimer.textContent = 'Expires in ' +
            String(hours).padStart(2, '0') + ':' +
            String(minutes).padStart(2, '0') + ':' +
            String(seconds).padStart(2, '0');
    }

    setInterval(updateSessionTimer, 1000);

    // ─── Session Persistence ────────────────────────────────

    /**
     * Saves the current session to localStorage.
     *
     * @param {Object} session - The session data from the API.
     */
    function saveSession(session) {
        localStorage.setItem('debugphp_session', JSON.stringify({
            id: session.id,
            expires_at: session.expires_at,
        }));
    }

    /**
     * Loads a stored session from localStorage.
     *
     * Returns null if no session is stored or if it has expired.
     *
     * @returns {Object|null} The stored session or null.
     */
    function loadSession() {
        var stored = localStorage.getItem('debugphp_session');

        if (!stored) return null;

        try {
            var session = JSON.parse(stored);
            var expiresAt = new Date(session.expires_at);

            // Check if session has expired locally
            if (expiresAt <= new Date()) {
                localStorage.removeItem('debugphp_session');
                return null;
            }

            return session;
        } catch (e) {
            localStorage.removeItem('debugphp_session');
            return null;
        }
    }

    /**
     * Applies a session (new or restored) to the UI and connects the stream.
     *
     * @param {Object} session - The session data.
     * @param {boolean} isNew - Whether this is a freshly created session.
     */
    function applySession(session, isNew) {
        sessionId = session.id;
        sessionExpiresAt = new Date(session.expires_at);

        dom.sessionId.textContent = sessionId;
        dom.sessionInfo.classList.remove('disconnected');

        if (isNew) {
            dom.debugLog.innerHTML = '';
            dom.emptyState.style.display = 'flex';
            dom.detailPanel.classList.add('hidden');
            stats = { total: 0, errors: 0, sql: 0 };
            updateStats();
        }

        saveSession(session);
        connectStream(sessionId);
    }

    /**
     * Creates a brand new session via the API.
     */
    async function startNewSession() {
        try {
            var session = await createSession();
            applySession(session, true);
        } catch (err) {
            console.error('Failed to create session:', err);
        }
    }

    // ─── Init ───────────────────────────────────────────────

    /**
     * Initializes the dashboard.
     *
     * Tries to restore an existing session from localStorage.
     * Only creates a new session if none is stored or the stored
     * one has expired.
     */
    async function init() {
        var stored = loadSession();

        if (stored) {
            // Restore existing session
            applySession(stored, false);
        } else {
            // No valid session — create a new one
            await startNewSession();
        }
    }

    // Start on page load
    init();
})();
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

    // ─── Editor Picker ──────────────────────────────────────

    /**
     * Supported editors with their deep-link URL schemes.
     *
     * Placeholders:
     *   {fullpath} — absolute path including filename
     *   {line}     — line number
     *
     * @type {Array<{id: string, label: string, scheme: string|null}>}
     */
    const EDITORS = [
        { id: 'none', label: 'None (disabled)', scheme: null },
        { id: 'vscode', label: 'VS Code', scheme: 'vscode://file/{fullpath}:{line}' },
        { id: 'vscode-insiders', label: 'VS Code Insiders', scheme: 'vscode-insiders://file/{fullpath}:{line}' },
        { id: 'cursor', label: 'Cursor', scheme: 'cursor://file/{fullpath}:{line}' },
        { id: 'phpstorm', label: 'PhpStorm', scheme: 'phpstorm://open?file={fullpath}&line={line}' },
        { id: 'sublime', label: 'Sublime Text', scheme: 'subl://open?url=file://{fullpath}&line={line}' },
    ];

    /** Currently selected editor ID, persisted in localStorage (user-specific). */
    let activeEditor = localStorage.getItem('debugphp_editor') || 'none';

    /**
     * Builds the editor deep-link URL for the given file and line.
     *
     * @param {string} editorId - The editor ID from EDITORS.
     * @param {string} fullPath - The absolute file path (directory + filename).
     * @param {number} line     - The line number.
     * @returns {string|null} The editor URL or null if editor is 'none'.
     */
    function buildEditorUrl(editorId, fullPath, line) {
        let editor = EDITORS.find(function (e) { return e.id === editorId; });
        if (!editor || !editor.scheme) return null;

        return editor.scheme
            .replace('{fullpath}', encodeURIComponent(fullPath).replace(/%2F/g, '/'))
            .replace('{line}', String(line));
    }

    /**
     * Opens the given file and line in the currently selected editor.
     *
     * @param {string} path - Directory path of the file.
     * @param {string} file - Filename (basename).
     * @param {number} line - Line number.
     */
    function openInEditor(path, file, line) {
        if (activeEditor === 'none') return;

        let fullPath = (path && path !== 'unknown')
            ? (path.replace(/\/$/, '') + '/' + file)
            : file;

        let url = buildEditorUrl(activeEditor, fullPath, line);
        if (url) window.location.href = url;
    }

    /**
     * Renders the editor dropdown option list and updates the topbar button label.
     * Called once on init and whenever the selection changes.
     */
    function renderEditorOptions() {
        let list = document.getElementById('editorOptionsList');
        let label = document.getElementById('editorPickerLabel');

        if (!list || !label) return;

        list.innerHTML = '';

        EDITORS.forEach(function (editor) {
            let isActive = editor.id === activeEditor;

            let option = document.createElement('button');
            option.className = 'editor-option' + (isActive ? ' active' : '');
            option.dataset.editorId = editor.id;
            option.innerHTML =
                '<span class="editor-option-label">' + escapeHtml(editor.label) + '</span>' +
                '<span class="editor-option-check">&#10003;</span>';

            option.addEventListener('click', function () {
                activeEditor = editor.id;
                localStorage.setItem('debugphp_editor', activeEditor);
                updateEditorState();
                closeEditorDropdown();
            });

            list.appendChild(option);
        });

        let currentEditor = EDITORS.find(function (e) { return e.id === activeEditor; });
        label.textContent = (currentEditor && currentEditor.id !== 'none')
            ? currentEditor.label
            : 'Editor';
    }

    /**
     * Syncs all origin elements in the log to reflect the current editor state.
     * Adds/removes the `clickable` class and cursor styling.
     */
    function updateEditorState() {
        renderEditorOptions();

        let hasEditor = activeEditor !== 'none';
        document.body.classList.toggle('has-editor', hasEditor);

        let btn = document.getElementById('editorPickerBtn');
        if (btn) btn.classList.toggle('active', hasEditor);
    }

    /**
     * Closes the editor dropdown.
     */
    function closeEditorDropdown() {
        let dropdown = document.getElementById('editorDropdown');
        if (dropdown) dropdown.style.display = 'none';
    }

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
        topbarMetrics: document.getElementById('topbarMetrics'),
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

            let data = JSON.parse(e.data);
            addEntry(data);
        });

        eventSource.addEventListener('metric', function (e) {
            let data = JSON.parse(e.data);
            updateMetric(data.key, data.value);
        });

        eventSource.addEventListener('metric:remove', function (e) {
            let data = JSON.parse(e.data);
            removeMetric(data.key);
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

    // ─── Metrics ────────────────────────────────────────────

    /**
     * Inserts a metric chip into the topbar or updates an existing one.
     *
     * @param {string}      key   - The metric name.
     * @param {string|null} value - The value, or null for label-only display.
     */
    function updateMetric(key, value) {
        let attrKey = key.replace(/[^a-zA-Z0-9_-]/g, '_');
        let existing = dom.topbarMetrics.querySelector('[data-metric-key="' + attrKey + '"]');

        if (existing) {
            existing.classList.remove('updated');
            void existing.offsetWidth;

            if (value !== null && value !== undefined) {
                let valEl = existing.querySelector('.metric-value');
                if (valEl) {
                    valEl.textContent = value;
                }
                existing.classList.remove('label-only');
            }

            existing.classList.add('updated');
            return;
        }

        let chip = document.createElement('span');
        chip.className = 'metric-chip' + (value === null || value === undefined ? ' label-only' : '');
        chip.dataset.metricKey = attrKey;

        if (value !== null && value !== undefined) {
            chip.innerHTML =
                '<span class="metric-key">' + escapeHtml(key) + ':</span>' +
                '<span class="metric-value">' + escapeHtml(String(value)) + '</span>';
        } else {
            chip.innerHTML = '<span class="metric-key">' + escapeHtml(key) + '</span>';
        }

        dom.topbarMetrics.appendChild(chip);
    }

    /**
     * Removes a metric chip from the topbar with a fade-out animation.
     *
     * @param {string} key - The metric name to remove.
     */
    function removeMetric(key) {
        let attrKey = key.replace(/[^a-zA-Z0-9_-]/g, '_');
        let chip = dom.topbarMetrics.querySelector('[data-metric-key="' + attrKey + '"]');

        if (!chip) return;

        chip.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        chip.style.opacity = '0';
        chip.style.transform = 'translateY(-4px)';

        setTimeout(function () {
            if (chip.parentNode) {
                chip.parentNode.removeChild(chip);
            }
        }, 300);
    }

    /**
     * Removes all metric chips from the topbar.
     */
    function clearMetrics() {
        dom.topbarMetrics.innerHTML = '';
    }

    // ─── Entry Rendering ────────────────────────────────────

    /**
     * Adds a debug entry to the log.
     *
     * @param {Object} entry - The entry data from the server.
     */
    function addEntry(entry) {
        dom.emptyState.style.display = 'none';

        let el = document.createElement('div');
        el.className = 'log-entry';
        el.dataset.type = entry.type || 'info';
        el.dataset.id = String(entry.id);

        let colorClass = colorMap[entry.color] || 'c-gray';
        let labelClass = labelMap[entry.type] || 'label-info';
        let time = formatTimestamp(entry.timestamp);
        let label = entry.label || entry.type || 'info';

        let originHtml = '';
        if (entry.origin && entry.origin.file) {
            let originText = entry.origin.file + (entry.origin.line ? ':' + entry.origin.line : '');
            originHtml =
                '<span class="entry-origin"' +
                ' data-file="' + escapeHtml(entry.origin.file) + '"' +
                ' data-path="' + escapeHtml(entry.origin.path || '') + '"' +
                ' data-line="' + (entry.origin.line || 0) + '"' +
                ' title="Open in editor">' +
                escapeHtml(originText) +
                '</span>';
        }

        originHtml +=
            '<button class="entry-delete-btn" title="Delete entry" data-entry-id="' + String(entry.id) + '">&#xd7;</button>';


        let content = entry.type === 'table'
            ? formatTable(entry.data)
            : formatData(entry.data);

        el.innerHTML =
            '<div class="entry-color ' + colorClass + '"></div>' +
            '<div class="entry-body">' +
            '<div class="entry-header">' +
            '<span class="entry-label ' + labelClass + '">' + escapeHtml(label) + '</span>' +
            '<span class="entry-time">' + time + '</span>' +
            originHtml +
            '</div>' +
            '<div class="entry-content">' + content + '</div>' +
            '</div>';

        el.addEventListener('click', function () {
            if (e.target.closest('.entry-delete-btn')) return;
            selectEntry(entry, el);
        });

        dom.debugLog.appendChild(el);

        // Apply active filters
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
     * Sends a DELETE request to remove a single entry from the server.
     *
     * @param {number} entryId   - The numeric entry ID.
     * @param {string} sessionId - The current session ID (for ownership check).
     * @returns {Promise<boolean>} True if the server confirmed deletion.
     */
    async function deleteEntry(entryId, currentSessionId) {
        try {
            const response = await fetch('/api/entry/' + entryId, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session: currentSessionId }),
            });

            if (!response.ok) {
                return false;
            }

            const result = await response.json();
            return result.deleted === true;
        } catch (err) {
            console.error('Failed to delete entry:', err);
            return false;
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
            let json = JSON.stringify(data, null, 2);
            return '<div class="json-preview">' + syntaxHighlight(json) + '</div>';
        }

        return escapeHtml(String(data));
    }

    /**
     * Renders a table entry as an HTML table.
     *
     * Expects data in the shape { headers: string[]|null, rows: object[] }.
     * When headers is null, column names are derived from the union of all
     * row keys.
     *
     * @param {Object} data - Table payload with optional headers and rows array.
     * @returns {string} HTML string for the complete table.
     */
    function formatTable(data) {
        if (!data || typeof data !== 'object' || !Array.isArray(data.rows)) {
            return '<span style="color:var(--text-muted)">Invalid table data</span>';
        }

        let rows = data.rows;

        if (rows.length === 0) {
            return '<span style="color:var(--text-muted)">Empty table</span>';
        }

        let keySet = [];
        let keysSeen = new Set();
        rows.forEach(function (row) {
            if (row && typeof row === 'object' && !Array.isArray(row)) {
                Object.keys(row).forEach(function (k) {
                    if (!keysSeen.has(k)) {
                        keysSeen.add(k);
                        keySet.push(k);
                    }
                });
            }
        });

        let explicitHeaders = Array.isArray(data.headers) ? data.headers : null;

        let displayHeaders = keySet.map(function (key, index) {
            return (explicitHeaders && explicitHeaders[index] !== undefined)
                ? String(explicitHeaders[index])
                : key;
        });

        let rowCount = rows.length;
        let badge = '<span class="table-row-count">' + rowCount + ' row' + (rowCount !== 1 ? 's' : '') + '</span>';

        let html = '<div class="debug-table-wrap">';
        html += badge;
        html += '<div class="debug-table-scroll"><table class="debug-table">';

        html += '<thead><tr>';
        html += '<th class="debug-table-th debug-table-th--idx">#</th>';
        displayHeaders.forEach(function (header) {
            html += '<th class="debug-table-th">' + escapeHtml(header) + '</th>';
        });
        html += '</tr></thead>';

        html += '<tbody>';
        rows.forEach(function (row, rowIndex) {
            html += '<tr class="debug-table-row">';
            html += '<td class="debug-table-td debug-table-td--idx">' + (rowIndex + 1) + '</td>';
            keySet.forEach(function (key) {
                let val = (row && typeof row === 'object') ? row[key] : undefined;
                html += '<td class="debug-table-td">' + formatCell(val) + '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody>';

        html += '</table></div></div>';

        return html;
    }

    /**
     * Formats a single table cell value as an HTML snippet.
     *
     * @param {*} val - The raw cell value.
     * @returns {string} Styled HTML for the cell content.
     */
    function formatCell(val) {
        if (val === null || val === undefined) {
            return '<span class="cell-null">—</span>';
        }

        if (typeof val === 'boolean') {
            return '<span class="cell-bool cell-bool--' + String(val) + '">' + String(val) + '</span>';
        }

        if (typeof val === 'number') {
            return '<span class="cell-num">' + String(val) + '</span>';
        }

        if (typeof val === 'object') {
            return '<span class="cell-json">' + escapeHtml(JSON.stringify(val)) + '</span>';
        }

        return '<span class="cell-str">' + escapeHtml(String(val)) + '</span>';
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
                let cls = 'color:var(--orange)'; // number
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
     * @param {Object}      entry - The entry data.
     * @param {HTMLElement} el    - The clicked DOM element.
     */
    function selectEntry(entry, el) {
        let prev = dom.debugLog.querySelector('.log-entry.selected');
        if (prev) prev.classList.remove('selected');

        el.classList.add('selected');
        selectedEntryId = entry.id;

        dom.detailPanel.classList.remove('hidden');

        let originFile = (entry.origin && entry.origin.file) ? entry.origin.file : 'unknown';
        let originPath = (entry.origin && entry.origin.path) ? entry.origin.path : 'unknown';
        let originLine = entry.origin ? entry.origin.line : 0;

        let dataSection;
        if (entry.type === 'table') {
            dataSection =
                '<div class="detail-section">' +
                '<div class="detail-label">Table</div>' +
                formatTable(entry.data) +
                '</div>';
        } else {
            let dataJson = typeof entry.data === 'object'
                ? JSON.stringify(entry.data, null, 2)
                : String(entry.data);
            dataSection =
                '<div class="detail-section">' +
                '<div class="detail-label">Data</div>' +
                '<div class="detail-json">' + syntaxHighlight(escapeHtml(dataJson)) + '</div>' +
                '</div>';
        }

        let pathRowContent =
            '<div class="dm-label">Path</div>' +
            '<div class="dm-value dm-path">' + escapeHtml(originPath) + '</div>';

        if (activeEditor !== 'none') {
            pathRowContent +=
                '<button class="detail-open-btn" ' +
                'data-file="' + escapeHtml(originFile) + '" ' +
                'data-path="' + escapeHtml(originPath) + '" ' +
                'data-line="' + originLine + '">' +
                'Open in ' + escapeHtml(EDITORS.find(function (e) { return e.id === activeEditor; })?.label || 'Editor') +
                '</button>';
        }

        dom.detailBody.innerHTML =
            '<div class="detail-section">' +
            '<div class="detail-meta-grid">' +
            '<div class="detail-meta-item"><div class="dm-label">Type</div><div class="dm-value">' + escapeHtml(entry.type || 'info') + '</div></div>' +
            '<div class="detail-meta-item"><div class="dm-label">Time</div><div class="dm-value">' + formatTimestamp(entry.timestamp) + '</div></div>' +
            '<div class="detail-meta-item"><div class="dm-label">File</div><div class="dm-value">' + escapeHtml(originFile) + '</div></div>' +
            '<div class="detail-meta-item"><div class="dm-label">Line</div><div class="dm-value">' + originLine + '</div></div>' +
            '<div class="detail-meta-item full-width">' + pathRowContent + '</div>' +
            '</div>' +
            '</div>' +
            dataSection;
    }

    // ─── Filters ────────────────────────────────────────────

    /**
     * Applies the active filter and search query to all entries.
     */
    function applyFilters() {
        let entries = dom.debugLog.querySelectorAll('.log-entry');
        let visible = 0;

        entries.forEach(function (entry) {
            let matchesFilter = activeFilter === 'all' || entry.dataset.type === activeFilter;
            let matchesSearch = !searchQuery || entry.textContent.toLowerCase().includes(searchQuery);
            let show = matchesFilter && matchesSearch;

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
        let d = new Date(ts * 1000);
        let h = String(d.getHours()).padStart(2, '0');
        let m = String(d.getMinutes()).padStart(2, '0');
        let s = String(d.getSeconds()).padStart(2, '0');
        let ms = String(d.getMilliseconds()).padStart(3, '0');
        return h + ':' + m + ':' + s + '.' + ms;
    }

    /**
     * Escapes HTML special characters.
     *
     * @param {string} str - The input string.
     * @returns {string} The escaped string.
     */
    function escapeHtml(str) {
        let div = document.createElement('div');
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
        clearMetrics();
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
        let prev = dom.debugLog.querySelector('.log-entry.selected');
        if (prev) prev.classList.remove('selected');
    });

    // ─── Editor Picker Events ────────────────────────────────

    // Toggle editor dropdown
    document.getElementById('editorPickerBtn').addEventListener('click', function (e) {
        e.stopPropagation();
        let dropdown = document.getElementById('editorDropdown');
        let isVisible = dropdown.style.display !== 'none';
        dropdown.style.display = isVisible ? 'none' : 'block';
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function (e) {
        let picker = document.getElementById('editorPicker');
        if (picker && !picker.contains(e.target)) {
            closeEditorDropdown();
        }
    });

    // Origin click in log → open in editor (event delegation)
    dom.debugLog.addEventListener('click', function (e) {
        if (activeEditor === 'none') return;

        let originEl = e.target.closest('.entry-origin');
        if (!originEl) return;

        e.stopPropagation();

        let file = originEl.dataset.file || '';
        let path = originEl.dataset.path || '';
        let line = parseInt(originEl.dataset.line || '0', 10);
        openInEditor(path, file, line);
    });

    // "Open in Editor" button in detail panel (event delegation)
    dom.detailBody.addEventListener('click', function (e) {
        let btn = e.target.closest('.detail-open-btn');
        if (!btn) return;

        let file = btn.dataset.file || '';
        let path = btn.dataset.path || '';
        let line = parseInt(btn.dataset.line || '0', 10);
        openInEditor(path, file, line);
    });

    // Delete entry via event delegation on the log container
    dom.debugLog.addEventListener('click', function (e) {
        let btn = e.target.closest('.entry-delete-btn');
        if (!btn) return;

        e.stopPropagation();

        let entryId = parseInt(btn.dataset.entryId || '0', 10);
        if (!entryId || !sessionId) return;

        let logEntry = btn.closest('.log-entry');
        if (!logEntry) return;

        logEntry.classList.add('is-deleting');

        deleteEntry(entryId, sessionId).then(function (success) {
            if (success) {
                let type = logEntry.dataset.type || 'info';
                stats.total = Math.max(0, stats.total - 1);
                if (type === 'error') stats.errors = Math.max(0, stats.errors - 1);
                if (type === 'sql') stats.sql = Math.max(0, stats.sql - 1);
                updateStats();

                if (logEntry.classList.contains('selected')) {
                    dom.detailPanel.classList.add('hidden');
                }

                setTimeout(function () {
                    logEntry.remove();

                    let remaining = dom.debugLog.querySelectorAll('.log-entry');
                    if (remaining.length === 0) {
                        dom.emptyState.style.display = 'flex';
                    }
                }, 300);
            } else {
                logEntry.classList.remove('is-deleting');
            }
        });
    });

    // ─── Session Timer ──────────────────────────────────────
    let sessionExpiresAt = null;

    function updateSessionTimer() {
        if (!sessionExpiresAt) return;

        let now = new Date();
        let diff = sessionExpiresAt - now;

        if (diff <= 0) {
            dom.sessionTimer.textContent = 'Session expired';
            return;
        }

        let hours = Math.floor(diff / 3600000);
        let minutes = Math.floor((diff % 3600000) / 60000);
        let seconds = Math.floor((diff % 60000) / 1000);

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
        let stored = localStorage.getItem('debugphp_session');

        if (!stored) return null;

        try {
            let session = JSON.parse(stored);
            let expiresAt = new Date(session.expires_at);

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
     * @param {Object}  session - The session data.
     * @param {boolean} isNew   - Whether this is a freshly created session.
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
            clearMetrics();
        }

        saveSession(session);
        connectStream(sessionId);
    }

    /**
     * Creates a brand new session via the API.
     */
    async function startNewSession() {
        try {
            let session = await createSession();
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
     * Only creates a new session if none is stored or the stored one has expired.
     */
    async function init() {
        renderEditorOptions();
        updateEditorState();

        let stored = loadSession();

        if (stored) {
            applySession(stored, false);
        } else {
            await startNewSession();
        }
    }

    init();
})();
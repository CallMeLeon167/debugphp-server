/**
 * DebugPHP Dashboard
 *
 * (c) Leon Schmidt — MIT License
 * https://github.com/CallMeLeon167/debugphp-server
 */

(function () {
    'use strict';
    const BASE = window.__DEBUGPHP_BASE || '';

    // ─── State ──────────────────────────────────────────────
    let sessionId = null;
    let eventSource = null;
    let paused = false;
    let autoScroll = true;
    let activeFilter = 'all';
    let activeLabelFilter = 'all';
    let searchQuery = '';
    let selectedEntryId = null;
    let stats = { total: 0, errors: 0, sql: 0 };
    let lastRequestId = null;
    let autoClear = localStorage.getItem('debugphp_autoclear') === 'true';
    let typeCounts = new Map();
    let labelCounts = new Map();

    const TYPE_COLORS = {
        info: 'var(--blue)',
        sql: 'var(--purple)',
        error: 'var(--red)',
        timer: 'var(--yellow)',
        success: 'var(--accent)',
        cache: 'var(--orange)',
        table: 'var(--blue)',
    };

    // ─── Editor Picker ──────────────────────────────────────

    /**
     * Supported editors with their deep-link URL schemes.
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
        searchInput: document.getElementById('searchInput'),
        pauseBtn: document.getElementById('pauseBtn'),
        autoClearBtn: document.getElementById('autoClearBtn'),
        detailPanel: document.getElementById('detailPanel'),
        detailBody: document.getElementById('detailBody'),
        statTotal: document.getElementById('statTotal'),
        statErrors: document.getElementById('statErrors'),
        statSql: document.getElementById('statSql'),
        sessionTimer: document.getElementById('sessionTimer'),
        topbarMetrics: document.getElementById('topbarMetrics'),
        typeFilterChips: document.getElementById('typeFilterChips'),
        labelFilterSection: document.getElementById('labelFilterSection'),
        labelFilterChips: document.getElementById('labelFilterChips'),
        debugToolbar: document.getElementById('debugToolbar'),
    };

    // ─── Color / Label class mapping ─────────────────────────
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
        const response = await fetch(BASE + '/api/session', { method: 'POST' });
        return response.json();
    }

    /**
     * Sends a clear command for the current session.
     */
    async function clearSession() {
        if (!sessionId) return;

        await fetch(BASE + '/api/clear', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session: sessionId }),
        });
    }

    /**
     * Sends a DELETE request to remove a single entry from the server.
     *
     * @param {number} entryId         - The numeric entry ID.
     * @param {string} currentSessionId - The current session ID (for ownership check).
     * @returns {Promise<boolean>} True if the server confirmed deletion.
     */
    async function deleteEntry(entryId, currentSessionId) {
        try {
            const response = await fetch(BASE + '/api/entry/' + entryId, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session: currentSessionId }),
            });

            if (!response.ok) return false;

            const result = await response.json();
            return result.deleted === true;
        } catch (err) {
            console.error('Failed to delete entry:', err);
            return false;
        }
    }

    // ─── Auto-Clear ──────────────────────────────────────────

    /**
     * Syncs the auto-clear button appearance with the current state.
     * Active state is visually indicated via the `.active` CSS class.
     */
    function updateAutoClearBtn() {
        dom.autoClearBtn.classList.toggle('active', autoClear);
    }

    /**
     * Clears all entries from the DOM only, without calling the server.
     * Resets both type and label filter state.
     */
    function clearDomEntries() {
        dom.debugLog.innerHTML = '';
        dom.emptyState.style.display = 'flex';
        dom.detailPanel.classList.add('hidden');
        stats = { total: 0, errors: 0, sql: 0 };
        updateStats();
        resetTypeFilters();
        resetLabelFilters();
    }

    /**
     * Checks whether the given entry signals a new PHP request.
     *
     * Called before rendering each incoming entry. If auto-clear is active
     * and a different request_id is detected, the dashboard clears all previous
     * entries (both DOM and server-side) before rendering the new one.
     *
     * @param {string} requestId - The request_id from the incoming SSE entry.
     */
    function handleRequestIdChange(requestId) {
        if (!requestId) return;

        if (lastRequestId !== null && requestId !== lastRequestId && autoClear) {
            clearDomEntries();
            clearSession();
        }

        lastRequestId = requestId;
    }

    // ─── Type Filter ─────────────────────────────────────────

    /**
     * Registers a new entry type in the type filter sidebar.
     * Creates a chip if it doesn't exist yet, otherwise increments the count.
     *
     * @param {string} type - The entry type (e.g. "info", "sql", "error").
     */
    function registerType(type) {
        if (!type) return;

        const current = typeCounts.get(type) || 0;
        typeCounts.set(type, current + 1);

        const attrKey = type.replace(/[^a-zA-Z0-9_-]/g, '_');
        let chip = dom.typeFilterChips.querySelector('[data-type-key="' + attrKey + '"]');

        if (chip) {
            const countEl = chip.querySelector('.chip-count');
            if (countEl) countEl.textContent = String(typeCounts.get(type));
            return;
        }

        chip = document.createElement('span');
        chip.className = 'chip';
        chip.dataset.typeKey = attrKey;
        chip.dataset.typeValue = type;
        chip.dataset.filter = type;

        const dotColor = TYPE_COLORS[type] || 'var(--text-muted)';

        chip.innerHTML =
            '<span class="chip-dot" style="background:' + dotColor + '"></span>' +
            escapeHtml(type.charAt(0).toUpperCase() + type.slice(1)) +
            '<span class="chip-count">' + String(typeCounts.get(type)) + '</span>';

        chip.addEventListener('click', function () {
            setTypeFilter(type);
        });

        dom.typeFilterChips.appendChild(chip);
    }

    /**
     * Decrements the count for a type after an entry is deleted.
     * Removes the chip and resets the active filter if the count reaches zero.
     *
     * @param {string} type - The type of the deleted entry.
     */
    function unregisterType(type) {
        if (!type) return;

        const current = typeCounts.get(type) || 0;
        const next = current - 1;

        if (next <= 0) {
            typeCounts.delete(type);

            const attrKey = type.replace(/[^a-zA-Z0-9_-]/g, '_');
            const chip = dom.typeFilterChips.querySelector('[data-type-key="' + attrKey + '"]');
            if (chip) chip.remove();

            if (activeFilter === type) {
                activeFilter = 'all';
                dom.typeFilterChips.querySelector('[data-filter="all"]').classList.add('active');
            }
        } else {
            typeCounts.set(type, next);

            const attrKey = type.replace(/[^a-zA-Z0-9_-]/g, '_');
            const chip = dom.typeFilterChips.querySelector('[data-type-key="' + attrKey + '"]');
            if (chip) {
                const countEl = chip.querySelector('.chip-count');
                if (countEl) countEl.textContent = String(next);
            }
        }
    }

    /**
     * Activates a type filter chip. Clicking the active chip resets to 'all'.
     *
     * @param {string} type
     */
    function setTypeFilter(type) {
        if (activeFilter === type) {
            activeFilter = 'all';
        } else {
            activeFilter = type;
        }

        dom.typeFilterChips.querySelectorAll('.chip').forEach(function (chip) {
            chip.classList.toggle(
                'active',
                chip.dataset.filter === activeFilter || (activeFilter === 'all' && chip.dataset.filter === 'all')
            );
        });

        applyFilters();
    }

    /**
     * Resets all type filter state and removes all dynamic type chips.
     * Called on clearDomEntries() and startNewSession().
     */
    function resetTypeFilters() {
        activeFilter = 'all';
        typeCounts.clear();

        dom.typeFilterChips.querySelectorAll('[data-type-key]').forEach(function (chip) {
            chip.remove();
        });

        const allChip = dom.typeFilterChips.querySelector('[data-filter="all"]');
        if (allChip) allChip.classList.add('active');
    }

    // ─── Label Filter ────────────────────────────────────────

    /**
     * Registers a new entry label in the label filter sidebar.
     *
     * @param {string} label
     */
    function registerLabel(label) {
        if (!label) return;

        const current = labelCounts.get(label) || 0;
        labelCounts.set(label, current + 1);

        const attrKey = label.replace(/[^a-zA-Z0-9_-]/g, '_');
        let chip = dom.labelFilterChips.querySelector('[data-label-key="' + attrKey + '"]');

        if (chip) {
            const countEl = chip.querySelector('.chip-count');
            if (countEl) countEl.textContent = String(labelCounts.get(label));
            return;
        }

        chip = document.createElement('span');
        chip.className = 'chip';
        chip.dataset.labelKey = attrKey;
        chip.dataset.labelValue = label;
        chip.innerHTML =
            escapeHtml(label) +
            '<span class="chip-count">' + String(labelCounts.get(label)) + '</span>';

        chip.addEventListener('click', function () {
            setLabelFilter(label);
        });

        dom.labelFilterChips.appendChild(chip);
        dom.labelFilterSection.style.display = '';
    }

    /**
     * Decrements the count for a label after an entry is deleted.
     * Removes the chip and hides the section if the count reaches zero.
     *
     * @param {string} label
     */
    function unregisterLabel(label) {
        if (!label) return;

        const current = labelCounts.get(label) || 0;
        const next = current - 1;

        if (next <= 0) {
            labelCounts.delete(label);

            const attrKey = label.replace(/[^a-zA-Z0-9_-]/g, '_');
            const chip = dom.labelFilterChips.querySelector('[data-label-key="' + attrKey + '"]');
            if (chip) chip.remove();

            if (activeLabelFilter === label) {
                activeLabelFilter = 'all';
            }

            if (labelCounts.size === 0) {
                dom.labelFilterSection.style.display = 'none';
            }
        } else {
            labelCounts.set(label, next);

            const attrKey = label.replace(/[^a-zA-Z0-9_-]/g, '_');
            const chip = dom.labelFilterChips.querySelector('[data-label-key="' + attrKey + '"]');
            if (chip) {
                const countEl = chip.querySelector('.chip-count');
                if (countEl) countEl.textContent = String(next);
            }
        }
    }

    /**
     * Activates a label filter chip. Clicking the active chip resets to 'all'.
     *
     * @param {string} label
     */
    function setLabelFilter(label) {
        if (activeLabelFilter === label) {
            activeLabelFilter = 'all';
        } else {
            activeLabelFilter = label;
        }

        dom.labelFilterChips.querySelectorAll('.chip').forEach(function (chip) {
            chip.classList.toggle('active', chip.dataset.labelValue === activeLabelFilter);
        });

        applyFilters();
    }

    /**
     * Resets all label filter state and removes all label chips.
     */
    function resetLabelFilters() {
        activeLabelFilter = 'all';
        labelCounts.clear();
        dom.labelFilterChips.innerHTML = '';
        dom.labelFilterSection.style.display = 'none';
    }

    // ─── SSE Connection ─────────────────────────────────────

    /**
     * @param {string} id
     */
    function connectStream(id) {
        if (eventSource) {
            eventSource.close();
        }

        eventSource = new EventSource(BASE + '/api/stream/' + id);

        eventSource.addEventListener('connected', function () {
            dom.sessionInfo.classList.remove('disconnected');
        });

        eventSource.addEventListener('entry', function (e) {
            if (paused) return;

            let data = JSON.parse(e.data);
            handleRequestIdChange(data.request_id || '');
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

        eventSource.addEventListener('environment', function (e) {
            let data = JSON.parse(e.data);
            renderEnvironment(data);
        });

        eventSource.onerror = function () {
            dom.sessionInfo.classList.add('disconnected');
        };
    }

    // ─── Environment ────────────────────────────────────────

    /**
     * Renders environment data as chips in the debug toolbar.
     * Replaces any previously rendered environment chips.
     *
     * @param {Object<string, string>} data - The environment key-value pairs.
     */
    function renderEnvironment(data) {
        clearEnvironment();
        Object.keys(data).forEach(function (key) {
            if (key == 'session') return;

            let value = data[key];
            if (!value) return;

            let chip = document.createElement('span');
            chip.className = 'env-chip';
            chip.innerHTML =
                '<span class="env-key">' + escapeHtml(key) + ':</span>' +
                '<span class="env-value">' + escapeHtml(value) + '</span>';

            dom.debugToolbar.appendChild(chip);
        });
    }

    /**
     * Clears all environment chips from the toolbar.
     */
    function clearEnvironment() {
        dom.debugToolbar.innerHTML = '';
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
                if (valEl) valEl.textContent = value;
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
            if (chip.parentNode) chip.parentNode.removeChild(chip);
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
        el.dataset.label = entry.label || '';
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

        el.addEventListener('click', function (e) {
            if (e.target.closest('.entry-delete-btn')) return;
            selectEntry(entry, el);
        });

        dom.debugLog.appendChild(el);

        registerType(entry.type || 'info');
        if (entry.label) registerLabel(entry.label);

        el.style.display = isEntryVisible(el) ? '' : 'none';

        stats.total++;
        if (entry.type === 'error') stats.errors++;
        if (entry.type === 'sql') stats.sql++;
        updateStats();

        if (autoScroll) {
            dom.debugLog.scrollTop = dom.debugLog.scrollHeight;
        }
    }

    // ─── PHP-Style Type Renderer ─────────────────────────────

    /**
     * Formats debug data for display in the log.
     *
     * If the data carries a typed PHP descriptor (produced by Entry::buildTyped()),
     * it is rendered in a var_dump-style format. Otherwise falls back to plain
     * JSON rendering for table payloads and legacy entries.
     *
     * @param {*} data - The debug data from the SSE event.
     * @returns {string} HTML string.
     */
    function formatData(data) {
        // Typed PHP descriptor: { type: 'string'|'int'|'array'|'object'|... }
        if (data !== null && typeof data === 'object' && typeof data.type === 'string') {
            return '<div class="php-dump">' + renderPhpNode(data, 0, '') + '</div>';
        }

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

    // ─── Copy Helpers ────────────────────────────────────────

    /**
     * Extracts the raw plain-text from a php-dump node exactly as displayed,
     * without any HTML tags. Used by the Copy button in the detail panel.
     *
     * @param {*}      data - The entry data (typed descriptor or raw value).
     * @param {string} type - The entry type (e.g. "table", "info").
     * @returns {string} The plain-text for the clipboard.
     */
    function extractCopyText(data, type) {
        if (type === 'table' && data && typeof data === 'object' && Array.isArray(data.rows)) {
            return extractTableTsv(data);
        }

        if (data !== null && typeof data === 'object' && typeof data.type === 'string') {
            return renderPhpNodeText(data, 0);
        }

        if (data === null || data === undefined) {
            return 'null';
        }

        if (typeof data === 'string') {
            return '"' + data + '"';
        }

        if (typeof data === 'object') {
            return JSON.stringify(data, null, 2);
        }

        return String(data);
    }

    /**
     * Recursively renders a typed PHP descriptor as plain text (var_dump-style).
     * Mirrors renderPhpNode() but produces plain text instead of HTML.
     *
     * @param {Object} node  - The typed descriptor node.
     * @param {number} depth - Current indentation depth.
     * @returns {string} Plain-text var_dump representation.
     */
    function renderPhpNodeText(node, depth) {
        if (!node || typeof node.type === 'undefined') {
            return JSON.stringify(node);
        }

        var pad = phpIndent(depth);
        var childPad = phpIndent(depth + 1);

        switch (node.type) {
            case 'null':
                return 'null';
            case 'bool':
                return 'bool(' + (node.value ? 'true' : 'false') + ')';
            case 'int':
                return 'int(' + String(node.value) + ')';
            case 'float':
                return 'float(' + String(node.value) + ')';
            case 'string':
                return 'string(' + String(node.length) + ') "' + String(node.value) + '"';
            case 'resource':
                return 'resource(' + String(node.value) + ')';
            case 'truncated':
                return '*DEPTH LIMIT REACHED*';
            case 'unknown':
                return String(node.value || '');

            case 'array':
                if (node.length === 0) return 'array(0) {}';
                return renderPhpCollectionText('array(' + String(node.length) + ')', node.value, pad, childPad, depth, false);

            case 'object':
                if (!node.value || node.value.length === 0) return 'object(' + (node.class || '') + ')(0) {}';
                return renderPhpCollectionText('object(' + (node.class || '') + ') (' + String(node.length) + ')', node.value, pad, childPad, depth, true);

            case 'exception':
                return renderPhpCollectionText(node.class || 'Exception', node.value, pad, childPad, depth, true);

            default:
                return JSON.stringify(node);
        }
    }

    /**
     * Renders an array/object collection as indented plain text.
     *
     * @param {string}  header         - Header line (e.g. "array(3)").
     * @param {Array}   items          - Array of {key, value} pairs.
     * @param {string}  pad            - Indentation for closing brace.
     * @param {string}  childPad       - Indentation for child entries.
     * @param {number}  depth          - Current depth.
     * @param {boolean} forceStringKeys - Whether to quote all keys.
     * @returns {string} Plain-text block.
     */
    function renderPhpCollectionText(header, items, pad, childPad, depth, forceStringKeys) {
        var text = header + ' {\n';

        items.forEach(function (item) {
            var key = item.key;
            var keyStr;

            if (!forceStringKeys && typeof key === 'number') {
                keyStr = '[' + String(key) + ']';
            } else {
                keyStr = '["' + String(key) + '"]';
            }

            text += childPad + keyStr + '=>\n';
            text += childPad + renderPhpNodeText(item.value, depth + 1) + '\n';
        });

        text += pad + '}';
        return text;
    }

    /**
     * Converts table data to a tab-separated values (TSV) string.
     *
     * @param {Object} data - Table payload with optional headers and rows array.
     * @returns {string} TSV-formatted string.
     */
    function extractTableTsv(data) {
        var rows = data.rows;
        var keySet = [];
        var keysSeen = new Set();

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

        var explicitHeaders = Array.isArray(data.headers) ? data.headers : null;
        var displayHeaders = keySet.map(function (key, index) {
            return (explicitHeaders && explicitHeaders[index] !== undefined)
                ? String(explicitHeaders[index])
                : key;
        });

        var lines = [displayHeaders.join('\t')];

        rows.forEach(function (row) {
            var cells = keySet.map(function (key) {
                var val = (row && typeof row === 'object') ? row[key] : '';
                if (val === null || val === undefined) return '';
                if (typeof val === 'object') return JSON.stringify(val);
                return String(val);
            });
            lines.push(cells.join('\t'));
        });

        return lines.join('\n');
    }

    /**
     * Builds a PHP-style access path segment for a given key.
     *
     * Numeric keys → [0], string keys → ['key'].
     *
     * @param {string|number} key - The array/object key.
     * @param {boolean} forceStringKeys - True if inside an object (always quote).
     * @returns {string} The bracket-notation segment.
     */
    function buildPathSegment(key, forceStringKeys) {
        if (!forceStringKeys && typeof key === 'number') {
            return '[' + String(key) + ']';
        }
        return "['" + String(key) + "']";
    }

    /**
     * Recursively renders a typed PHP descriptor node as PHP var_dump-style HTML.
     *
     * Expected node shapes (produced by Entry::buildTyped()):
     *   { type: 'null' }
     *   { type: 'bool',      value: boolean }
     *   { type: 'int',       value: number }
     *   { type: 'float',     value: number }
     *   { type: 'string',    length: number, value: string }
     *   { type: 'resource',  value: string }
     *   { type: 'array',     length: number, value: [{key, value},...] }
     *   { type: 'object',    class: string, length: number, value: [{key, value},...] }
     *   { type: 'exception', class: string, value: [{key, value},...] }
     *   { type: 'unknown',   value: string }
     *   { type: 'truncated' }
     *
     * @param {Object} node       - The typed descriptor node.
     * @param {number} depth      - Current indentation depth.
     * @param {string} parentPath - The accumulated PHP access path (e.g. "['data']").
     * @returns {string} HTML string for this node.
     */
    function renderPhpNode(node, depth, parentPath) {
        if (!node || typeof node.type === 'undefined') {
            return '<span class="php-unknown">' + escapeHtml(JSON.stringify(node)) + '</span>';
        }

        var pad = phpIndent(depth);
        var childPad = phpIndent(depth + 1);
        var path = parentPath || '';

        switch (node.type) {

            case 'null':
                return '<span class="php-null">null</span>';

            case 'bool':
                return (
                    '<span class="php-keyword">bool</span>' +
                    '<span class="php-paren">(</span>' +
                    '<span class="php-bool">' + (node.value ? 'true' : 'false') + '</span>' +
                    '<span class="php-paren">)</span>'
                );

            case 'int':
                return (
                    '<span class="php-keyword">int</span>' +
                    '<span class="php-paren">(</span>' +
                    '<span class="php-number">' + escapeHtml(String(node.value)) + '</span>' +
                    '<span class="php-paren">)</span>'
                );

            case 'float':
                return (
                    '<span class="php-keyword">float</span>' +
                    '<span class="php-paren">(</span>' +
                    '<span class="php-number">' + escapeHtml(String(node.value)) + '</span>' +
                    '<span class="php-paren">)</span>'
                );

            case 'string':
                return (
                    '<span class="php-keyword">string</span>' +
                    '<span class="php-paren">(</span>' +
                    '<span class="php-length">' + escapeHtml(String(node.length)) + '</span>' +
                    '<span class="php-paren">)</span> ' +
                    '<span class="php-string">&quot;' + escapeHtml(String(node.value)) + '&quot;</span>'
                );

            case 'resource':
                return (
                    '<span class="php-keyword">resource</span>' +
                    '<span class="php-paren">(</span>' +
                    '<span class="php-classname">' + escapeHtml(String(node.value)) + '</span>' +
                    '<span class="php-paren">)</span>'
                );

            case 'truncated':
                return '<span class="php-truncated">*DEPTH LIMIT REACHED*</span>';

            case 'unknown':
                return '<span class="php-unknown">' + escapeHtml(String(node.value || '')) + '</span>';

            case 'array':
                if (node.length === 0) {
                    return (
                        '<span class="php-keyword">array</span>' +
                        '<span class="php-paren">(</span>' +
                        '<span class="php-length">0</span>' +
                        '<span class="php-paren">)</span> ' +
                        '<span class="php-brace">{}</span>'
                    );
                }

                return renderPhpCollection(
                    '<span class="php-keyword">array</span>' +
                    '<span class="php-paren">(</span>' +
                    '<span class="php-length">' + escapeHtml(String(node.length)) + '</span>' +
                    '<span class="php-paren">)</span>',
                    node.value, pad, childPad, depth, false, path
                );

            case 'object':
                if (!node.value || node.value.length === 0) {
                    return (
                        '<span class="php-keyword">object</span>' +
                        '<span class="php-paren">(</span>' +
                        '<span class="php-classname">' + escapeHtml(node.class || '') + '</span>' +
                        '<span class="php-paren">)</span>' +
                        '<span class="php-paren">(</span>' +
                        '<span class="php-length">0</span>' +
                        '<span class="php-paren">)</span> ' +
                        '<span class="php-brace">{}</span>'
                    );
                }

                return renderPhpCollection(
                    '<span class="php-keyword">object</span>' +
                    '<span class="php-paren">(</span>' +
                    '<span class="php-classname">' + escapeHtml(node.class || '') + '</span>' +
                    '<span class="php-paren">)</span>' +
                    '<span class="php-dim"> (</span>' +
                    '<span class="php-length">' + escapeHtml(String(node.length)) + '</span>' +
                    '<span class="php-dim">)</span>',
                    node.value, pad, childPad, depth, true, path
                );

            case 'exception':
                return renderPhpCollection(
                    '<span class="php-exception">' + escapeHtml(node.class || 'Exception') + '</span>',
                    node.value, pad, childPad, depth, true, path
                );

            default:
                return '<span class="php-unknown">' + escapeHtml(JSON.stringify(node)) + '</span>';
        }
    }

    /**
     * Renders an array or object collection as an indented block.
     *
     * Each key is wrapped in a clickable span that shows the full PHP access
     * path as a tooltip and copies it to the clipboard on click.
     *
     * @param {string}  header         - Pre-built HTML for the type header line.
     * @param {Array}   items          - Array of {key, value} descriptor pairs.
     * @param {string}  pad            - Indentation for the closing brace.
     * @param {string}  childPad       - Indentation for child entries.
     * @param {number}  depth          - Current depth (passed to child nodes).
     * @param {boolean} forceStringKeys - If true, always quote keys (objects).
     * @param {string}  parentPath     - The accumulated PHP access path up to this level.
     * @returns {string} HTML string.
     */
    function renderPhpCollection(header, items, pad, childPad, depth, forceStringKeys, parentPath) {
        var html = header + ' <span class="php-brace">{</span>\n';
        var basePath = parentPath || '';

        items.forEach(function (item) {
            var key = item.key;
            var segment = buildPathSegment(key, forceStringKeys);
            var fullPath = basePath + segment;
            var keyHtml;

            if (!forceStringKeys && typeof key === 'number') {
                keyHtml = (
                    '<span class="php-access-path" data-path="' + escapeHtml(fullPath) + '" title="' + escapeHtml(fullPath) + '">' +
                    '<span class="php-bracket">[</span>' +
                    '<span class="php-number">' + escapeHtml(String(key)) + '</span>' +
                    '<span class="php-bracket">]</span>' +
                    '</span>'
                );
            } else {
                keyHtml = (
                    '<span class="php-access-path" data-path="' + escapeHtml(fullPath) + '" title="' + escapeHtml(fullPath) + '">' +
                    '<span class="php-bracket">[</span>' +
                    '<span class="php-key">&quot;' + escapeHtml(String(key)) + '&quot;</span>' +
                    '<span class="php-bracket">]</span>' +
                    '</span>'
                );
            }

            html += childPad + keyHtml + '<span class="php-arrow">=&gt;</span>';
            html += renderPhpNode(item.value, depth + 1, fullPath) + '\n';
        });

        html += pad + '<span class="php-brace">}</span>';
        return html;
    }

    /**
     * Returns an indentation string of two spaces per depth level.
     *
     * @param {number} depth - The indentation depth.
     * @returns {string} Spaces.
     */
    function phpIndent(depth) {
        var s = '';
        for (var i = 0; i < depth; i++) { s += '  '; }
        return s;
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
        html += '</tr></thead><tbody>';

        rows.forEach(function (row, rowIndex) {
            html += '<tr class="debug-table-row">';
            html += '<td class="debug-table-td debug-table-td--idx">' + (rowIndex + 1) + '</td>';
            keySet.forEach(function (key) {
                let val = (row && typeof row === 'object') ? row[key] : undefined;
                html += '<td class="debug-table-td">' + formatCell(val) + '</td>';
            });
            html += '</tr>';
        });

        html += '</tbody></table></div></div>';
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

    // ─── Session ID Copy ────────────────────────────────────

    dom.sessionId.addEventListener('click', function () {
        if (!sessionId) return;

        navigator.clipboard.writeText(sessionId).then(function () {
            dom.sessionId.classList.add('copied');
            setTimeout(function () {
                dom.sessionId.classList.remove('copied');
            }, 1400);
        });
    });

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

        let dataLabel = entry.type === 'table' ? 'Table' : 'Data';
        let dataContent = entry.type === 'table'
            ? formatTable(entry.data)
            : '<div class="detail-data">' + formatData(entry.data) + '</div>';

        let dataSection =
            '<div class="detail-section">' +
            '<div class="detail-label-row">' +
            '<div class="detail-label">' + escapeHtml(dataLabel) + '</div>' +
            '<button class="detail-copy-btn" id="detailCopyBtn" title="Copy value">' +
            '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>' +
            '<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>' +
            '</svg>' +
            '<span class="detail-copy-label">Copy</span>' +
            '</button>' +
            '</div>' +
            dataContent +
            '</div>';

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

        // Wire up copy button
        let copyBtn = document.getElementById('detailCopyBtn');
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                let text = extractCopyText(entry.data, entry.type || 'info');
                navigator.clipboard.writeText(text).then(function () {
                    let label = copyBtn.querySelector('.detail-copy-label');
                    if (label) {
                        label.textContent = 'Copied!';
                        setTimeout(function () {
                            label.textContent = 'Copy';
                        }, 1500);
                    }
                });
            });
        }
    }

    // ─── Filters ────────────────────────────────────────────

    /**
     * Determines whether a single log-entry element should be visible.
     *
     * OR logic between type and label:
     *   - Both 'all'        → visible
     *   - Only type set     → must match type
     *   - Only label set    → must match label
     *   - Both set          → must match type OR label
     * Search is always AND-combined on top.
     *
     * @param {HTMLElement} el
     * @returns {boolean}
     */
    function isEntryVisible(el) {
        const typeSelected = activeFilter !== 'all';
        const labelSelected = activeLabelFilter !== 'all';

        let matchesFilter;

        if (!typeSelected && !labelSelected) {
            matchesFilter = true;
        } else if (typeSelected && !labelSelected) {
            matchesFilter = el.dataset.type === activeFilter;
        } else if (!typeSelected && labelSelected) {
            matchesFilter = el.dataset.label === activeLabelFilter;
        } else {
            matchesFilter = el.dataset.type === activeFilter || el.dataset.label === activeLabelFilter;
        }

        const matchesSearch = !searchQuery || el.textContent.toLowerCase().includes(searchQuery);

        return matchesFilter && matchesSearch;
    }

    /**
     * Applies the active filters to all entries and updates the visible count.
     */
    function applyFilters() {
        let entries = dom.debugLog.querySelectorAll('.log-entry');
        let visible = 0;

        entries.forEach(function (entry) {
            let show = isEntryVisible(entry);
            entry.style.display = show ? '' : 'none';
            if (show) visible++;
        });
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
    }

    // ─── Event Listeners ────────────────────────────────────

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

    // Auto-clear toggle
    dom.autoClearBtn.addEventListener('click', function () {
        autoClear = !autoClear;
        localStorage.setItem('debugphp_autoclear', String(autoClear));
        updateAutoClearBtn();
    });

    // Clear
    document.getElementById('clearBtn').addEventListener('click', function () {
        clearSession();
        clearDomEntries();
        lastRequestId = null;
    });

    // New session
    document.getElementById('newSessionBtn').addEventListener('click', function () {
        clearDomEntries();
        clearMetrics();
        clearEnvironment();
        lastRequestId = null;
        startNewSession();
    });

    // Detail panel close
    document.getElementById('detailClose').addEventListener('click', function () {
        dom.detailPanel.classList.add('hidden');
        let prev = dom.debugLog.querySelector('.log-entry.selected');
        if (prev) prev.classList.remove('selected');
    });

    // ─── Delete Entry (event delegation) ─────────────────────

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
                let label = logEntry.dataset.label || '';

                stats.total = Math.max(0, stats.total - 1);
                if (type === 'error') stats.errors = Math.max(0, stats.errors - 1);
                if (type === 'sql') stats.sql = Math.max(0, stats.sql - 1);
                updateStats();

                unregisterType(type);
                if (label) unregisterLabel(label);

                if (logEntry.classList.contains('selected')) {
                    dom.detailPanel.classList.add('hidden');
                }

                setTimeout(function () {
                    logEntry.remove();

                    if (dom.debugLog.querySelectorAll('.log-entry').length === 0) {
                        dom.emptyState.style.display = 'flex';
                    }
                }, 300);
            } else {
                logEntry.classList.remove('is-deleting');
            }
        });
    });

    // ─── Editor Picker Events ────────────────────────────────

    document.getElementById('editorPickerBtn').addEventListener('click', function (e) {
        e.stopPropagation();
        let dropdown = document.getElementById('editorDropdown');
        let isVisible = dropdown.style.display !== 'none';
        dropdown.style.display = isVisible ? 'none' : 'block';
    });

    document.addEventListener('click', function (e) {
        let picker = document.getElementById('editorPicker');
        if (picker && !picker.contains(e.target)) closeEditorDropdown();
    });

    dom.debugLog.addEventListener('click', function (e) {
        if (activeEditor === 'none') return;

        let originEl = e.target.closest('.entry-origin');
        if (!originEl) return;

        e.stopPropagation();
        openInEditor(originEl.dataset.path || '', originEl.dataset.file || '', parseInt(originEl.dataset.line || '0', 10));
    });

    dom.detailBody.addEventListener('click', function (e) {
        let btn = e.target.closest('.detail-open-btn');
        if (!btn) return;
        openInEditor(btn.dataset.path || '', btn.dataset.file || '', parseInt(btn.dataset.line || '0', 10));
    });


    /**
     * Handles click on a .php-access-path element.
     * Copies the PHP access path to the clipboard and shows a brief tooltip.
     *
     * @param {Event} e - The click event.
     */
    function handleAccessPathClick(e) {
        let pathEl = e.target.closest('.php-access-path');
        if (!pathEl) return;

        e.stopPropagation();

        let accessPath = pathEl.dataset.path || '';
        if (!accessPath) return;

        navigator.clipboard.writeText(accessPath).then(function () {
            pathEl.classList.add('copied');
            setTimeout(function () {
                pathEl.classList.remove('copied');
            }, 1200);
        });
    }

    dom.debugLog.addEventListener('click', handleAccessPathClick);
    dom.detailBody.addEventListener('click', handleAccessPathClick);

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
            clearDomEntries();
            clearMetrics();
            lastRequestId = null;
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

    // ─── Detail Panel Resize ────────────────────────────────

    /**
     * The resize functionality for the detail panel.
     * Width is persisted in localStorage.
     */
    (function initDetailResize() {
        const detailPanel = dom.detailPanel;
        const resizeHandle = document.getElementById('detailResizeHandle');
        let isResizing = false;
        let startX = 0;
        let startWidth = 0;

        const savedWidth = localStorage.getItem('debugphp_detail_width');
        if (savedWidth) {
            detailPanel.style.width = savedWidth + 'px';
        }

        resizeHandle.addEventListener('mousedown', function (e) {
            isResizing = true;
            startX = e.clientX;
            startWidth = detailPanel.offsetWidth;
            resizeHandle.classList.add('resizing');
            detailPanel.classList.add('resizing');
            document.body.style.cursor = 'ew-resize';
            e.preventDefault();
        });

        document.addEventListener('mousemove', function (e) {
            if (!isResizing) return;

            const deltaX = startX - e.clientX;
            const newWidth = startWidth + deltaX;

            const minWidth = 280;
            const maxWidth = window.innerWidth * 0.6;

            if (newWidth >= minWidth && newWidth <= maxWidth) {
                detailPanel.style.width = newWidth + 'px';
            }
        });

        document.addEventListener('mouseup', function () {
            if (isResizing) {
                isResizing = false;
                resizeHandle.classList.remove('resizing');
                detailPanel.classList.remove('resizing');
                document.body.style.cursor = '';

                localStorage.setItem('debugphp_detail_width', detailPanel.offsetWidth);
            }
        });
    })();

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
        updateAutoClearBtn();

        let stored = loadSession();

        if (stored) {
            applySession(stored, false);
        } else {
            await startNewSession();
        }
    }

    init();
})();
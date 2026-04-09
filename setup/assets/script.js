/**
 * DebugPHP Dashboard
 *
 * (c) Leon Schmidt — MIT License
 * https://github.com/CallMeLeon167/debugphp-server
 */

(function () {
    'use strict';
    const BASE = window.__DEBUGPHP_BASE || '';

    let storageTested = false;

    /**
     * Collects and returns the current values of all configuration fields.
     *
     * @returns {{ storage_path: string, session_lifetime: string }}
     */
    function getFormData() {
        return {
            storage_path: document.getElementById('storagePath').value,
            session_lifetime: document.getElementById('sessionLifetime').value,
        };
    }

    /**
     * Displays an alert message inside the element with the given ID.
     *
     * @param {string} id      - The ID of the alert container element.
     * @param {string} type    - The alert type CSS class (e.g. 'success' or 'error').
     * @param {string} message - The message text to display.
     * @returns {void}
     */
    function showAlert(id, type, message) {
        let el = document.getElementById(id);
        el.className = 'alert ' + type + ' visible';
        el.textContent = message;
    }

    /**
     * Toggles the loading state of a button, disabling it and adding a visual indicator while active.
     *
     * @param {string}  btnId   - The ID of the button element.
     * @param {boolean} loading - Whether to enable or disable the loading state.
     * @returns {void}
     */
    function setLoading(btnId, loading) {
        let btn = document.getElementById(btnId);
        btn.disabled = loading;
        btn.classList.toggle('loading', loading);
    }

    /**
     * Updates the step indicator UI to reflect the current active step.
     * Dots before the active step are marked as done, the active step is highlighted,
     * and the connecting lines are updated accordingly.
     *
     * @param {number} step - The current step number (1–2).
     * @returns {void}
     */
    function setStep(step) {
        for (let i = 1; i <= 2; i++) {
            let dot = document.getElementById('stepDot' + i);
            dot.classList.remove('active', 'done');
            if (i < step) dot.classList.add('done');
            else if (i === step) dot.classList.add('active');
        }
        document.getElementById('stepLine1').classList.toggle('done', step > 1);
    }

    /**
     * Tests the storage directory using the current form values.
     * On success, enables the "Setup" button and marks the storage as tested.
     * On failure, disables the "Setup" button and displays an error alert.
     *
     * @returns {Promise<void>}
     */
    window.testStorage = async function () {
        setLoading('btnTest', true);
        try {
            let data = getFormData();
            data.action = 'test';
            let response = await fetch(BASE + '/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            let result = await response.json();
            if (result.success) {
                showAlert('alertTest', 'success', result.message);
                storageTested = true;
                document.getElementById('btnSetup').disabled = false;
            } else {
                showAlert('alertTest', 'error', result.message);
                storageTested = false;
                document.getElementById('btnSetup').disabled = true;
            }
        } catch (err) {
            showAlert('alertTest', 'error', 'Request failed: ' + err.message);
        }
        setLoading('btnTest', false);
    };

    /**
     * Saves the .env file and creates storage directories in one step.
     * Only proceeds if the storage has been successfully tested beforehand.
     *
     * @returns {Promise<void>}
     */
    window.runSetup = async function () {
        if (!storageTested) return;
        setLoading('btnSetup', true);
        try {
            let data = getFormData();

            // Step A: Save .env
            data.action = 'save_env';
            let envResponse = await fetch(BASE + '/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            let envResult = await envResponse.json();

            if (!envResult.success) {
                showAlert('alertTest', 'error', envResult.message);
                setLoading('btnSetup', false);
                return;
            }

            // Step B: Create directories
            data.action = 'setup';
            let setupResponse = await fetch(BASE + '/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            let setupResult = await setupResponse.json();

            if (setupResult.success) {
                document.getElementById('step1').style.display = 'none';
                document.getElementById('step2').style.display = 'block';
                setStep(2);
            } else {
                showAlert('alertTest', 'error', setupResult.message);
            }
        } catch (err) {
            showAlert('alertTest', 'error', 'Request failed: ' + err.message);
        }
        setLoading('btnSetup', false);
    };

    /**
     * Resets the storage tested state and disables the "Setup" button whenever
     * the storage path field is modified by the user.
     */
    document.querySelectorAll('#storagePath, #sessionLifetime').forEach(function (input) {
        input.addEventListener('input', function () {
            storageTested = false;
            document.getElementById('btnSetup').disabled = true;
            document.getElementById('alertTest').classList.remove('visible');
        });
    });
})();
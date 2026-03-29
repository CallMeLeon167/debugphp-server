(function () {
    'use strict';
    const BASE = window.__DEBUGPHP_BASE || '';

    let connectionTested = false;

    /**
     * Collects and returns the current values of all database and session configuration fields.
     *
     * @returns {{ db_host: string, db_port: string, db_database: string, db_username: string, db_password: string, session_lifetime: string }}
     */
    function getFormData() {
        return {
            db_host: document.getElementById('dbHost').value,
            db_port: document.getElementById('dbPort').value,
            db_database: document.getElementById('dbDatabase').value,
            db_username: document.getElementById('dbUsername').value,
            db_password: document.getElementById('dbPassword').value,
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
        if (loading) {
            btn.classList.add('loading');
            btn.disabled = true;
        } else {
            btn.classList.remove('loading');
            btn.disabled = false;
        }
    }

    /**
     * Updates the step indicator UI to reflect the current active step.
     * Dots before the active step are marked as done, the active step is highlighted,
     * and the connecting lines are updated accordingly.
     *
     * @param {number} step - The current step number (1–3).
     * @returns {void}
     */
    function setStep(step) {
        for (let i = 1; i <= 3; i++) {
            let dot = document.getElementById('stepDot' + i);
            dot.classList.remove('active', 'done');
            if (i < step) dot.classList.add('done');
            else if (i === step) dot.classList.add('active');
        }
        document.getElementById('stepLine1').classList.toggle('done', step > 1);
        document.getElementById('stepLine2').classList.toggle('done', step > 2);
    }

    /**
     * Tests the database connection using the current form values.
     * On success, enables the "Next" button and marks the connection as tested.
     * On failure, disables the "Next" button and displays an error alert.
     *
     * @returns {Promise<void>}
     */
    window.testConnection = async function () {
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
                connectionTested = true;
                document.getElementById('btnNext').disabled = false;
            } else {
                showAlert('alertTest', 'error', result.message);
                connectionTested = false;
                document.getElementById('btnNext').disabled = true;
            }
        } catch (err) {
            showAlert('alertTest', 'error', 'Request failed: ' + err.message);
        }
        setLoading('btnTest', false);
    };

    /**
     * Saves the current configuration as a .env file and advances to step 2.
     * Only proceeds if the connection has been successfully tested beforehand.
     * Displays an error alert if the request fails or the server returns an error.
     *
     * @returns {Promise<void>}
     */
    window.saveEnvAndNext = async function () {
        if (!connectionTested) return;
        setLoading('btnNext', true);
        try {
            let data = getFormData();
            data.action = 'save_env';
            let response = await fetch(BASE + '/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            let result = await response.json();
            if (result.success) {
                document.getElementById('step1').style.display = 'none';
                document.getElementById('step2').style.display = 'block';
                setStep(2);
            } else {
                showAlert('alertTest', 'error', result.message);
            }
        } catch (err) {
            showAlert('alertTest', 'error', 'Request failed: ' + err.message);
        }
        setLoading('btnNext', false);
    };

    /**
     * Triggers the database migration/setup routine on the server and advances to step 3 on success.
     * Displays an error alert if the request fails or the server returns an error.
     *
     * @returns {Promise<void>}
     */
    window.runSetup = async function () {
        setLoading('btnSetup', true);
        try {
            let data = getFormData();
            data.action = 'setup';
            let response = await fetch(BASE + '/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            let result = await response.json();
            if (result.success) {
                document.getElementById('step2').style.display = 'none';
                document.getElementById('step3').style.display = 'block';
                setStep(3);
            } else {
                showAlert('alertSetup', 'error', result.message);
            }
        } catch (err) {
            showAlert('alertSetup', 'error', 'Request failed: ' + err.message);
        }
        setLoading('btnSetup', false);
    };

    /**
     * Navigates back from step 2 to step 1 by toggling the visibility of the respective step containers.
     *
     * @returns {void}
     */
    window.goBack = function () {
        document.getElementById('step2').style.display = 'none';
        document.getElementById('step1').style.display = 'block';
        setStep(1);
    };

    /**
     * Resets the connection tested state and disables the "Next" button whenever
     * any of the database credential fields are modified by the user.
     */
    document.querySelectorAll('#dbHost, #dbPort, #dbDatabase, #dbUsername, #dbPassword').forEach(function (input) {
        input.addEventListener('input', function () {
            connectionTested = false;
            document.getElementById('btnNext').disabled = true;
            document.getElementById('alertTest').classList.remove('visible');
        });
    });
})();
(function () {
    'use strict';

    var connectionTested = false;

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

    function showAlert(id, type, message) {
        var el = document.getElementById(id);
        el.className = 'alert ' + type + ' visible';
        el.textContent = message;
    }

    function setLoading(btnId, loading) {
        var btn = document.getElementById(btnId);
        if (loading) {
            btn.classList.add('loading');
            btn.disabled = true;
        } else {
            btn.classList.remove('loading');
            btn.disabled = false;
        }
    }

    function setStep(step) {
        for (var i = 1; i <= 3; i++) {
            var dot = document.getElementById('stepDot' + i);
            dot.classList.remove('active', 'done');
            if (i < step) dot.classList.add('done');
            else if (i === step) dot.classList.add('active');
        }
        document.getElementById('stepLine1').classList.toggle('done', step > 1);
        document.getElementById('stepLine2').classList.toggle('done', step > 2);
    }

    window.testConnection = async function () {
        setLoading('btnTest', true);
        try {
            var data = getFormData();
            data.action = 'test';
            var response = await fetch('/setup/', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data),
            });
            var result = await response.json();
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

    window.saveEnvAndNext = async function () {
        if (!connectionTested) return;
        setLoading('btnNext', true);
        try {
            var data = getFormData();
            data.action = 'save_env';
            var response = await fetch('/setup/', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data),
            });
            var result = await response.json();
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

    window.runSetup = async function () {
        setLoading('btnSetup', true);
        try {
            var data = getFormData();
            data.action = 'setup';
            var response = await fetch('/setup/', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data),
            });
            var result = await response.json();
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

    window.goBack = function () {
        document.getElementById('step2').style.display = 'none';
        document.getElementById('step1').style.display = 'block';
        setStep(1);
    };

    document.querySelectorAll('.form-input').forEach(function (input) {
        input.addEventListener('input', function () {
            connectionTested = false;
            document.getElementById('btnNext').disabled = true;
            document.getElementById('alertTest').classList.remove('visible');
        });
    });
})();
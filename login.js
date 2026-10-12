/*
 * login.js
 * Connect your login form to login.php.
 *
 * Required form fields:
 *   name="identifier"
 *   name="password"
 */

(function () {
    'use strict';

    const form = document.getElementById('loginForm');

    if (!form) {
        return;
    }

    const message = document.getElementById('loginMessage');
    const submit = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (message) {
            message.textContent = '';
            message.className = '';
        }

        if (submit) {
            submit.disabled = true;
        }

        try {
            const response = await fetch('login.php', {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(
                    data.message || 'Login failed.'
                );
            }

            window.location.href =
                data.redirect || 'dashboard.html';

        } catch (error) {

            if (message) {
                message.textContent = error.message;
                message.className = 'error-message';
            }

            if (submit) {
                submit.disabled = false;
            }
        }
    });

})();

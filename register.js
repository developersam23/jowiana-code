/*
 * register.js
 * Connect your registration form to register.php.
 *
 * Required form fields:
 *   name="full_name"
 *   name="phone"
 *   name="email"
 *   name="password"
 *   name="confirm_password"
 *
 * Optional honeypot:
 *   name="website"
 */

(function () {
    'use strict';

    const form = document.getElementById('registerForm');

    if (!form) {
        return;
    }

    const message =
        document.getElementById('registerMessage');

    const submit =
        form.querySelector('button[type="submit"]');

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

            const response =
                await fetch('register.php', {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                });

            const data =
                await response.json();

            if (!response.ok || !data.success) {
                throw new Error(
                    data.message ||
                    'Registration failed.'
                );
            }

            window.location.href =
                data.redirect ||
                'dashboard.html';

        } catch (error) {

            if (message) {
                message.textContent =
                    error.message;

                message.className =
                    'error-message';
            }

            if (submit) {
                submit.disabled = false;
            }
        }
    });

})();

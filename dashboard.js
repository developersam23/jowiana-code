/*
 * dashboard.js
 *
 * No rider name is stored in this file.
 * The logged-in rider is loaded from me.php.
 */

(function () {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const all = (selector) => document.querySelectorAll(selector);

    /* ---------------- Helpers ---------------- */

    function setText(id, value) {
        const element = $(id);
        if (element) {
            element.textContent = value;
        }
    }

    function setAll(selector, value) {
        all(selector).forEach(function (element) {
            element.textContent = value;
        });
    }

    function getInitials(name) {
        const parts = String(name).trim().split(/\s+/).filter(Boolean);

        if (!parts.length) {
            return 'R';
        }

        return parts
            .map(function (part) { return part.charAt(0); })
            .join('')
            .substring(0, 2)
            .toUpperCase();
    }

    function getGreeting(hour) {
        if (hour < 12) { return 'Good morning'; }
        if (hour < 17) { return 'Good afternoon'; }
        return 'Good evening';
    }

    const DOC_LABELS = { photo: 'Profile photo', id_card: 'ID card', certificate: 'Certificate' };

    function renderDocs(container, documents) {
        if (!container) {
            return;
        }

        if (!documents || !documents.length) {
            showEmpty(container, 'No documents uploaded yet.', true);
            return;
        }

        container.innerHTML = '';

        documents.forEach(function (doc) {
            const item = document.createElement('li');
            item.className = 'dash-doc';

            const link = document.createElement('a');
            link.href = 'file.php?id=' + encodeURIComponent(doc.id);
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = doc.title || doc.original_name;

            const kind = document.createElement('span');
            kind.textContent = DOC_LABELS[doc.doc_type] || 'Document';

            item.appendChild(link);
            item.appendChild(kind);
            container.appendChild(item);
        });
    }

    function showEmpty(container, message, listItem) {
        if (!container) {
            return;
        }

        container.innerHTML = '';

        const element = document.createElement(listItem ? 'li' : 'p');
        element.className = 'empty-state';
        element.textContent = message;

        container.appendChild(element);
    }

    /* ---------------- Mobile sidebar ---------------- */

    const sidebar = $('sidebar');
    const overlay = $('sidebarOverlay');
    const toggle = $('sidebarToggle');

    function setSidebar(open) {
        if (!sidebar || !overlay || !toggle) {
            return;
        }

        sidebar.classList.toggle('open', open);
        overlay.classList.toggle('hidden', !open);
        toggle.setAttribute('aria-expanded', String(open));
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            setSidebar(!sidebar.classList.contains('open'));
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function () {
            setSidebar(false);
        });
    }

    /* ---------------- User dropdown ---------------- */

    const userBtn = $('userBtn');
    const dropdown = $('userDropdown');

    if (userBtn && dropdown) {
        userBtn.addEventListener('click', function (event) {
            event.stopPropagation();

            const opening = dropdown.classList.contains('hidden');

            dropdown.classList.toggle('hidden', !opening);
            userBtn.setAttribute('aria-expanded', String(opening));
        });

        document.addEventListener('click', function () {
            dropdown.classList.add('hidden');
            userBtn.setAttribute('aria-expanded', 'false');
        });
    }

    /* ---------------- Mark notifications read ---------------- */

    const markRead = $('markRead');

    if (markRead) {
        markRead.addEventListener('click', function () {
            all('#notifList .unread').forEach(function (item) {
                item.classList.remove('unread');
            });

            const badge = $('bellBadge');
            if (badge) {
                badge.classList.add('hidden');
            }
        });
    }

    /* ---------------- Render rider ---------------- */

    function render(rider) {
        const fullName = String(
            rider.full_name || rider.fullName || rider.name || 'Rider'
        ).trim();

        const firstName = fullName.split(/\s+/)[0];
        const riderId = rider.rider_id || '-';

        /* Dynamic identity: name, first name and rider ID everywhere. */
        setAll('[data-rider-name]', fullName);
        setAll('[data-rider-first]', firstName);
        setAll('[data-rider-id]', riderId);

        /* Avatars (the header and sidebar circles show the initials). */
        const initials = getInitials(fullName);
        all('.user-avatar, .rider-photo').forEach(function (element) {
            element.textContent = '';

            if (rider.photo_url) {
                const photo = document.createElement('img');
                photo.src = rider.photo_url;
                photo.alt = '';
                element.appendChild(photo);
            } else {
                element.textContent = initials;
            }
        });

        setText('greeting', getGreeting(new Date().getHours()));
        document.title = 'Dashboard - ' + fullName + ' | Jowaina Son';

        /* ---------------- Verification ---------------- */

        const verified = rider.status === 'verified' || rider.status === 'approved';
        const badge = $('verifyBadge');

        if (badge) {
            badge.className = 'verify-badge ' + (verified ? 'verified' : 'pending');

            const icon = badge.querySelector('i');
            if (icon) {
                icon.className = verified
                    ? 'fa-solid fa-circle-check'
                    : 'fa-solid fa-hourglass-half';
            }
        }

        setText('verifyText', verified ? 'Verified rider' : 'Verification pending');

        /* ---------------- Completion ---------------- */

        const completion = Math.max(0, Math.min(100, Number(rider.completion) || 0));

        setText('progressPercent', completion + '%');
        setText('statProfile', completion + '%');

        const fill = $('progressFill');
        if (fill) {
            fill.style.width = completion + '%';
        }

        const track = $('progressTrack');
        if (track) {
            track.setAttribute('aria-valuenow', String(completion));
        }

        /* ---------------- Statistics ---------------- */

        const stats = rider.stats || {};

        setText('statDocs', Number(stats.documents) || 0);
        setText('statTrainings', Number(stats.trainings) || 0);
        setText('statOpps', Number(stats.opportunities) || 0);

        /* ---------------- Lists ---------------- */

        showEmpty(
            $('oppList'),
            'No opportunities yet. Complete your profile to get matched.'
        );

        renderDocs($('docList'), rider.documents);

        /* ---------------- Next step button ---------------- */

        const nextBtn = $('nextStepBtn');
        const nextText = $('nextStepText');

        if (nextBtn && nextText) {
            if (!rider.education_done) {
                nextBtn.href = 'education.html';
                nextText.textContent = 'Complete Education & Qualifications';
            } else if (!rider.documents_done) {
                nextBtn.href = 'documents.html';
                nextText.textContent = 'Upload your photo & ID card';
            } else {
                nextBtn.href = 'profile.html';
                nextText.textContent = 'Review your profile';
            }
        }

        /* ---------------- Notification ---------------- */

        const notificationList = $('notifList');

        if (notificationList) {
            notificationList.innerHTML = '';

            const notification = document.createElement('li');
            notification.className = 'notif unread';
            notification.textContent =
                'Welcome to the community, ' + firstName +
                '! Complete your profile to get verified.';

            notificationList.appendChild(notification);
        }

        const bell = $('bellBadge');
        if (bell) {
            bell.textContent = '1';
            bell.classList.remove('hidden');
        }
    }

    /* ---------------- Load the logged-in rider ---------------- */

    fetch('me.php', {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
    })
    .then(function (response) {
        if (response.status === 401) {
            window.location.href = 'login.html';
            return null;
        }

        /* Read as text first so a PHP error page gives a clear message. */
        return response.text().then(function (text) {
            let data;

            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error(
                    'me.php did not return JSON (HTTP ' + response.status +
                    '). It started with: ' + text.slice(0, 160)
                );
            }

            if (!response.ok || !data.success) {
                const detail = data.debug ? ' (' + data.debug + ')' : '';
                throw new Error(
                    (data.message || 'Unable to load rider account.') + detail
                );
            }

            return data;
        });
    })
    .then(function (data) {
        if (!data) {
            return;
        }

        /* Works with the nested "rider" object and with the flat format. */
        const rider = Object.assign({}, data, data.rider || {});

        if (!rider.full_name) {
            throw new Error('me.php answered, but it did not include full_name.');
        }

        render(rider);
    })
    .catch(function (error) {
        console.error('Dashboard error:', error);
        setText('progressText', 'Could not load your account details: ' + error.message);
    });

})();
/* ==========================================================================
   Documents page
   - shows the logged-in rider's name (from me.php)
   - uploads the profile photo, ID card and any number of certificates
   - lists, previews and removes them (documents.php + file.php)
   ========================================================================== */

(async function () {
    'use strict';

    var LOGIN_PAGE = 'login.html';
    var MAX_BYTES = 5 * 1024 * 1024;
    var IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    var DOC_TYPES = IMAGE_TYPES.concat(['application/pdf']);

    var $ = function (id) { return document.getElementById(id); };

    var csrf = '';
    var limits = { photo: 1, id_card: 2, certificate: 30 };
    var docs = [];
    var busy = false;

    /* ======================================================================
       WHO IS LOGGED IN?
       ====================================================================== */
    try {
        var meRes = await fetch('me.php', { credentials: 'same-origin', cache: 'no-store' });
        if (meRes.status === 401) {
            window.location.href = LOGIN_PAGE;
            return;
        }
        var me = await meRes.json();
        if (me && me.success) {
            document.querySelectorAll('[data-rider-name]').forEach(function (n) { n.textContent = me.full_name; });
            document.querySelectorAll('[data-rider-id]').forEach(function (n) { n.textContent = me.rider_id || '-'; });
        }
    } catch (err) {
        console.warn('Could not load the logged-in rider from me.php:', err);
    }

    /* ======================================================================
       MENU, SIDEBAR AND USER DROPDOWN
       ====================================================================== */
    var sidebar = $('sidebar');
    var overlay = $('sidebarOverlay');
    var toggleBtn = $('sidebarToggle');

    function setSidebar(open) {
        sidebar.classList.toggle('open', open);
        overlay.classList.toggle('hidden', !open);
        toggleBtn.setAttribute('aria-expanded', String(open));
        toggleBtn.querySelector('i').className = open ? 'fa-solid fa-xmark' : 'fa-solid fa-bars';
        document.body.style.overflow = open ? 'hidden' : '';
    }
    toggleBtn.addEventListener('click', function () { setSidebar(!sidebar.classList.contains('open')); });
    overlay.addEventListener('click', function () { setSidebar(false); });
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 1100 && sidebar.classList.contains('open')) setSidebar(false);
    });

    var userBtn = $('userBtn');
    var userDropdown = $('userDropdown');
    function setDropdown(open) {
        userDropdown.classList.toggle('hidden', !open);
        userBtn.setAttribute('aria-expanded', String(open));
    }
    userBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        setDropdown(userDropdown.classList.contains('hidden'));
    });
    document.addEventListener('click', function () { setDropdown(false); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { setDropdown(false); setSidebar(false); }
    });

    /* ======================================================================
       HELPERS
       ====================================================================== */
    var toastTimer;
    function showToast(message, isError) {
        var toast = $('toast');
        $('toastMsg').textContent = message;
        toast.classList.toggle('error', !!isError);
        toast.classList.remove('hidden');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toast.classList.add('hidden'); }, isError ? 5000 : 3000);
    }

    function showAlert(message) {
        var box = $('pageAlert');
        box.textContent = message || '';
        box.classList.toggle('hidden', !message);
    }

    function fmtSize(bytes) {
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        return Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }

    function fmtDate(value) {
        var d = new Date(String(value).replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function ofType(type) {
        return docs.filter(function (d) { return d.doc_type === type; });
    }

    function fileUrl(id, download) {
        return 'file.php?id=' + encodeURIComponent(id) + (download ? '&download=1' : '');
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function icon(classes) {
        var i = document.createElement('i');
        i.className = classes;
        return i;
    }

    // Replace the header and sidebar avatars with the rider's photo
    function setAvatars(photo) {
        document.querySelectorAll('.user-avatar, .rider-photo').forEach(function (box) {
            box.textContent = '';
            if (photo) {
                var img = document.createElement('img');
                img.src = fileUrl(photo.id);
                img.alt = '';
                box.appendChild(img);
            } else {
                box.appendChild(icon('fa-solid fa-user'));
            }
        });
    }

    /* ======================================================================
       LOAD + DRAW
       ====================================================================== */
    async function load() {
        try {
            var res = await fetch('documents.php', { credentials: 'same-origin', cache: 'no-store' });
            if (res.status === 401) {
                window.location.href = LOGIN_PAGE;
                return false;
            }
            var data = await res.json();
            if (!data.success) {
                showAlert(data.message || 'Could not load your documents.');
                return false;
            }
            csrf = data.csrf_token || '';
            limits = data.limits || limits;
            docs = data.documents || [];
            showAlert('');
            return true;
        } catch (err) {
            showAlert('Could not reach the server. Please refresh the page.');
            return false;
        }
    }

    function docRow(d) {
        var row = el('div', 'doc-item');

        var thumb = el('div', 'doc-thumb');
        if (d.mime_type === 'application/pdf') {
            thumb.appendChild(icon('fa-solid fa-file-pdf'));
        } else {
            var img = document.createElement('img');
            img.src = fileUrl(d.id);
            img.alt = '';
            img.loading = 'lazy';
            thumb.appendChild(img);
        }

        var info = el('div', 'doc-info');
        info.appendChild(el('div', 'doc-title', d.title || d.original_name));
        info.appendChild(el('div', 'doc-meta', d.original_name + ' · ' + fmtSize(Number(d.size_bytes)) + ' · ' + fmtDate(d.uploaded_at)));

        var actions = el('div', 'doc-actions');
        var view = document.createElement('a');
        view.href = fileUrl(d.id);
        view.target = '_blank';
        view.rel = 'noopener';
        view.setAttribute('aria-label', 'View ' + (d.title || d.original_name));
        view.appendChild(icon('fa-regular fa-eye'));

        var del = document.createElement('button');
        del.type = 'button';
        del.setAttribute('aria-label', 'Remove ' + (d.title || d.original_name));
        del.dataset.remove = d.id;
        del.appendChild(icon('fa-solid fa-trash-can'));

        actions.appendChild(view);
        actions.appendChild(del);

        row.appendChild(thumb);
        row.appendChild(info);
        row.appendChild(actions);
        return row;
    }

    function fillList(box, items, emptyText) {
        box.textContent = '';
        if (!items.length) {
            box.appendChild(el('div', 'doc-empty', emptyText));
            return;
        }
        items.forEach(function (d) { box.appendChild(docRow(d)); });
    }

    function setCheck(id, done, text) {
        var li = $(id);
        li.classList.toggle('done', done);
        li.querySelector('i').className = done ? 'fa-solid fa-circle-check' : 'fa-regular fa-circle';
        if (text) li.querySelector('span').textContent = text;
    }

    function draw() {
        var photo = ofType('photo')[0] || null;
        var ids = ofType('id_card');
        var certs = ofType('certificate');

        // Photo
        var pv = $('photoPreview');
        pv.textContent = '';
        if (photo) {
            var img = document.createElement('img');
            img.src = fileUrl(photo.id);
            img.alt = 'Your profile photo';
            pv.appendChild(img);
        } else {
            pv.appendChild(icon('fa-solid fa-user'));
        }
        $('photoBtnText').textContent = photo ? 'Change photo' : 'Choose photo';
        setAvatars(photo);

        // ID card + certificates
        fillList($('idList'), ids, 'No ID card uploaded yet.');
        fillList($('certList'), certs, 'No certificates uploaded yet.');
        $('idBtn').classList.toggle('hidden', ids.length >= limits.id_card);

        // Checklist
        setCheck('chkPhoto', !!photo);
        setCheck('chkId', ids.length > 0);
        setCheck('chkCert', certs.length > 0,
            certs.length ? 'Certificates (' + certs.length + ' uploaded)' : 'Certificates (optional)');
    }

    /* ======================================================================
       UPLOAD + REMOVE
       ====================================================================== */
    // Checks files in the browser first so riders get quick feedback.
    // The server checks everything again.
    function precheck(files, allowed) {
        for (var i = 0; i < files.length; i++) {
            var f = files[i];
            if (allowed.indexOf(f.type) === -1) {
                return f.name + ': ' + (allowed === IMAGE_TYPES
                    ? 'please choose a JPG, PNG or WEBP image.'
                    : 'please choose a JPG, PNG, WEBP or PDF file.');
            }
            if (f.size > MAX_BYTES) return f.name + ': file is larger than 5 MB.';
            if (f.size === 0) return f.name + ': file is empty.';
        }
        return '';
    }

    async function upload(type, fileList, title, button) {
        if (busy) return;
        var files = Array.prototype.slice.call(fileList);
        if (!files.length) return;

        var allowed = type === 'photo' ? IMAGE_TYPES : DOC_TYPES;
        var problem = precheck(files, allowed);
        if (problem) { showToast(problem, true); return; }

        var fd = new FormData();
        fd.append('action', 'upload');
        fd.append('doc_type', type);
        if (title) fd.append('title', title);
        files.forEach(function (f) { fd.append('files[]', f); });

        busy = true;
        if (button) button.disabled = true;
        showToast('Uploading...');

        try {
            var res = await fetch('documents.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': csrf },
                body: fd
            });
            if (res.status === 401) { window.location.href = LOGIN_PAGE; return; }

            var data;
            try { data = await res.json(); } catch (e) { data = null; }

            if (!data) {
                showToast('The server could not accept the upload. Try a smaller file.', true);
            } else if (!data.success) {
                showToast(data.message || 'Upload failed.', true);
            } else {
                showToast(data.message);
                if (data.errors && data.errors.length) showAlert(data.errors.join(' '));
                else showAlert('');
            }
        } catch (err) {
            showToast('Could not reach the server. Please try again.', true);
        }

        busy = false;
        if (button) button.disabled = false;
        if (await load()) draw();
    }

    async function removeDoc(id) {
        if (busy) return;
        if (!window.confirm('Remove this document?')) return;
        busy = true;

        var fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);

        try {
            var res = await fetch('documents.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': csrf },
                body: fd
            });
            var data = await res.json();
            showToast(data.message || (data.success ? 'Removed.' : 'Could not remove.'), !data.success);
        } catch (err) {
            showToast('Could not reach the server. Please try again.', true);
        }

        busy = false;
        if (await load()) draw();
    }

    /* ---------- Wire up the buttons ---------- */
    function bindPicker(buttonId, inputId, type, getTitle) {
        var btn = $(buttonId);
        var input = $(inputId);
        btn.addEventListener('click', function () { if (!busy) input.click(); });
        input.addEventListener('change', function () {
            var chosen = input.files;
            // Copy the list first, because clearing the input empties it
            var copy = Array.prototype.slice.call(chosen);
            input.value = '';
            upload(type, copy, getTitle ? getTitle() : '', btn);
        });
    }

    bindPicker('photoBtn', 'photoInput', 'photo');
    bindPicker('idBtn', 'idInput', 'id_card');
    bindPicker('certBtn', 'certInput', 'certificate', function () { return $('certTitle').value.trim(); });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-remove]');
        if (btn) removeDoc(btn.getAttribute('data-remove'));
    });

    /* ---------- Start ---------- */
    if (await load()) draw();
})();
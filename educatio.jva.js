/* ==========================================================================
   Education & Qualifications page
   - menu / sidebar / dropdown
   - fills the County and Year lists
   - add / remove training blocks
   - character counter
   - validation, saving and the profile-completion bar
   ========================================================================== */

(function () {
    'use strict';

    /* ---------- Settings you may want to change ---------- */
    var NEXT_PAGE = 'training.html';      // page opened after "Save & Continue" / "Skip for now"
    var STORAGE_KEY = 'jowainaEducation'; // where the form is saved in this browser
    var BASE_COMPLETION = 40;             // % already complete before this section is filled

    var COUNTIES = [
        'Baringo', 'Bomet', 'Bungoma', 'Busia', 'Elgeyo-Marakwet', 'Embu', 'Garissa', 'Homa Bay',
        'Isiolo', 'Kajiado', 'Kakamega', 'Kericho', 'Kiambu', 'Kilifi', 'Kirinyaga', 'Kisii',
        'Kisumu', 'Kitui', 'Kwale', 'Laikipia', 'Lamu', 'Machakos', 'Makueni', 'Mandera',
        'Marsabit', 'Meru', 'Migori', 'Mombasa', "Murang'a", 'Nairobi', 'Nakuru', 'Nandi',
        'Narok', 'Nyamira', 'Nyandarua', 'Nyeri', 'Samburu', 'Siaya', 'Taita-Taveta', 'Tana River',
        'Tharaka-Nithi', 'Trans Nzoia', 'Turkana', 'Uasin Gishu', 'Vihiga', 'Wajir', 'West Pokot'
    ];

    /* ---------- Shortcuts ---------- */
    var $ = function (id) { return document.getElementById(id); };
    var form = $('educationForm');

    /* ======================================================================
       1. MENU, SIDEBAR AND USER DROPDOWN
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

    toggleBtn.addEventListener('click', function () {
        setSidebar(!sidebar.classList.contains('open'));
    });
    overlay.addEventListener('click', function () { setSidebar(false); });

    // Close the mobile menu automatically when the screen grows to desktop size
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
       2. FILL THE COUNTY AND YEAR LISTS
       ====================================================================== */
    var locationSelect = $('location');
    COUNTIES.forEach(function (c) {
        var o = document.createElement('option');
        o.textContent = c + ' County';
        locationSelect.appendChild(o);
    });

    var yearSelect = $('yearDone');
    var thisYear = new Date().getFullYear();
    for (var y = thisYear + 4; y >= 1970; y--) {
        var opt = document.createElement('option');
        opt.textContent = y;
        yearSelect.appendChild(opt);
    }

    // Do not allow future dates for completed training
    function limitDates(scope) {
        var today = new Date().toISOString().split('T')[0];
        scope.querySelectorAll('.t-date').forEach(function (d) { d.max = today; });
    }
    limitDates(document);

    /* ======================================================================
       3. ADD / REMOVE TRAINING BLOCKS
       ====================================================================== */
    var trainingList = $('trainingList');
    var template = $('trainingTemplate');

    $('addTraining').addEventListener('click', function () {
        var clone = template.content.cloneNode(true);
        limitDates(clone);
        trainingList.appendChild(clone);
        var blocks = trainingList.querySelectorAll('[data-training]');
        var last = blocks[blocks.length - 1];
        var first = last.querySelector('select');
        if (first) first.focus();
        updateProgress();
    });

    trainingList.addEventListener('click', function (e) {
        var btn = e.target.closest('.remove-training');
        if (!btn) return;
        btn.closest('[data-training]').remove();
        updateProgress();
    });

    /* ======================================================================
       4. CHARACTER COUNTER
       ====================================================================== */
    var otherQuals = $('otherQuals');
    var charCount = $('charCount');

    function updateCount() {
        charCount.textContent = otherQuals.value.length + '/200';
    }
    otherQuals.addEventListener('input', updateCount);

    /* ======================================================================
       5. VALIDATION
       ====================================================================== */
    function validateField(el) {
        var field = el.closest('.field');
        if (!field) return true;
        var err = field.querySelector('.field-error');
        var empty = el.required && !el.value.trim();
        field.classList.toggle('invalid', empty);
        if (err) err.textContent = empty ? 'This field is required.' : '';
        return !empty;
    }

    function validateAll() {
        var firstBad = null;
        form.querySelectorAll('[required]').forEach(function (el) {
            if (!validateField(el) && !firstBad) firstBad = el;
        });
        return firstBad;
    }

    // Clear the error as soon as the person fixes the field; keep progress live
    form.addEventListener('input', function (e) {
        if (e.target.required) validateField(e.target);
        updateProgress();
    });
    form.addEventListener('change', function (e) {
        if (e.target.required) validateField(e.target);
        updateProgress();
    });

    /* ======================================================================
       6. PROFILE COMPLETION BAR
       40% is already done elsewhere; filling the required fields on this
       page adds the remaining 60%.
       ====================================================================== */
    function updateProgress() {
        var required = form.querySelectorAll('[required]');
        var filled = 0;
        required.forEach(function (el) { if (el.value.trim()) filled++; });
        var ratio = required.length ? filled / required.length : 0;
        var percent = Math.round(BASE_COMPLETION + (100 - BASE_COMPLETION) * ratio);

        $('progressFill').style.width = percent + '%';
        $('progressPercent').textContent = percent + '%';
        $('progressTrack').setAttribute('aria-valuenow', percent);
        $('progressText').textContent = percent >= 100
            ? 'Great! Your education section is complete.'
            : 'Complete your education section to unlock more opportunities.';
    }

    /* ======================================================================
       7. SAVE / LOAD (stored in this browser only)
       When you build a backend, replace saveData() with a request to your
       server, for example fetch('/api/education', { method: 'POST', ... }).
       ====================================================================== */
    function collectData() {
        var trainings = [];
        trainingList.querySelectorAll('[data-training]').forEach(function (b) {
            trainings.push({
                type: b.querySelector('.t-type').value,
                institution: b.querySelector('.t-inst').value,
                certification: b.querySelector('.t-cert').value.trim(),
                date: b.querySelector('.t-date').value
            });
        });

        return {
            eduLevel: $('eduLevel').value,
            course: $('course').value.trim(),
            fieldOfStudy: $('fieldOfStudy').value.trim(),
            school: $('school').value.trim(),
            location: $('location').value,
            yearDone: $('yearDone').value,
            eduStatus: $('eduStatus').value,
            grade: $('grade').value.trim(),
            certName: $('certName').value.trim(),
            trainings: trainings,
            otherQuals: otherQuals.value.trim()
        };
    }

    function saveData() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(collectData()));
            return true;
        } catch (err) {
            return false; // storage blocked or full
        }
    }

    function loadData() {
        var data;
        try {
            data = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        } catch (err) {
            data = null;
        }
        if (!data) return;

        ['eduLevel', 'course', 'fieldOfStudy', 'school', 'location', 'yearDone', 'eduStatus', 'grade', 'certName']
            .forEach(function (id) { if (data[id]) $(id).value = data[id]; });

        otherQuals.value = data.otherQuals || '';

        var list = data.trainings || [];
        // make sure there are enough blocks
        while (trainingList.querySelectorAll('[data-training]').length < list.length) {
            var clone = template.content.cloneNode(true);
            limitDates(clone);
            trainingList.appendChild(clone);
        }
        var blocks = trainingList.querySelectorAll('[data-training]');
        list.forEach(function (t, i) {
            blocks[i].querySelector('.t-type').value = t.type || '';
            blocks[i].querySelector('.t-inst').value = t.institution || '';
            blocks[i].querySelector('.t-cert').value = t.certification || '';
            blocks[i].querySelector('.t-date').value = t.date || '';
        });
    }

    /* ======================================================================
       8. TOAST + BUTTONS
       ====================================================================== */
    var toastTimer;
    function showToast(message, isError) {
        var toast = $('toast');
        $('toastMsg').textContent = message;
        toast.classList.toggle('error', !!isError);
        toast.classList.remove('hidden');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toast.classList.add('hidden'); }, 3000);
    }

    function goNext() {
        setTimeout(function () { window.location.href = NEXT_PAGE; }, 1200);
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var bad = validateAll();
        if (bad) {
            bad.focus();
            showToast('Please fill in the required fields.', true);
            return;
        }
        if (saveData()) {
            showToast('Education details saved!');
            goNext();
        } else {
            showToast('Could not save in this browser.', true);
        }
    });

    $('skipBtn').addEventListener('click', function () {
        saveData(); // keep anything already typed
        window.location.href = NEXT_PAGE;
    });

    /* ---------- Start ---------- */
    loadData();
    updateCount();
    updateProgress();
})();
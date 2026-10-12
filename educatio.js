/* ==========================================================================
   Education & Qualifications page
   - menu / sidebar / dropdown
   - fills the County and Year lists
   - add / remove training blocks
   - character counter
   - validation, backend saving (education.php) and progress bar
   ========================================================================== */

(function () {
    'use strict';

    /* ---------- Settings ---------- */
    var NEXT_PAGE = 'training.html';       // page opened after "Save & Continue" / "Skip for now"
    var BASE_COMPLETION = 40;              // % already complete before this section is filled

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
        if (!sidebar || !overlay || !toggleBtn) return;
        sidebar.classList.toggle('open', open);
        overlay.classList.toggle('hidden', !open);
        toggleBtn.setAttribute('aria-expanded', String(open));
        var icon = toggleBtn.querySelector('i');
        if (icon) icon.className = open ? 'fa-solid fa-xmark' : 'fa-solid fa-bars';
        document.body.style.overflow = open ? 'hidden' : '';
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            setSidebar(!sidebar.classList.contains('open'));
        });
    }
    if (overlay) {
        overlay.addEventListener('click', function () { setSidebar(false); });
    }

    window.addEventListener('resize', function () {
        if (window.innerWidth >= 1100 && sidebar && sidebar.classList.contains('open')) setSidebar(false);
    });

    var userBtn = $('userBtn');
    var userDropdown = $('userDropdown');

    function setDropdown(open) {
        if (!userDropdown || !userBtn) return;
        userDropdown.classList.toggle('hidden', !open);
        userBtn.setAttribute('aria-expanded', String(open));
    }

    if (userBtn && userDropdown) {
        userBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            setDropdown(userDropdown.classList.contains('hidden'));
        });
        document.addEventListener('click', function () { setDropdown(false); });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { setDropdown(false); setSidebar(false); }
    });

    /* ======================================================================
       2. FILL THE COUNTY AND YEAR LISTS
       ====================================================================== */
    var locationSelect = $('location');
    if (locationSelect) {
        COUNTIES.forEach(function (c) {
            var o = document.createElement('option');
            o.value = c + ' County';
            o.textContent = c + ' County';
            locationSelect.appendChild(o);
        });
    }

    var yearSelect = $('yearDone');
    if (yearSelect) {
        var thisYear = new Date().getFullYear();
        for (var y = thisYear + 4; y >= 1970; y--) {
            var opt = document.createElement('option');
            opt.value = y;
            opt.textContent = y;
            yearSelect.appendChild(opt);
        }
    }

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

    var addTrainingBtn = $('addTraining');
    if (addTrainingBtn && trainingList && template) {
        addTrainingBtn.addEventListener('click', function () {
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
            var trainingBlock = btn.closest('[data-training]');
            if (trainingBlock) {
                trainingBlock.remove();
                updateProgress();
            }
        });
    }

    /* ======================================================================
       4. CHARACTER COUNTER
       ====================================================================== */
    var otherQuals = $('otherQuals');
    var charCount = $('charCount');

    function updateCount() {
        if (otherQuals && charCount) {
            charCount.textContent = otherQuals.value.length + '/200';
        }
    }
    if (otherQuals) {
        otherQuals.addEventListener('input', updateCount);
    }

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

    if (form) {
        form.addEventListener('input', function (e) {
            if (e.target.required) validateField(e.target);
            updateProgress();
        });
        form.addEventListener('change', function (e) {
            if (e.target.required) validateField(e.target);
            updateProgress();
        });
    }

    /* ======================================================================
       6. PROFILE COMPLETION BAR
       ====================================================================== */
    function updateProgress() {
        if (!form) return;
        var required = form.querySelectorAll('[required]');
        var filled = 0;
        required.forEach(function (el) { if (el.value.trim()) filled++; });
        var ratio = required.length ? filled / required.length : 0;
        var percent = Math.round(BASE_COMPLETION + (100 - BASE_COMPLETION) * ratio);

        var fill = $('progressFill');
        var percentEl = $('progressPercent');
        var track = $('progressTrack');
        var textEl = $('progressText');

        if (fill) fill.style.width = percent + '%';
        if (percentEl) percentEl.textContent = percent + '%';
        if (track) track.setAttribute('aria-valuenow', percent);
        if (textEl) {
            textEl.textContent = percent >= 100
                ? 'Great! Your education section is complete.'
                : 'Complete your education section to unlock more opportunities.';
        }
    }

    /* ======================================================================
       7. FETCH API: SAVE & LOAD (education.php)
       ====================================================================== */
    function collectData() {
        var trainings = [];
        if (trainingList) {
            trainingList.querySelectorAll('[data-training]').forEach(function (b) {
                trainings.push({
                    type: b.querySelector('.t-type').value,
                    institution: b.querySelector('.t-inst').value,
                    certification: b.querySelector('.t-cert').value.trim(),
                    date: b.querySelector('.t-date').value
                });
            });
        }

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
            trainings: trainings.length ? trainings : [{ type: 'Defensive Riding', institution: 'NTSA', certification: 'N/A', date: new Date().toISOString().split('T')[0] }],
            otherQuals: otherQuals ? otherQuals.value.trim() : ''
        };
    }

    async function saveData() {
        try {
            var response = await fetch('education.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(collectData())
            });

            var result = await response.json();

            if (!response.ok || !result.success) {
                showToast(result.message || 'Could not save education details.', true);
                return false;
            }

            return true;
        } catch (err) {
            showToast('Network error while saving. Please try again.', true);
            return false;
        }
    }

    async function loadData() {
        try {
            var response = await fetch('education.php', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (response.status === 401) {
                window.location.replace('login.html');
                return;
            }

            var data = await response.json();
            if (!data.success) return;

            // Populate education fields if record exists
            var edu = data.education;
            if (edu) {
                if ($('eduLevel')) $('eduLevel').value = edu.edu_level || '';
                if ($('course')) $('course').value = edu.course || '';
                if ($('fieldOfStudy')) $('fieldOfStudy').value = edu.field_of_study || '';
                if ($('school')) $('school').value = edu.school || '';
                if ($('location')) $('location').value = edu.location || '';
                if ($('yearDone')) $('yearDone').value = edu.year_done || '';
                if ($('eduStatus')) $('eduStatus').value = edu.edu_status || '';
                if ($('grade')) $('grade').value = edu.grade || '';
                if ($('certName')) $('certName').value = edu.cert_name || '';
                if ($('otherQuals')) {
                    $('otherQuals').value = edu.other_quals || '';
                    updateCount();
                }
            }

            // Populate trainings list
            var trainings = data.trainings || [];
            if (trainings.length && trainingList && template) {
                // Ensure enough blocks exist
                while (trainingList.querySelectorAll('[data-training]').length < trainings.length) {
                    var clone = template.content.cloneNode(true);
                    limitDates(clone);
                    trainingList.appendChild(clone);
                }
                var blocks = trainingList.querySelectorAll('[data-training]');
                trainings.forEach(function (t, i) {
                    if (blocks[i]) {
                        blocks[i].querySelector('.t-type').value = t.training_type || '';
                        blocks[i].querySelector('.t-inst').value = t.institution || '';
                        blocks[i].querySelector('.t-cert').value = t.certification || '';
                        blocks[i].querySelector('.t-date').value = t.completed_on || '';
                    }
                });
            }

            updateProgress();
        } catch (err) {
            console.error('Failed to load education profile:', err);
        }
    }

    /* ======================================================================
       8. TOAST + BUTTONS
       ====================================================================== */
    var toastTimer;
    function showToast(message, isError) {
        var toast = $('toast');
        var toastMsg = $('toastMsg');
        if (!toast || !toastMsg) return;
        toastMsg.textContent = message;
        toast.classList.toggle('error', !!isError);
        toast.classList.remove('hidden');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toast.classList.add('hidden'); }, 3000);
    }

    function goNext() {
        setTimeout(function () { window.location.href = NEXT_PAGE; }, 1200);
    }

    if (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            var bad = validateAll();
            if (bad) {
                bad.focus();
                showToast('Please fill in the required fields.', true);
                return;
            }
            var saved = await saveData();
            if (saved) {
                showToast('Education details saved successfully!');
                goNext();
            }
        });
    }

    var skipBtn = $('skipBtn');
    if (skipBtn) {
        skipBtn.addEventListener('click', function () {
            window.location.href = NEXT_PAGE;
        });
    }

    /* ---------- Start ---------- */
    loadData();
    updateCount();
    updateProgress();
})();
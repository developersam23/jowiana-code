
/* ==========================================================================
   Education & Qualifications page
   - Loads the logged-in rider from me.php
   - Menu, sidebar and dropdown
   - Fills County and Year lists
   - Add/remove training blocks
   - Character counter
   - Validation, saving and profile-completion bar
   ========================================================================== */

(async function () {
    'use strict';

    /* ---------- Settings ---------- */
    var LOGIN_PAGE = 'login.html';
    var NEXT_PAGE = 'documents.html';
    var BASE_COMPLETION = 20;
    var EDU_POINTS = 60;

    var COUNTIES = [
        'Baringo', 'Bomet', 'Bungoma', 'Busia', 'Elgeyo-Marakwet',
        'Embu', 'Garissa', 'Homa Bay', 'Isiolo', 'Kajiado',
        'Kakamega', 'Kericho', 'Kiambu', 'Kilifi', 'Kirinyaga',
        'Kisii', 'Kisumu', 'Kitui', 'Kwale', 'Laikipia', 'Lamu',
        'Machakos', 'Makueni', 'Mandera', 'Marsabit', 'Meru',
        'Migori', 'Mombasa', "Murang'a", 'Nairobi', 'Nakuru',
        'Nandi', 'Narok', 'Nyamira', 'Nyandarua', 'Nyeri',
        'Samburu', 'Siaya', 'Taita-Taveta', 'Tana River',
        'Tharaka-Nithi', 'Trans Nzoia', 'Turkana', 'Uasin Gishu',
        'Vihiga', 'Wajir', 'West Pokot'
    ];

    /* ---------- Load logged-in rider ---------- */
    try {
        var meResponse = await fetch('me.php', {
            credentials: 'same-origin',
            cache: 'no-store'
        });

        if (meResponse.status === 401) {
            window.location.href = LOGIN_PAGE;
            return;
        }

        var me = await meResponse.json();

        if (me && me.success) {
            document.querySelectorAll('[data-rider-name]').forEach(function (el) {
                el.textContent = me.full_name || 'Rider';
            });

            document.querySelectorAll('[data-rider-first]').forEach(function (el) {
                el.textContent = String(me.full_name || 'Rider').split(/\s+/)[0];
            });

            document.querySelectorAll('[data-rider-id]').forEach(function (el) {
                el.textContent = me.rider_id || '-';
            });

            if (typeof me.base_completion === 'number') {
                BASE_COMPLETION = me.base_completion;
            }
        }
    } catch (err) {
        console.warn('Could not load rider details from me.php:', err);
    }

    /* ---------- Shortcuts ---------- */
    var $ = function (id) {
        return document.getElementById(id);
    };

    var form = $('educationForm');
    var sidebar = $('sidebar');
    var overlay = $('sidebarOverlay');
    var toggleBtn = $('sidebarToggle');
    var userBtn = $('userBtn');
    var userDropdown = $('userDropdown');
    var locationSelect = $('location');
    var yearSelect = $('yearDone');
    var trainingList = $('trainingList');
    var template = $('trainingTemplate');
    var otherQuals = $('otherQuals');
    var charCount = $('charCount');

    /* ---------- Menu and sidebar ---------- */
    function setSidebar(open) {
        if (!sidebar || !overlay || !toggleBtn) return;

        sidebar.classList.toggle('open', open);
        overlay.classList.toggle('hidden', !open);
        toggleBtn.setAttribute('aria-expanded', String(open));

        var icon = toggleBtn.querySelector('i');
        if (icon) {
            icon.className = open
                ? 'fa-solid fa-xmark'
                : 'fa-solid fa-bars';
        }

        document.body.style.overflow = open ? 'hidden' : '';
    }

    if (toggleBtn && sidebar && overlay) {
        toggleBtn.addEventListener('click', function () {
            setSidebar(!sidebar.classList.contains('open'));
        });

        overlay.addEventListener('click', function () {
            setSidebar(false);
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth >= 1100 && sidebar.classList.contains('open')) {
                setSidebar(false);
            }
        });
    }

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

        document.addEventListener('click', function () {
            setDropdown(false);
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            setDropdown(false);
            setSidebar(false);
        }
    });

    /* ---------- Populate counties ---------- */
    if (locationSelect) {
        COUNTIES.forEach(function (county) {
            var option = document.createElement('option');
            option.value = county + ' County';
            option.textContent = county + ' County';
            locationSelect.appendChild(option);
        });
    }

    /* ---------- Populate years ---------- */
    if (yearSelect) {
        var thisYear = new Date().getFullYear();

        for (var y = thisYear + 4; y >= 1970; y--) {
            var option = document.createElement('option');
            option.value = String(y);
            option.textContent = String(y);
            yearSelect.appendChild(option);
        }
    }

    /* ---------- Training date limits ---------- */
    function todayLocal() {
        var d = new Date();
        var month = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');

        return d.getFullYear() + '-' + month + '-' + day;
    }

    function limitDates(scope) {
        var today = todayLocal();

        scope.querySelectorAll('.t-date').forEach(function (dateInput) {
            dateInput.max = today;
        });
    }

    limitDates(document);

    /* ---------- Add training block ---------- */
    if (trainingList && template && $('addTraining')) {
        $('addTraining').addEventListener('click', function () {
            var clone = template.content.cloneNode(true);
            limitDates(clone);
            trainingList.appendChild(clone);

            var blocks = trainingList.querySelectorAll('[data-training]');
            var last = blocks[blocks.length - 1];
            var firstSelect = last.querySelector('select');

            if (firstSelect) firstSelect.focus();

            updateProgress();
        });

        trainingList.addEventListener('click', function (e) {
            var button = e.target.closest('.remove-training');
            if (!button) return;

            button.closest('[data-training]').remove();
            updateProgress();
        });
    }

    /* ---------- Character counter ---------- */
    function updateCount() {
        if (otherQuals && charCount) {
            charCount.textContent = otherQuals.value.length + '/200';
        }
    }

    if (otherQuals) {
        otherQuals.addEventListener('input', updateCount);
    }

    /* ==========================================================================
       VALIDATION
       Main education fields stay required.
       Training can be completely empty.
       Once a training entry is started, all four fields are required.
       ========================================================================== */

    function validateField(el) {
        var field = el.closest('.field');
        if (!field) return true;

        var error = field.querySelector('.field-error');
        var empty = el.required && !el.value.trim();

        field.classList.toggle('invalid', empty);

        if (error) {
            error.textContent = empty ? 'This field is required.' : '';
        }

        return !empty;
    }

    function validateTrainingBlock(block) {
        var fields = [
            { selector: '.t-type', label: 'Training type' },
            { selector: '.t-inst', label: 'Training institution' },
            { selector: '.t-cert', label: 'Certification obtained' },
            { selector: '.t-date', label: 'Date completed' }
        ];

        var started = fields.some(function (item) {
            var el = block.querySelector(item.selector);
            return el && el.value.trim() !== '';
        });

        // Allow the whole training block to remain empty.
        if (!started) {
            fields.forEach(function (item) {
                var el = block.querySelector(item.selector);
                if (!el) return;

                var field = el.closest('.field');
                var error = field && field.querySelector('.field-error');

                if (field) field.classList.remove('invalid');
                if (error) error.textContent = '';
            });

            return true;
        }

        // If any training detail is entered, require every detail.
        var valid = true;

        fields.forEach(function (item) {
            var el = block.querySelector(item.selector);
            if (!el) return;

            var field = el.closest('.field');
            var error = field && field.querySelector('.field-error');
            var empty = !el.value.trim();

            if (field) field.classList.toggle('invalid', empty);

            if (error) {
                error.textContent = empty
                    ? item.label + ' is required for this training entry.'
                    : '';
            }

            if (empty) valid = false;
        });

        return valid;
    }

    function validateAll() {
        var firstBad = null;

        // Validate the required education fields.
        form.querySelectorAll('[required]').forEach(function (el) {
            if (!validateField(el) && !firstBad) {
                firstBad = el;
            }
        });

        // Validate training only if an entry has been started.
        trainingList.querySelectorAll('[data-training]').forEach(function (block) {
            if (!validateTrainingBlock(block) && !firstBad) {
                firstBad = block.querySelector(
                    '.field.invalid input, .field.invalid select'
                );
            }
        });

        return firstBad;
    }

    if (form) {
        form.addEventListener('input', function (e) {
            if (e.target.required) validateField(e.target);

            var block = e.target.closest('[data-training]');
            if (block) validateTrainingBlock(block);

            updateProgress();
        });

        form.addEventListener('change', function (e) {
            if (e.target.required) validateField(e.target);

            var block = e.target.closest('[data-training]');
            if (block) validateTrainingBlock(block);

            updateProgress();
        });
    }

    /* ---------- Profile completion ---------- */
    function updateProgress() {
        if (!form) return;

        var required = form.querySelectorAll('[required]');
        var filled = 0;

        required.forEach(function (el) {
            if (el.value.trim()) filled++;
        });

        var ratio = required.length ? filled / required.length : 0;
        var percent = Math.round(BASE_COMPLETION + EDU_POINTS * ratio);

        if ($('progressFill')) {
            $('progressFill').style.width = percent + '%';
        }

        if ($('progressPercent')) {
            $('progressPercent').textContent = percent + '%';
        }

        if ($('progressTrack')) {
            $('progressTrack').setAttribute('aria-valuenow', percent);
        }

        if ($('progressText')) {
            $('progressText').textContent = ratio >= 1
                ? 'Great! Your education section is complete.'
                : 'Complete your education section to unlock more opportunities.';
        }
    }

    /* ---------- Collect form data ---------- */
    function collectData() {
        var trainings = [];

        trainingList.querySelectorAll('[data-training]').forEach(function (block) {
            var type = block.querySelector('.t-type').value;
            var institution = block.querySelector('.t-inst').value;
            var certification = block.querySelector('.t-cert').value.trim();
            var date = block.querySelector('.t-date').value;

            // Do not submit a completely empty training block.
            if (!type && !institution && !certification && !date) {
                return;
            }

            trainings.push({
                type: type,
                institution: institution,
                certification: certification,
                date: date
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

    /* ---------- Save data ---------- */
    async function saveData() {
        try {
            var response = await fetch('education.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(collectData())
            });

            if (response.status === 401) {
                window.location.href = LOGIN_PAGE;
                return {
                    ok: false,
                    message: 'Please log in again.'
                };
            }

            var responseText = await response.text();
            var data;

            try {
                data = JSON.parse(responseText);
            } catch (err) {
                console.error(
                    'education.php returned status ' + response.status + ':',
                    responseText.slice(0, 500)
                );

                return {
                    ok: false,
                    message: 'Server error (' + response.status +
                        '). Press F12 and open Console for details.'
                };
            }

            return {
                ok: !!data.success,
                message: (data.message || '') +
                    (data.debug ? ' [' + data.debug + ']' : '')
            };
        } catch (err) {
            console.error('Could not save education data:', err);

            return {
                ok: false,
                message: 'Could not reach the server.'
            };
        }
    }

    /* ---------- Load saved data ---------- */
    async function loadData() {
        var data;

        try {
            var response = await fetch('education.php', {
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (response.status === 401) {
                window.location.href = LOGIN_PAGE;
                return;
            }

            data = await response.json();
        } catch (err) {
            console.warn('Could not load saved education details:', err);
            return;
        }

        if (!data || !data.success) return;

        var education = data.education;

        if (education) {
            var map = {
                eduLevel: education.edu_level,
                course: education.course,
                fieldOfStudy: education.field_of_study,
                school: education.school,
                location: education.location,
                yearDone: education.year_done,
                eduStatus: education.edu_status,
                grade: education.grade,
                certName: education.cert_name
            };

            Object.keys(map).forEach(function (id) {
                if (map[id] !== null && map[id] !== undefined && map[id] !== '') {
                    $(id).value = String(map[id]);
                }
            });

            otherQuals.value = education.other_quals || '';
        }

        var savedTrainings = data.trainings || [];

        while (
            trainingList.querySelectorAll('[data-training]').length <
            savedTrainings.length
        ) {
            var clone = template.content.cloneNode(true);
            limitDates(clone);
            trainingList.appendChild(clone);
        }

        var blocks = trainingList.querySelectorAll('[data-training]');

        savedTrainings.forEach(function (training, index) {
            blocks[index].querySelector('.t-type').value =
                training.training_type || '';

            blocks[index].querySelector('.t-inst').value =
                training.institution || '';

            blocks[index].querySelector('.t-cert').value =
                training.certification || '';

            blocks[index].querySelector('.t-date').value =
                training.completed_on || '';
        });
    }

    /* ---------- Toast messages ---------- */
    var toastTimer;

    function showToast(message, isError) {
        var toast = $('toast');
        var toastMsg = $('toastMsg');

        if (!toast || !toastMsg) {
            alert(message);
            return;
        }

        toastMsg.textContent = message;
        toast.classList.toggle('error', !!isError);
        toast.classList.remove('hidden');

        clearTimeout(toastTimer);

        toastTimer = setTimeout(function () {
            toast.classList.add('hidden');
        }, isError ? 8000 : 3000);
    }

    function goNext() {
        setTimeout(function () {
            window.location.href = NEXT_PAGE;
        }, 1200);
    }

    /* ---------- Submit form ---------- */
    var saving = false;

    if (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            if (saving) return;

            var bad = validateAll();

            if (bad) {
                bad.focus();
                showToast('Please fill in the required fields.', true);
                return;
            }

            var submitBtn = form.querySelector('button[type="submit"]');

            saving = true;
            if (submitBtn) submitBtn.disabled = true;

            var result = await saveData();

            saving = false;
            if (submitBtn) submitBtn.disabled = false;

            if (result.ok) {
                showToast('Education details saved!', false);
                goNext();
            } else {
                showToast(
                    result.message || 'Could not save. Please try again.',
                    true
                );
            }
        });
    }

    /* ---------- Skip for now ---------- */
    if ($('skipBtn')) {
        $('skipBtn').addEventListener('click', function () {
            window.location.href = NEXT_PAGE;
        });
    }

    /* ---------- Start ---------- */
    if (form) {
        await loadData();
        updateCount();
        updateProgress();
    }
})();
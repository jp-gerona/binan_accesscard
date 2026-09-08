// Registers the family record modals (View + Import-fix) with the shared
// dashboard loader, drives the standalone Data Entry page (Family/entry) and the
// shared field set it and the modals render (head card plus member rows),
// captures repeatable family members, and submits over AJAX to the existing
// FamilyController::store() endpoint, refreshing the server-side DataTable on
// success.
//
// Shared form behavior:
//   - "Other" freetext selects (reveal + submit-time option swap)
//   - Contact number digits-only / exactly-11 validation
//   - Archived (grandfather) badge un-tick warning
//   - localStorage draft auto-save + restore-on-reopen + keep/discard-on-close
//
// Data Entry page only: the control-number gate ([data-control-number-gate])
// checks the number before anything else renders; the spine's later sections
// ([data-entry-section]) lose d-none only once it comes back available. A
// successful save navigates to the `redirect` the server returns (records)
// rather than staying on a spent form.
//
// Connected to:
//   - dashboard-modal-loader.js : window.registerDashboardModal()
//   - family-datatable.js       : window.reloadFamilyDataTable()
//   - Views  : Family/entry.php, Family/_fields.php, Family/family-modal.php, Family/view.php
//   - Backend: POST records (store), GET records/qr-check
(function (window, document) {
    'use strict';

    if (typeof window.registerDashboardModal !== 'function') {
        return;
    }

    var DRAFT_KEY = 'binan_family_modal_draft_v1';
    var saveTimer = null;
    var qrCheckTimer = null;
    var qrCheckSequence = 0;

    // ---- small DOM helpers -------------------------------------------------

    function setHidden(el, hidden) {
        if (el) {
            el.classList.toggle('d-none', !!hidden);
        }
    }

    // ---- "Other" freetext selects -----------------------------------------

    function isOtherValue(value) {
        var normalized = String(value || '').trim().toLowerCase();

        return normalized === 'other' || normalized === 'others' || normalized === '__other__';
    }

    // The "Other" freetext is typed by the worker, so it is stored uppercase like
    // names and addresses. Values picked from a dropdown keep their stored casing.
    function cleanOtherValue(value) {
        return String(value || '')
            .replace(/[^\p{L}\p{N}\s.,'\-/&()]/gu, '')
            .replace(/\s+/g, ' ')
            .trim()
            .toUpperCase();
    }

    function findOtherInput(select) {
        if (!select) {
            return null;
        }

        var direct = select.dataset.otherInput || '';

        if (direct !== '') {
            return document.querySelector(direct);
        }

        var field = select.dataset.otherField || '';
        var container = select.closest('[class*="col-"]') || select.parentElement;

        return field !== '' && container
            ? container.querySelector('[data-other-for="' + field + '"]')
            : null;
    }

    function selectedFieldValue(select) {
        var otherInput = findOtherInput(select);

        if (isOtherValue(select.value) && otherInput) {
            var otherValue = String(otherInput.value || '').trim();

            return otherValue !== '' ? otherValue : select.value;
        }

        return select.value;
    }

    function syncOtherControl(select) {
        var otherInput = findOtherInput(select);

        if (!otherInput) {
            return;
        }

        var shouldShow = isOtherValue(select.value);

        setHidden(otherInput, !shouldShow);
        otherInput.required = shouldShow;

        if (!shouldShow) {
            otherInput.value = '';
        }
    }

    function optionExists(select, value) {
        return Array.from(select.options).some(function (option) {
            return option.value === value;
        });
    }

    // Sets a select to a stored value: picks the matching option, or selects the
    // "Other" option and fills the freetext when the value is a custom one.
    function setSelectValueWithOther(select, value) {
        var normalized = String(value || '');

        if (normalized === '' || optionExists(select, normalized)) {
            select.value = normalized;
            syncOtherControl(select);

            return;
        }

        var otherOption = Array.from(select.options).find(function (option) {
            return isOtherValue(option.value);
        });

        if (otherOption) {
            otherOption.selected = true;
            var otherInput = findOtherInput(select);

            if (otherInput) {
                otherInput.value = normalized;
            }

            syncOtherControl(select);
        } else {
            select.value = '';
        }
    }

    function applyOtherValues(root) {
        Array.from(root.querySelectorAll('.js-other-select')).forEach(function (select) {
            var otherInput = findOtherInput(select);

            if (!otherInput || !isOtherValue(select.value)) {
                return;
            }

            var otherValue = cleanOtherValue(otherInput.value);

            if (otherValue === '') {
                return;
            }

            var generated = Array.from(select.options).find(function (option) {
                return option.dataset.generatedOther === '1';
            });

            if (!generated) {
                generated = document.createElement('option');
                generated.dataset.generatedOther = '1';
                select.appendChild(generated);
            }

            generated.value = otherValue;
            generated.textContent = otherValue;
            generated.selected = true;
        });
    }

    function initOtherSelects(root) {
        Array.from(root.querySelectorAll('.js-other-select')).forEach(function (select) {
            var initial = typeof select.dataset.initialValue !== 'undefined' ? select.dataset.initialValue : select.value;
            setSelectValueWithOther(select, initial);
        });
    }

    // The personal fields shared by the head block (head_*) and each member row (members[i][*]).
    var PERSON_FIELDS = ['lastname', 'firstname', 'middlename', 'suffix', 'birthday', 'sex',
        'civilstatus', 'contactnumber', 'religion', 'education', 'job', 'salary'];

    function readPersonField(control) {
        if (!control) {
            return '';
        }

        return control.matches('.js-other-select') ? selectedFieldValue(control) : control.value;
    }

    function writePersonField(control, value) {
        if (!control) {
            return;
        }

        if (control.matches('.js-other-select')) {
            setSelectValueWithOther(control, value);
        } else {
            control.value = value;
        }
    }

    // "Set as Head": swap the person fields between the head block and this member row.
    // Address / barangay / QR stay with the head position (they describe the household); the
    // demoted head becomes a member whose relationship is cleared for the operator to re-pick.
    function promoteMemberToHead(root, row) {
        var form = root.querySelector('form');
        var prefix = row.dataset.memberFieldPrefix;

        if (!form || !prefix) {
            return;
        }

        var memberField = function (key) {
            var name = (prefix + '[' + key + ']').replace(/(["\\])/g, '\\$1');

            return row.querySelector('[name="' + name + '"]');
        };

        PERSON_FIELDS.forEach(function (key) {
            var headCtrl = form.querySelector('[name="head_' + key + '"]');
            var memberCtrl = memberField(key);

            if (!headCtrl || !memberCtrl) {
                return;
            }

            var headVal = readPersonField(headCtrl);
            var memberVal = readPersonField(memberCtrl);
            writePersonField(headCtrl, memberVal);
            writePersonField(memberCtrl, headVal);
        });

        writePersonField(memberField('relationship'), '');

        refreshAllAgeEligibility(root);
        refreshAllServiceCategories(root);
        refreshAllChoicesSummaries(root);
        renumberMembers(root);

        // The swap rewrites who holds the QR card, so put the result in front of the
        // worker rather than leaving it below the fold.
        var headField = root.querySelector('[name="head_lastname"]');

        if (headField) {
            headField.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    // ---- field error helper ------------------------------------------------

    // Writes into the .invalid-feedback the view renders next to each control.
    // Bootstrap reveals it only while the control carries .is-invalid, so that class
    // toggle is the single switch and the div needs no hiding of its own.
    function setFieldError(field, message) {
        if (!field) {
            return;
        }

        var scope = field.closest('.input-group') || field.closest('[class*="col-"]') || field.parentElement;
        var feedback = scope ? scope.querySelector('[data-family-field-error]') : null;

        field.classList.toggle('is-invalid', message !== '');

        if (feedback) {
            feedback.textContent = message;
        }
    }

    // ---- contact number validation (ported) --------------------------------

    function isContactField(el) {
        return el && (el.name === 'head_contactnumber' || /\[contactnumber\]$/.test(el.name || ''));
    }

    // An 11-digit mobile (09XXXXXXXXX) or a Binan landline (7 to 8 digits, with the
    // 049 area code optional). The looser rule is deliberate: a mobile-only pattern
    // would block an edit to an older record whose landline nobody touched.
    var CONTACT_PATTERN = /^(09\d{9}|(049)?\d{7,8})$/;
    var CONTACT_MESSAGE = 'Enter an 11-digit mobile number (09XXXXXXXXX) or a Binan landline (7 to 8 digits, optional 049 prefix).';

    function enforceContactDigits(el) {
        el.value = String(el.value || '').replace(/[^0-9]/g, '').slice(0, 11);

        if (contactValueIsValid(el.value)) {
            setFieldError(el, '');
        }
    }

    // The value test on its own, so a closed row can be checked without a control.
    function contactValueIsValid(value) {
        var digits = String(value || '').trim();

        return digits === '' || CONTACT_PATTERN.test(digits);
    }

    function validateContact(el) {
        if (!el) {
            return true;
        }

        if (!contactValueIsValid(el.value)) {
            setFieldError(el, CONTACT_MESSAGE);

            return false;
        }

        setFieldError(el, '');

        return true;
    }

    // ---- control number availability -------------------------------------------

    function scheduleQrAvailabilityCheck(root, field) {
        if (!field || field.readOnly || !field.dataset.qrCheckUrl) {
            return;
        }

        if (qrCheckTimer) {
            window.clearTimeout(qrCheckTimer);
        }

        var sequence = ++qrCheckSequence;
        field.setCustomValidity('');
        setFieldError(field, '');

        if (String(field.value || '').trim() === '' || !field.checkValidity()) {
            return;
        }

        field.setCustomValidity('Checking whether this control number already exists.');

        qrCheckTimer = window.setTimeout(function () {
            var url = new URL(field.dataset.qrCheckUrl, window.location.href);
            var headId = root.querySelector('[name="head_id"]');
            url.searchParams.set('control_no', field.value);
            url.searchParams.set('head_id', headId ? headId.value : '0');

            // A hung request must never leave setCustomValidity parked: that blocks the
            // save with no visible message. Release it and let the server decide.
            var release = window.setTimeout(function () {
                if (sequence === qrCheckSequence && field.isConnected) {
                    field.setCustomValidity('');
                    setFieldError(field, '');
                }
            }, 5000);

            window.fetch(url.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            }).then(function (result) {
                window.clearTimeout(release);

                if (sequence !== qrCheckSequence || !field.isConnected) {
                    return;
                }

                var available = result.ok && result.data.available;
                var message = available ? '' : (result.data.message || 'The control number could not be validated.');
                field.setCustomValidity(message);
                setFieldError(field, message);
            }).catch(function () {
                window.clearTimeout(release);

                if (sequence === qrCheckSequence && field.isConnected) {
                    field.setCustomValidity('');
                    setFieldError(field, '');
                }
            });
        }, 350);
    }

    // ---- confirm dialog ----------------------------------------------------

    function askModalDialog(form, options) {
        return new Promise(function (resolve) {
            var host = form.closest('#familyModalBody') || form.closest('.modal-content') || form;
            var overlay = document.createElement('div');
            var dialog = document.createElement('div');
            var icon = document.createElement('div');
            var iconGlyph = document.createElement('i');
            var copy = document.createElement('div');
            var title = document.createElement('h3');
            var message = document.createElement('p');
            var actions = document.createElement('div');
            var cancelButton = document.createElement('button');
            var confirmButton = document.createElement('button');

            // No modal box to sit inside on the standalone Data Entry page, so the
            // backdrop is pinned to the viewport rather than the page-tall form.
            var isPageLevel = host === form;
            overlay.className = 'family-draft-dialog-backdrop' + (isPageLevel ? ' is-page-level' : '');
            overlay.setAttribute('role', 'presentation');
            dialog.className = 'family-draft-dialog';
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');

            icon.className = 'family-draft-dialog-icon' + (options.tone === 'warning' ? ' is-warning' : '');
            icon.setAttribute('aria-hidden', 'true');
            iconGlyph.className = options.iconClass || 'bi bi-question-lg';
            icon.appendChild(iconGlyph);

            copy.className = 'family-draft-dialog-copy';
            title.textContent = options.title || 'Confirm action';
            message.textContent = options.message || '';
            copy.appendChild(title);
            copy.appendChild(message);

            actions.className = 'family-draft-dialog-actions';
            cancelButton.type = 'button';
            cancelButton.className = 'btn btn-outline-secondary';
            cancelButton.dataset.dialogAction = 'cancel';
            cancelButton.textContent = options.cancelLabel || 'Cancel';
            confirmButton.type = 'button';
            confirmButton.className = options.confirmClass || 'btn btn-success';
            confirmButton.dataset.dialogAction = 'confirm';
            confirmButton.textContent = options.confirmLabel || 'Confirm';
            actions.appendChild(cancelButton);
            actions.appendChild(confirmButton);

            dialog.appendChild(icon);
            dialog.appendChild(copy);
            dialog.appendChild(actions);
            overlay.appendChild(dialog);

            var finish = function (confirmed) {
                overlay.remove();
                resolve(confirmed);
            };

            overlay.addEventListener('click', function (event) {
                if (event.target === overlay) {
                    finish(false);
                    return;
                }

                var button = event.target.closest('[data-dialog-action]');

                if (button) {
                    finish(button.dataset.dialogAction === 'confirm');
                }
            });

            overlay.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    finish(false);
                }
            });

            host.appendChild(overlay);
            confirmButton.focus();
        });
    }

    function askRemoveArchivedItem(form, label) {
        return askModalDialog(form, {
            title: 'Remove archived item?',
            message: '"' + label + '" is archived. If you remove it, this person loses the benefit and it can\'t be added back later.',
            iconClass: 'bi bi-exclamation-triangle',
            tone: 'warning',
            cancelLabel: 'Keep',
            confirmLabel: 'Remove',
            confirmClass: 'btn btn-danger'
        });
    }

    function askPromoteToHead(form, name) {
        return askModalDialog(form, {
            title: 'Make this person the head?',
            message: (name !== '' ? name + ' ' : 'This member ') + 'will take the head position and hold the QR card. The current head becomes a member, and you will need to set their relationship.',
            iconClass: 'bi bi-person-up',
            tone: 'warning',
            cancelLabel: 'Cancel',
            confirmLabel: 'Make head',
            confirmClass: 'btn btn-primary'
        });
    }

    function askRestoreDraft(form, savedAt) {
        return askModalDialog(form, {
            title: 'Restore unsaved record?',
            message: 'You have an unsaved record from ' + formatDraftAge(savedAt) + '. Restore it?',
            iconClass: 'bi bi-arrow-counterclockwise',
            cancelLabel: 'Discard',
            confirmLabel: 'Restore',
            confirmClass: 'btn btn-success'
        });
    }

    function askKeepOrDiscard(form) {
        return askModalDialog(form, {
            title: 'Keep your progress?',
            message: 'You have an unsaved record. Keep it to continue later, or discard it?',
            iconClass: 'bi bi-save',
            cancelLabel: 'Discard',
            confirmLabel: 'Keep',
            confirmClass: 'btn btn-success'
        });
    }

    // Shown when the server reports a max_input_vars cutoff (code FORM_TRUNCATED):
    // nothing was saved, but the draft holds everything. Both buttons just dismiss -
    // the modal stays open with the data intact either way.
    function askTruncationNotice(form) {
        return askModalDialog(form, {
            title: 'Form too large to send',
            message: 'There were too many entries to send all at once, so nothing was saved yet. Don\'t worry - all your entries are kept on this computer. Remove a few members and Save again, or close and reopen Add Family to restore everything.',
            iconClass: 'bi bi-exclamation-triangle',
            tone: 'warning',
            cancelLabel: 'Close',
            confirmLabel: 'Keep editing',
            confirmClass: 'btn btn-success'
        });
    }

    // ---- draft persistence (create mode only) ------------------------------

    function isCreateForm(root) {
        var modeInput = root.querySelector('[name="form_mode"]');

        return !modeInput || modeInput.value !== 'update';
    }

    function readDraft() {
        try {
            var raw = window.localStorage.getItem(DRAFT_KEY);

            return raw ? JSON.parse(raw) : null;
        } catch (error) {
            return null;
        }
    }

    function clearDraft() {
        try {
            window.localStorage.removeItem(DRAFT_KEY);
        } catch (error) {
            /* storage unavailable */
        }
    }

    function draftIsEmpty(snapshot) {
        if (!snapshot || typeof snapshot !== 'object') {
            return true;
        }

        var head = snapshot.head || {};
        var hasHead = Object.keys(head).some(function (key) {
            return String(head[key] || '').trim() !== '';
        });

        return !hasHead
            && (snapshot.members || []).length === 0
            && (snapshot.sector_ids || []).length === 0
            && (snapshot.service_ids || []).length === 0;
    }

    function formatDraftAge(savedAt) {
        var ts = Number(savedAt) || 0;

        if (ts <= 0) {
            return 'a moment ago';
        }

        var mins = Math.floor((Date.now() - ts) / 60000);

        if (mins < 1) { return 'just now'; }
        if (mins < 60) { return mins + (mins === 1 ? ' minute ago' : ' minutes ago'); }

        var hrs = Math.floor(mins / 60);

        if (hrs < 24) { return hrs + (hrs === 1 ? ' hour ago' : ' hours ago'); }

        var days = Math.floor(hrs / 24);

        return days + (days === 1 ? ' day ago' : ' days ago');
    }

    function checkedValues(scope, selector) {
        return Array.from(scope.querySelectorAll(selector + ':checked')).map(function (input) {
            return input.value;
        });
    }

    // One reader for both row states. An open row is read from its controls, a closed
    // row from the hidden inputs that replaced them; the names are the same either way.
    function readMemberData(row) {
        var data = { sector_ids: [], service_ids: [], sector_codes: [] };

        Array.from(row.querySelectorAll('input, select')).forEach(function (field) {
            var match = /members\[\d+\]\[([a-z_]+)\](\[\])?$/.exec(field.name || '');

            if (!match) {
                return;
            }

            var key = match[1];

            if (key === 'sector_ids' || key === 'service_ids') {
                if (field.type === 'hidden' || field.checked) {
                    data[key].push(field.value);

                    if (key === 'sector_ids') {
                        // The shortcode, not the full name: a summary line stays
                        // scannable with "SC, PWD" where the names would wrap.
                        data.sector_codes.push(String(field.dataset.sectorCode || field.dataset.sectorName || field.dataset.label || '').trim());
                    }
                }

                return;
            }

            data[key] = field.classList.contains('js-other-select') ? selectedFieldValue(field) : field.value;
        });

        return data;
    }

    function writeMemberData(row, data) {
        Object.keys(data).forEach(function (key) {
            if (key === 'sector_ids' || key === 'service_ids') {
                checkBoxes(row, row.dataset.memberFieldPrefix + '[' + key + '][]', data[key]);

                return;
            }

            if (key === 'sector_codes') {
                return;
            }

            var field = row.querySelector('[name="' + row.dataset.memberFieldPrefix + '[' + key + ']"]');

            if (!field) {
                return;
            }

            if (field.classList.contains('js-other-select')) {
                setSelectValueWithOther(field, data[key]);
            } else {
                field.value = data[key];
            }
        });
    }

    function hiddenInput(name, value, sectorCode) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;

        if (sectorCode) {
            input.dataset.sectorCode = sectorCode;
        }

        return input;
    }

    var MEMBER_VALUE_KEYS = ['lastname', 'firstname', 'middlename', 'suffix', 'birthday', 'sex',
        'civilstatus', 'contactnumber', 'religion', 'education', 'job', 'salary', 'relationship'];

    // Closed rows read as text, so a household of six is a list instead of six full
    // forms. The values move into hidden inputs under the same names, which is what
    // keeps FormData and the server contract unchanged.
    function collapseMemberRow(row) {
        var editor = row.querySelector('[data-family-member-editor]');
        var values = row.querySelector('[data-family-member-values]');

        if (!editor || !values || row.dataset.familyMemberOpen === '0') {
            return;
        }

        var data = readMemberData(row);
        var prefix = row.dataset.memberFieldPrefix;

        values.innerHTML = '';

        MEMBER_VALUE_KEYS.forEach(function (key) {
            values.appendChild(hiddenInput(prefix + '[' + key + ']', data[key] || ''));
        });

        data.sector_ids.forEach(function (id, position) {
            values.appendChild(hiddenInput(prefix + '[sector_ids][]', id, data.sector_codes[position]));
        });

        data.service_ids.forEach(function (id) {
            values.appendChild(hiddenInput(prefix + '[service_ids][]', id));
        });

        editor.innerHTML = '';
        row.dataset.familyMemberOpen = '0';
        setMemberToggleState(row, false);
        renderMemberSummary(row);
        renumberMembers(row.closest('[data-family-entry-form]') || row.parentElement);
    }

    function expandMemberRow(root, row) {
        if (!row || row.dataset.familyMemberOpen === '1') {
            return;
        }

        // Read before mounting: the editor replaces the hidden inputs these come from.
        var data = readMemberData(row);

        if (!buildMemberEditor(root, row)) {
            return;
        }

        row.dataset.familyMemberOpen = '1';
        writeMemberData(row, data);
        setMemberToggleState(row, true);
        renderMemberSummary(row);
        renumberMembers(root);
        refreshAgeEligibility(row);
        refreshServiceCategories(row);
        refreshChoicesSummary(row);
    }

    function setMemberToggleState(row, open) {
        var toggle = row.querySelector('[data-family-member-toggle]');
        var chevron = row.querySelector('[data-family-member-chevron]');
        var editor = row.querySelector('[data-family-member-editor]');

        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        // Swapping the icon keeps the open/closed state in Bootstrap Icons rather
        // than in a CSS transform of our own.
        if (chevron) {
            chevron.classList.toggle('bi-chevron-up', open);
            chevron.classList.toggle('bi-chevron-down', !open);
        }

        // The open row is the one being worked on, and its fields need the padding
        // the closed row has no use for.
        row.classList.toggle('bg-body-tertiary', open);

        if (editor) {
            editor.classList.toggle('px-3', open);
            editor.classList.toggle('pb-3', open);
        }
    }

    // The closed row has to say who it is: name, relationship, age, and the sector
    // codes, which is everything the worker scans a household list for.
    function renderMemberSummary(row) {
        var target = row.querySelector('[data-family-member-summary]');

        if (!target) {
            return;
        }

        var data = readMemberData(row);
        var middle = String(data.middlename || '').trim();
        var given = [String(data.firstname || '').trim(), middle !== '' ? middle.charAt(0) + '.' : '', String(data.suffix || '').trim()]
            .filter(Boolean).join(' ');
        var last = String(data.lastname || '').trim();
        var name = last !== '' && given !== '' ? last + ', ' + given : (last || given);
        var age = completedAge(data.birthday);
        var sectors = data.sector_codes.filter(Boolean).join(', ');
        // One muted line under the name, in the order a worker scans a household:
        // how they relate to the head, how old they are, what they are classified
        // as, how much aid they draw. Empty facts drop out rather than leaving
        // stray separators behind.
        var meta = [
            String(data.relationship || '').trim(),
            age === null ? '' : age + ' yrs',
            sectors,
            data.service_ids.length ? data.service_ids.length + ' program' + (data.service_ids.length === 1 ? '' : 's') : ''
        ].filter(Boolean).join(' · ');

        target.innerHTML = '';

        var nameEl = document.createElement('span');
        nameEl.className = 'fw-semibold text-truncate';
        nameEl.textContent = name !== '' ? name : 'Unnamed member';
        target.appendChild(nameEl);

        var metaEl = document.createElement('span');
        metaEl.className = 'small text-body-secondary text-truncate';
        metaEl.textContent = meta !== '' ? meta : 'No details yet';
        target.appendChild(metaEl);
    }

    function snapshotForm(form) {
        var head = {};

        Array.from(form.querySelectorAll('[name^="head_"]')).forEach(function (field) {
            head[field.name] = field.classList.contains('js-other-select') ? selectedFieldValue(field) : field.value;
        });

        var members = Array.from(form.querySelectorAll('[data-family-member-row]')).map(function (row) {
            var data = readMemberData(row);

            delete data.sector_codes;

            return data;
        });

        return {
            v: 1,
            head: head,
            sector_ids: checkedValues(form, 'input[name="sector_ids[]"]'),
            service_ids: checkedValues(form, 'input[name="service_ids[]"]'),
            members: members,
            savedAt: Date.now()
        };
    }

    // Closing is safe because the draft is kept, so the footer says so outright rather
    // than leaving the worker to infer it from a button color.
    function setDraftStatus(form, text) {
        var root = form.closest('[data-family-entry-form]') || form;
        var status = root.querySelector('[data-family-draft-status]');

        if (status) {
            status.textContent = text;
        }
    }

    function saveDraftNow(form) {
        try {
            window.localStorage.setItem(DRAFT_KEY, JSON.stringify(snapshotForm(form)));
            setDraftStatus(form, 'Draft saved');
        } catch (error) {
            /* storage unavailable / quota */
            setDraftStatus(form, '');
        }
    }

    function scheduleSave(root) {
        var form = root.querySelector('form');

        if (!form || !isCreateForm(root)) {
            return;
        }

        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(function () {
            saveDraftNow(form);
        }, 400);
    }

    function checkBoxes(scope, name, values) {
        var wanted = (values || []).map(String);

        Array.from(scope.querySelectorAll('input[name="' + name + '"]')).forEach(function (box) {
            box.checked = wanted.indexOf(String(box.value)) !== -1;
        });
    }

    function restoreDraftIntoForm(root, snapshot) {
        var form = root.querySelector('form');

        if (!form || !snapshot) {
            return;
        }

        var head = snapshot.head || {};

        Object.keys(head).forEach(function (name) {
            var field = form.querySelector('[name="' + name + '"]');

            if (!field) {
                return;
            }

            if (field.classList.contains('js-other-select')) {
                setSelectValueWithOther(field, head[name]);
            } else {
                field.value = head[name];
            }
        });

        checkBoxes(form, 'sector_ids[]', snapshot.sector_ids);
        checkBoxes(form, 'service_ids[]', snapshot.service_ids);

        clearMemberRows(root);

        (snapshot.members || []).forEach(function (member) {
            var row = addMemberRow(root);

            if (!row) {
                return;
            }

            Object.keys(member).forEach(function (key) {
                if (key === 'sector_ids' || key === 'service_ids') {
                    checkBoxes(row, row.dataset.memberFieldPrefix + '[' + key + '][]', member[key]);

                    return;
                }

                var field = row.querySelector('[name="' + row.dataset.memberFieldPrefix + '[' + key + ']"]');

                if (!field) {
                    return;
                }

                if (field.classList.contains('js-other-select')) {
                    setSelectValueWithOther(field, member[key]);
                } else {
                    field.value = member[key];
                }
            });
        });
        refreshAllAgeEligibility(root);
        refreshAllServiceCategories(root);
        refreshAllChoicesSummaries(root);
        renumberMembers(root);
    }

    // ---- member row headers -------------------------------------------------

    // The header states the position in the household and nothing else: the name
    // sits beside it in the summary, and printing it in both places made a closed
    // row two lines saying the same thing twice. Numbering is recomputed rather
    // than baked into the markup, because removing a row renumbers everything after it.
    function renumberMembers(root) {
        var rows = Array.from(root.querySelectorAll('[data-family-member-row]'));

        rows.forEach(function (row, index) {
            var title = row.querySelector('[data-family-member-title]');

            if (title) {
                // Just the position, and the same in both states: the row header does
                // not move or re-word when it opens, so an open row stays lined up
                // with the closed ones above and below it.
                title.textContent = String(index + 1);
            }
        });

        // The section header carries the count, and the empty state stands in for the
        // list until there is a first member, so the section is never a blank gap.
        var count = root.querySelector('[data-family-members-count]');
        var empty = root.querySelector('[data-family-members-empty]');

        if (count) {
            count.textContent = rows.length === 1 ? '1 member' : rows.length + ' members';
        }

        if (empty) {
            empty.classList.toggle('d-none', rows.length > 0);
        }
    }

    // ---- sectors and services ------------------------------------------------

    var SECTOR_INPUT_SELECTOR = 'input[name="sector_ids[]"], input[name$="[sector_ids][]"]';
    var SERVICE_INPUT_SELECTOR = 'input[name="service_ids[]"], input[name$="[service_ids][]"]';

    function normName(value) {
        return String(value || '').trim().toLowerCase();
    }

    function completedAge(value) {
        var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));

        if (!match) {
            return null;
        }

        var year = parseInt(match[1], 10);
        var month = parseInt(match[2], 10);
        var day = parseInt(match[3], 10);
        var birthday = new Date(year, month - 1, day);
        var today = new Date();

        if (birthday.getFullYear() !== year || birthday.getMonth() !== month - 1 || birthday.getDate() !== day
            || birthday > today) {
            return null;
        }

        var age = today.getFullYear() - year;

        if (today.getMonth() < month - 1 || (today.getMonth() === month - 1 && today.getDate() < day)) {
            age -= 1;
        }

        return age;
    }

    // Where a choice's age limits come from: the view stamps them from
    // FamilyAgeEligibility, so 18 / 60 / B / SC live in exactly one place.
    function ageBoundsFor(input) {
        var source = input.matches(SERVICE_INPUT_SELECTOR)
            ? input.closest('[data-service-category]')
            : input;

        if (!source) {
            return null;
        }

        var min = source.dataset.minAge;
        var max = source.dataset.maxAge;

        if (typeof min === 'undefined' && typeof max === 'undefined') {
            return null;
        }

        return {
            min: typeof min === 'undefined' ? null : parseInt(min, 10),
            max: typeof max === 'undefined' ? null : parseInt(max, 10)
        };
    }

    function ageBoundsMessage(bounds) {
        if (bounds.max !== null) {
            return 'Available only to persons below ' + (bounds.max + 1) + ' years old.';
        }

        return 'Available only to persons ' + bounds.min + ' years old and above.';
    }

    function refreshAgeNote(scopeEl) {
        var row = scopeEl.matches && scopeEl.matches('[data-family-member-row]') ? scopeEl : null;
        var birthday = row
            ? scopeEl.querySelector('input[name$="[birthday]"]')
            : scopeEl.querySelector('input[name="head_birthday"]');
        var note = birthday ? birthday.parentElement.querySelector('[data-family-age-note]') : null;

        if (!note) {
            return;
        }

        var age = completedAge(birthday.value);

        note.textContent = age === null ? '' : 'Age: ' + age;
    }

    // A person's birthday controls only that person's age-specific choices.
    function refreshAgeEligibility(scopeEl) {
        if (!scopeEl || !scopeEl.querySelectorAll) {
            return;
        }

        var row = scopeEl.matches && scopeEl.matches('[data-family-member-row]') ? scopeEl : null;
        var birthday = row
            ? row.querySelector('input[name$="[birthday]"]')
            : scopeEl.querySelector('input[name="head_birthday"]');
        var age = birthday ? completedAge(birthday.value) : null;
        var selector = row
            ? 'input[name$="[sector_ids][]"], input[name$="[service_ids][]"]'
            : 'input[name="sector_ids[]"], input[name="service_ids[]"]';

        scopeEl.querySelectorAll(selector).forEach(function (input) {
            // A closed row carries its ticks as hidden inputs under the same names.
            // Disabling one would drop it from the POST, so eligibility only ever
            // touches a real checkbox the worker can see.
            if (input.type !== 'checkbox') {
                return;
            }

            var bounds = ageBoundsFor(input);

            if (bounds === null) {
                return;
            }

            var allowed = age !== null
                && (bounds.min === null || age >= bounds.min)
                && (bounds.max === null || age <= bounds.max);
            var message = age === null
                ? 'Enter a valid date of birth to determine eligibility.'
                : ageBoundsMessage(bounds);
            var choice = input.closest('.family-choice');

            // A ticked box is never cleared on the worker's behalf: a mistyped birth year
            // used to wipe the ticks with no message and no way back. The tick stays,
            // says why it is wrong, and resolves itself when the date is corrected.
            // FamilyAgeEligibility::selectionError() still blocks a genuinely bad save.
            input.disabled = !allowed && !input.checked;
            input.classList.toggle('is-invalid', !allowed && input.checked);

            if (choice) {
                choice.title = allowed ? '' : message;

                var label = choice.querySelector('.form-check-label');

                if (label) {
                    label.classList.toggle('text-danger', !allowed && input.checked);
                    label.setAttribute('title', allowed ? '' : message);
                }
            }
        });

        refreshAgeNote(scopeEl);
    }

    function refreshAllAgeEligibility(root) {
        refreshAgeEligibility(root);
        root.querySelectorAll('[data-family-member-row]').forEach(function (row) {
            refreshAgeEligibility(row);
        });
    }

    // ---- service accordion state --------------------------------------------
    // A category opens when it matches a ticked sector or already holds a tick, and
    // closes again when neither is true any more. Panels the worker opened by hand
    // are left alone: their click is an explicit instruction, so nothing auto-closes
    // it. That flag is what keeps a cleared filter or an unticked box from leaving
    // every category hanging open.

    function servicePanelsIn(scopeEl) {
        var row = scopeEl.matches && scopeEl.matches('[data-family-member-row]') ? scopeEl : null;

        return Array.from(scopeEl.querySelectorAll('[data-family-service-panel]')).filter(function (panel) {
            return row ? true : !panel.closest('[data-family-member-row]');
        });
    }

    // Drives a collapse panel without needing bootstrap.Collapse to be instantiated
    // first, and keeps the header button's aria-expanded in step.
    function setServicePanelOpen(panel, open) {
        var item = panel.closest('.accordion-item');
        var button = item ? item.querySelector('.accordion-button') : null;

        // Only the panel gets skipped when it is already where we want it. The
        // header still gets synced, so markup that arrives with the two out of
        // step (an import-fix fragment, say) is corrected instead of left alone.
        if (panel.classList.contains('show') !== open) {
            if (window.bootstrap && window.bootstrap.Collapse) {
                window.bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false })[open ? 'show' : 'hide']();
            } else {
                panel.classList.toggle('show', open);
            }
        }

        if (button) {
            button.classList.toggle('collapsed', !open);
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    function refreshServiceCategories(scopeEl) {
        if (!scopeEl || !scopeEl.querySelectorAll) {
            return;
        }

        var row = scopeEl.matches && scopeEl.matches('[data-family-member-row]') ? scopeEl : null;
        var searchRoot = row || scopeEl;
        var sectorSelector = row ? 'input[name$="[sector_ids][]"]:checked' : 'input[name="sector_ids[]"]:checked';
        var checkedKeys = {};

        searchRoot.querySelectorAll(sectorSelector).forEach(function (input) {
            checkedKeys[normName(input.dataset.sectorName)] = true;
        });

        servicePanelsIn(searchRoot).forEach(function (panel) {
            if (panel.dataset.familyUserToggled === '1') {
                return;
            }

            var matched = !!checkedKeys[normName(panel.dataset.serviceCategory)];
            var hasChecked = panel.querySelector('input[type="checkbox"]:checked') !== null;

            setServicePanelOpen(panel, matched || hasChecked);
        });
    }

    function refreshAllServiceCategories(root) {
        refreshServiceCategories(root);
        root.querySelectorAll('[data-family-member-row]').forEach(function (row) {
            refreshServiceCategories(row);
        });
    }

    // Type-to-narrow over one accordion: non-matching choices hide, a category with no
    // match drops out, and matching categories open while the search runs. Clearing the
    // box hands the open state back to the sector-and-tick rule above, so the accordion
    // does not stay fully expanded afterwards.
    function filterServiceAccordion(input) {
        var accordion = input.parentElement ? input.parentElement.nextElementSibling : null;

        if (!accordion || !accordion.matches('[data-family-service-accordion]')) {
            return;
        }

        var term = String(input.value || '').trim().toLowerCase();
        var scope = input.closest('[data-family-member-row]') || input.closest('[data-family-entry-form]');

        accordion.querySelectorAll('[data-family-service-item]').forEach(function (item) {
            var panel = item.querySelector('[data-family-service-panel]');
            var matches = 0;

            item.querySelectorAll('.family-choice').forEach(function (choice) {
                var label = choice.querySelector('.form-check-label');
                var hit = term === '' || (label ? label.textContent.toLowerCase().indexOf(term) !== -1 : false);
                var cell = choice.closest('.col') || choice;

                cell.classList.toggle('d-none', !hit);

                if (hit) {
                    matches++;
                }
            });

            item.classList.toggle('d-none', term !== '' && matches === 0);

            if (!panel) {
                return;
            }

            if (term !== '') {
                // A search result the worker can't see is useless, so matches open for
                // the duration. This is the filter's doing, not theirs, so it does not
                // count as a manual toggle.
                setServicePanelOpen(panel, matches > 0);
            }
        });

        if (term === '' && scope) {
            refreshServiceCategories(scope);
        }
    }

    // The collapse toggle doubles as the summary of what is ticked, so a collapsed
    // block (edit mode) still says what this person is categorised as.
    // Scoped, so a member row summarises its own ticks instead of the head's.
    function refreshChoicesSummary(scopeEl) {
        var target = scopeEl.querySelector('[data-family-choices-summary]');

        if (!target) {
            return;
        }

        var row = scopeEl.matches && scopeEl.matches('[data-family-member-row]') ? scopeEl : null;
        var sectorSelector = row ? 'input[name$="[sector_ids][]"]:checked' : 'input[name="sector_ids[]"]:checked';
        var serviceSelector = row ? 'input[name$="[service_ids][]"]:checked' : 'input[name="service_ids[]"]:checked';
        var codes = Array.from(scopeEl.querySelectorAll(sectorSelector)).map(function (input) {
            return String(input.dataset.sectorCode || input.dataset.sectorName || input.dataset.label || '').trim();
        }).filter(Boolean);
        var services = scopeEl.querySelectorAll(serviceSelector).length;

        if (codes.length === 0 && services === 0) {
            target.textContent = 'Nothing selected yet';
            return;
        }

        target.textContent = 'Sectors: ' + (codes.length ? codes.join(', ') : 'none')
            + ' (' + services + ' program' + (services === 1 ? '' : 's') + ')';
    }

    function refreshAllChoicesSummaries(root) {
        refreshChoicesSummary(root);
        root.querySelectorAll('[data-family-member-row]').forEach(function (row) {
            refreshChoicesSummary(row);
            renderMemberSummary(row);
        });
    }

    // ---- head validation ---------------------------------------------------

    // The head fields are always visible now, so "the head section" is simply the
    // head-scoped required controls. No pane lookup, no step gate.
    function validateHead(container) {
        var form = container.querySelector('form');
        var requiredFields = form
            ? Array.from(form.querySelectorAll('[required]')).filter(function (field) {
                return !field.closest('[data-family-member-row]');
            })
            : [];
        var firstInvalid = null;

        requiredFields.forEach(function (field) {
            var isEmpty = String(field.value || '').trim() === '';
            var invalid = !field.checkValidity();

            setFieldError(field, invalid ? (isEmpty ? 'This field is required.' : field.validationMessage) : '');

            if (invalid && !firstInvalid) {
                firstInvalid = field;
            }
        });

        var contact = container.querySelector('[name="head_contactnumber"]');

        if (contact && !validateContact(contact) && !firstInvalid) {
            firstInvalid = contact;
        }

        if (firstInvalid) {
            firstInvalid.focus();

            return false;
        }

        return true;
    }

    function clearFieldError(field) {
        if (String(field.value || '').trim() !== '') {
            setFieldError(field, '');
        }
    }

    // ---- repeatable members ------------------------------------------------

    function memberIndex(row) {
        var match = /^members\[(\d+)\]$/.exec((row && row.dataset.memberFieldPrefix) || '');

        return match ? match[1] : null;
    }

    // Mounts the field set into a row's editor slot. The template still carries the
    // __INDEX__ placeholder, so one replace covers both the posted names and the ids
    // the labels point at. A mounted editor and the hidden values are mutually
    // exclusive: they share field names, so leaving both would post each key twice.
    function buildMemberEditor(root, row) {
        var template = root.querySelector('[data-family-member-editor-template]');
        var mount = row ? row.querySelector('[data-family-member-editor]') : null;
        var values = row ? row.querySelector('[data-family-member-values]') : null;
        var index = memberIndex(row);

        if (!template || !mount || index === null) {
            return null;
        }

        mount.innerHTML = (template.innerHTML || '').replace(/__INDEX__/g, index).trim();

        if (values) {
            values.innerHTML = '';
        }

        initOtherSelects(mount);

        return mount;
    }

    function addMemberRow(root) {
        var button = root.querySelector('[data-family-add-member]');
        var container = root.querySelector('[data-family-members]');
        var template = root.querySelector('[data-family-member-template]');

        if (!button || !container || !template) {
            return null;
        }

        var nextIndex = parseInt(button.dataset.nextIndex || '0', 10) || 0;
        var markup = (template.innerHTML || '').replace(/__INDEX__/g, String(nextIndex));
        var holder = document.createElement('div');
        holder.innerHTML = markup.trim();

        var row = holder.querySelector('[data-family-member-row]');

        if (!row) {
            return null;
        }

        row.dataset.memberFieldPrefix = 'members[' + nextIndex + ']';

        // Server-rendered rows sit inside a full-width grid cell (the shared
        // `.row.g-3` in Family/_fields.php); wrap a JS-added row the same way so
        // members stack one per row.
        var card = document.createElement('div');
        card.className = 'col-12';
        card.setAttribute('data-member-card', '');
        card.appendChild(row);
        container.appendChild(card);
        button.dataset.nextIndex = String(nextIndex + 1);

        buildMemberEditor(root, row);
        row.dataset.familyMemberOpen = '1';
        refreshAgeEligibility(row);
        refreshServiceCategories(row);

        return row;
    }

    function clearMemberRows(root) {
        var container = root.querySelector('[data-family-members]');
        var button = root.querySelector('[data-family-add-member]');

        if (container) {
            container.innerHTML = '';
        }

        if (button) {
            button.dataset.nextIndex = '0';
        }
    }

    // ---- AJAX submit -------------------------------------------------------

    function findCsrfInput(form) {
        var known = ['entry_type', 'form_mode', 'head_id'];

        return Array.from(form.querySelectorAll('input[type="hidden"]')).find(function (input) {
            return known.indexOf(input.name) === -1;
        }) || null;
    }

    function updateCsrf(form, hash) {
        if (!hash) {
            return;
        }

        var input = findCsrfInput(form);

        if (input) {
            input.value = hash;
        }
    }

    function showFamilyToast(message, isError, variant) {
        var toast = document.createElement('div');
        var alertClass = variant === 'warning'
            ? 'alert-warning'
            : (isError ? 'alert-danger' : 'alert-success');
        toast.className = 'alert ' + alertClass + ' family-toast shadow';
        toast.setAttribute('role', 'status');
        toast.textContent = message;
        document.body.appendChild(toast);

        window.setTimeout(function () {
            toast.style.transition = 'opacity 200ms ease';
            toast.style.opacity = '0';
            window.setTimeout(function () { toast.remove(); }, 220);
        }, 3200);
    }

    function showFormError(root, message) {
        var form = root.querySelector('form');

        if (!form) {
            return;
        }

        var alert = form.querySelector('[data-family-form-error]');

        if (!alert) {
            alert = document.createElement('div');
            alert.className = 'alert alert-danger';
            alert.setAttribute('data-family-form-error', '');
            form.insertBefore(alert, form.firstChild);
        }

        alert.textContent = message;
        alert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function closeFamilyModal() {
        var modalEl = document.getElementById('familyModal');

        if (modalEl && window.bootstrap && window.bootstrap.Modal) {
            modalEl.dataset.familyCloseConfirmed = '1';
            var instance = window.bootstrap.Modal.getInstance(modalEl);

            if (instance) {
                instance.hide();
            }
        }
    }

    // Mirrors FamilyController::firstIncompleteMember() so the worker sees the gap at
    // the field instead of after a round-trip. Same six fields, and the same
    // skip-if-unnamed rule as hasMemberData(): a row with no name is an empty row, not
    // an incomplete one. The server keeps its own copy and stays authoritative.
    var MEMBER_REQUIRED_FIELDS = ['birthday', 'sex', 'civilstatus', 'education', 'job', 'salary'];

    // Relationship is required on this form but NOT server-side: the Excel importer and
    // older records legitimately arrive without one, so the server keeps defaulting it
    // rather than rejecting them. Held apart from the list above so that stays an
    // honest mirror of firstIncompleteMember().
    var MEMBER_CLIENT_REQUIRED_FIELDS = MEMBER_REQUIRED_FIELDS.concat(['relationship']);

    function memberField(row, key) {
        return row.querySelector('[name="' + row.dataset.memberFieldPrefix + '[' + key + ']"]');
    }

    function validateMembers(root, form) {
        var first = null;

        Array.from(form.querySelectorAll('[data-family-member-row]')).forEach(function (row) {
            var data = readMemberData(row);
            var named = ['lastname', 'firstname'].some(function (key) {
                return String(data[key] || '').trim() !== '';
            });

            MEMBER_CLIENT_REQUIRED_FIELDS.forEach(function (key) {
                var empty = named && String(data[key] || '').trim() === '';

                if (row.dataset.familyMemberOpen === '1') {
                    setFieldError(memberField(row, key), empty ? 'This field is required.' : '');
                }

                if (empty && !first) {
                    first = { row: row, key: key };
                }
            });
        });

        if (!first) {
            return null;
        }

        // A complaint about a field the worker cannot see is useless, so the row opens.
        expandMemberRow(root, first.row);

        var field = memberField(first.row, first.key);
        setFieldError(field, 'This field is required.');

        return field;
    }

    function validateMemberContacts(root, form) {
        var firstRow = null;

        Array.from(form.querySelectorAll('[data-family-member-row]')).forEach(function (row) {
            var value = String(readMemberData(row).contactnumber || '').trim();

            if (row.dataset.familyMemberOpen === '1') {
                validateContact(memberField(row, 'contactnumber'));
            }

            if (!contactValueIsValid(value) && !firstRow) {
                firstRow = row;
            }
        });

        if (!firstRow) {
            return null;
        }

        expandMemberRow(root, firstRow);

        var field = memberField(firstRow, 'contactnumber');
        validateContact(field);

        return field;
    }

    // A save that goes nowhere used to explain itself only by focusing the first
    // bad field, which is invisible if that field is above the fold or inside a
    // member row further down. The action bar says how many are left, beside the
    // button the worker just pressed.
    //
    // Only counts once a save has actually been attempted: a fresh form is
    // entirely empty, and announcing "23 required fields are missing" before the
    // worker has typed anything is noise, not help.
    function reportSaveBlocked(form) {
        var reason = form.querySelector('[data-entry-blocked]');

        if (!reason || form.dataset.familySaveAttempted !== '1') {
            return;
        }

        // Skip fields inside a d-none container (the spine's locked sections while
        // the control-number gate is shut): the worker cannot see or fix them, so
        // counting them turns "1 field is missing" into a meaningless "23 required
        // fields are missing."
        var missing = Array.prototype.filter.call(
            form.querySelectorAll('[required]'),
            function (field) {
                return !field.checkValidity() && !field.closest('.d-none');
            }
        ).length;

        if (missing === 0) {
            reason.textContent = '';

            return;
        }

        reason.textContent = missing === 1
            ? '1 required field is missing.'
            : missing + ' required fields are missing.';
    }

    function submitFamilyForm(root, form) {
        form.dataset.familySaveAttempted = '1';

        // Deliberately not was-validated: that also paints every untouched optional
        // field green, which reads as "confirmed" on a box the worker never filled.
        // setFieldError's .is-invalid toggle is the only signal we want.
        if (!validateHead(root)) {
            reportSaveBlocked(form);

            return;
        }

        var badMember = validateMembers(root, form) || validateMemberContacts(root, form);

        if (badMember) {
            badMember.focus();
            badMember.scrollIntoView({ block: 'center' });
            reportSaveBlocked(form);

            return;
        }

        reportSaveBlocked(form);

        // Swap "Other" selects to their typed value so the custom text posts.
        applyOtherValues(form);

        // Record how many member rows we are posting so the server can detect a
        // submission truncated by PHP's max_input_vars (trailing members dropped).
        var memberCountField = form.querySelector('[data-members-count]');
        if (memberCountField) {
            memberCountField.value = String(form.querySelectorAll('[data-family-member-row]').length);
        }

        // Persist the exact state we are about to send so nothing is lost even if the
        // request dies mid-flight (browser/tab crash) before the 400ms auto-save fires.
        // Create mode only - edit mode keeps no draft (the draft key is global).
        if (isCreateForm(root)) {
            saveDraftNow(form);
        }

        var saveButton = root.querySelector('[data-family-save]');
        var originalLabel = saveButton ? saveButton.textContent : '';

        if (saveButton) {
            saveButton.disabled = true;
            saveButton.textContent = 'Saving...';
        }

        window.fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            }).catch(function () {
                return { ok: response.ok, data: {} };
            });
        }).then(function (result) {
            var data = result.data || {};

            updateCsrf(form, data.csrf);

            if (result.ok && data.status === 'success') {
                var mediaWarnings = Array.isArray(data.mediaWarnings)
                    ? data.mediaWarnings.filter(function (warning) {
                        return typeof warning === 'string' && warning.trim() !== '';
                    })
                    : [];
                var mediaWarningMessage = mediaWarnings.join(' ');

                if (isCreateForm(root)) {
                    clearDraft();
                }

                // The Data Entry page (records/entry) is a full navigation, not a modal
                // load: `redirect` is only ever set on that response (store()'s AJAX
                // branch), so following it is the page's only completion path. Keep an
                // optional-media warning visible long enough for the Encoder to read it
                // before navigating; the redirect target also renders the warning flash
                // for the non-AJAX and post-navigation paths.
                if (data.redirect) {
                    if (mediaWarningMessage) {
                        showFamilyToast(mediaWarningMessage, false, 'warning');
                        window.setTimeout(function () {
                            window.location.href = data.redirect;
                        }, 3200);
                    } else {
                        window.location.href = data.redirect;
                    }
                    return;
                }

                closeFamilyModal();

                if (typeof window.reloadFamilyDataTable === 'function') {
                    window.reloadFamilyDataTable();
                }

                showFamilyToast(
                    mediaWarningMessage || data.message || 'Family record saved successfully.',
                    false,
                    mediaWarningMessage ? 'warning' : undefined
                );
                return;
            }

            if (saveButton) {
                saveButton.disabled = false;
                saveButton.textContent = originalLabel;
            }

            if (data.code === 'FORM_TRUNCATED') {
                // Cutoff: the server saved nothing. Guarantee the typed data is in the
                // draft (create mode) and reassure the worker - their entries are safe
                // and the resume prompt will rebuild them on reopen. Not a normal error.
                if (isCreateForm(root)) {
                    saveDraftNow(form);
                }

                showFormError(root, data.message || 'The form was too large to send all at once. Nothing was saved, but your entries are kept on this computer.');
                askTruncationNotice(form);
                return;
            }

            showFormError(root, data.message || 'The family record could not be saved. Please review the form and try again.');
        }).catch(function () {
            if (saveButton) {
                saveButton.disabled = false;
                saveButton.textContent = originalLabel;
            }

            showFormError(root, 'A network error occurred. Please try again.');
        });
    }

    // ---- per-load initialisation -------------------------------------------

    // Every text answer is stored uppercase, so the field types uppercase: the
    // value that posts is the value on screen, rather than the worker typing
    // lowercase and the server quietly re-casing it on save. Delegated on the
    // form root so member rows added later are covered too.
    //
    // Only free-text inputs are touched. Dates, the numeric contact number and
    // the control number carry no case, and a search box filters against
    // labels the user reads in mixed case.
    function bindUppercaseTyping(root) {
        var SELECTOR = 'input[type="text"]:not([data-no-uppercase]), input:not([type])';

        root.addEventListener('input', function (event) {
            var field = event.target;

            if (!field.matches || !field.matches(SELECTOR) || field.readOnly || field.disabled) {
                return;
            }

            var upper = field.value.toUpperCase();

            if (upper === field.value) {
                return;
            }

            // Rewriting value moves the caret to the end, which makes editing the
            // middle of a name impossible, so it is put back where it was.
            var start = field.selectionStart;
            var end = field.selectionEnd;

            field.value = upper;

            if (start !== null && field.setSelectionRange) {
                field.setSelectionRange(start, end);
            }
        });
    }

    function initFamilyEntryModal(container) {
        var root = container.querySelector('[data-family-entry-form]');

        if (!root || root.dataset.familyEntryReady === '1') {
            return;
        }

        var modal = root.closest('#familyModal');

        if (modal) {
            modal.classList.add('is-family-entry-modal');
        }

        var title = modal ? modal.querySelector('#familyModalLabel') : null;

        if (title) {
            title.textContent = (root.dataset.familyModalTitle || '').trim() || 'New Family Record';
        }

        root.dataset.familyEntryReady = '1';

        bindUppercaseTyping(root);

        var formEl = root.querySelector('form');

        // Mark existing member rows (Update mode) with their posting prefix.
        Array.from(root.querySelectorAll('[data-family-member-row]')).forEach(function (row, index) {
            if (!row.dataset.memberFieldPrefix) {
                row.dataset.memberFieldPrefix = 'members[' + index + ']';
            }

            setMemberToggleState(row, row.dataset.familyMemberOpen === '1');
            renderMemberSummary(row);
        });

        initOtherSelects(root);

        // Live input: contact strip, Other reveal, required-clear, draft.
        root.addEventListener('input', function (event) {
            var target = event.target;

            if (isContactField(target)) {
                enforceContactDigits(target);
            }

            if (target && target.matches('[required]')) {
                clearFieldError(target);
            }

            if (target && target.name === 'qr_control_no') {
                scheduleQrAvailabilityCheck(root, target);
            }

            if (target && (target.name === 'head_birthday' || /\[birthday\]$/.test(target.name || ''))) {
                refreshAgeEligibility(target.closest('[data-family-member-row]') || root);
            }

            if (target && target.matches('[data-family-service-filter]')) {
                filterServiceAccordion(target);
            }

            if (target && /\[(lastname|firstname)\]$/.test(target.name || '')) {
                renumberMembers(root);
            }

            scheduleSave(root);
        });

        // Per-field validation on blur, so an error lands at the field the worker just
        // left instead of only after a submit. blur does not bubble, hence capture.
        root.addEventListener('blur', function (event) {
            var target = event.target;

            if (!target || !target.matches || !target.matches('input, select, textarea')) {
                return;
            }

            if (isContactField(target)) {
                validateContact(target);
                return;
            }

            if (target.matches('[required]')) {
                var isEmpty = String(target.value || '').trim() === '';
                var invalid = !target.checkValidity();

                setFieldError(target, invalid ? (isEmpty ? 'This field is required.' : target.validationMessage) : '');
            }
        }, true);

        root.addEventListener('change', function (event) {
            var target = event.target;

            if (target && target.matches('.js-other-select')) {
                syncOtherControl(target);
            }

            // Blank is a real answer for a middle name, so it gets said outright
            // instead of being typed as a placeholder. A disabled input posts
            // nothing, which is what an empty box would have posted anyway.
            if (target && target.matches('[data-family-no-middlename]')) {
                var middleColumn = target.closest('[class*="col-"]');
                // A member field is members[i][middlename], so it ends in a bracket:
                // matching on "middlename" alone finds the head field only.
                var middleName = middleColumn
                    ? middleColumn.querySelector('input[name="head_middlename"], input[name$="[middlename]"]')
                    : null;

                if (middleName) {
                    middleName.disabled = target.checked;

                    if (target.checked) {
                        middleName.value = '';
                        setFieldError(middleName, '');
                    }
                }
            }

            // Archived (grandfather) un-tick warning.
            if (target && target.matches('input[type="checkbox"][data-archived="1"]') && !target.checked) {
                askRemoveArchivedItem(formEl || root, target.dataset.label || 'this item').then(function (remove) {
                    if (!remove) {
                        target.checked = true;
                    }

                    // "Keep" re-checks asynchronously, after the sync refresh below already ran.
                    refreshServiceCategories(target.closest('[data-family-member-row]') || root);
                    refreshAllChoicesSummaries(root);
                    renumberMembers(root);
                    scheduleSave(root);
                });
            }

            if (target && (target.name === 'head_birthday' || /\[birthday\]$/.test(target.name || ''))) {
                refreshAgeEligibility(target.closest('[data-family-member-row]') || root);
            }

            if (target && (target.matches(SECTOR_INPUT_SELECTOR) || target.matches(SERVICE_INPUT_SELECTOR))) {
                refreshServiceCategories(target.closest('[data-family-member-row]') || root);
            }

            refreshAllChoicesSummaries(root);
            renumberMembers(root);
            scheduleSave(root);
        });

        // Clicking a category header is an explicit instruction, so that panel stops
        // following the sector-and-tick rule and stays where the worker put it.
        root.addEventListener('click', function (event) {
            var header = event.target.closest('.accordion-button');

            if (!header) {
                return;
            }

            // An empty selector makes querySelector throw, so bail before asking.
            var selector = header.getAttribute('data-bs-target') || '';

            if (selector === '') {
                return;
            }

            var panel = root.querySelector(selector);

            if (panel) {
                panel.dataset.familyUserToggled = '1';
            }
        }, true);

        // Add / remove repeatable family members.
        root.addEventListener('click', function (event) {
            var toggleButton = event.target.closest('[data-family-member-toggle]');

            if (toggleButton) {
                event.preventDefault();
                var toggleRow = toggleButton.closest('[data-family-member-row]');

                if (toggleRow) {
                    if (toggleRow.dataset.familyMemberOpen === '1') {
                        collapseMemberRow(toggleRow);
                    } else {
                        expandMemberRow(root, toggleRow);
                    }

                    scheduleSave(root);
                }

                return;
            }

            if (event.target.closest('[data-family-add-member]')) {
                event.preventDefault();
                addMemberRow(root);
                renumberMembers(root);
                scheduleSave(root);
                return;
            }

            var removeButton = event.target.closest('[data-family-member-remove]');

            if (removeButton) {
                event.preventDefault();
                var row = removeButton.closest('[data-family-member-row]');

                if (row) {
                    // Rows sit in a `col-12` grid cell (`[data-member-card]`); drop
                    // the cell with the row or the grid keeps an empty column.
                    var memberCard = row.closest('[data-member-card]');
                    (memberCard || row).remove();
                    renumberMembers(root);
                    scheduleSave(root);
                }

                return;
            }

            var setHeadButton = event.target.closest('[data-family-set-head]');

            if (setHeadButton) {
                event.preventDefault();
                var headRow = setHeadButton.closest('[data-family-member-row]');

                if (!headRow || !formEl) {
                    return;
                }

                var firstName = headRow.querySelector('[name$="[firstname]"]');
                var lastName = headRow.querySelector('[name$="[lastname]"]');
                var memberName = [
                    firstName ? String(firstName.value || '').trim() : '',
                    lastName ? String(lastName.value || '').trim() : ''
                ].filter(Boolean).join(' ');

                askPromoteToHead(formEl, memberName).then(function (confirmed) {
                    if (!confirmed) {
                        return;
                    }

                    expandMemberRow(root, headRow);
                    promoteMemberToHead(root, headRow);
                    scheduleSave(root);
                });
            }
        });

        if (formEl) {
            formEl.addEventListener('reset', function () {
                window.setTimeout(function () {
                    clearMemberRows(root);
                    initOtherSelects(root);
                    refreshAllAgeEligibility(root);
                    refreshAllServiceCategories(root);
                    refreshAllChoicesSummaries(root);
                    renumberMembers(root);

                    if (isCreateForm(root)) {
                        clearDraft();
                        setDraftStatus(formEl, '');
                    }
                }, 0);
            });

            formEl.addEventListener('submit', function (event) {
                event.preventDefault();
                submitFamilyForm(root, formEl);
            });

            // Keeps the blocked count honest as the worker fills fields in. No
            // debounce: it is a querySelectorAll over one form, cheaper than the
            // timer that would manage it.
            formEl.addEventListener('input', function () {
                reportSaveBlocked(formEl);
            });

            formEl.addEventListener('change', function () {
                reportSaveBlocked(formEl);
            });
        }
        refreshAllAgeEligibility(root);
        refreshAllServiceCategories(root);
        refreshAllChoicesSummaries(root);
        renumberMembers(root);

        // Restore-on-reopen prompt (create mode only). This call runs at page
        // load, while the entry page's control-number gate is still shut, so
        // offerDraftRestoreIfVisible's own gateShut check bails out here - the
        // officer has no control number yet to contextualise the draft
        // against. bindControlNumberGate calls this again once the gate
        // opens, and that call is the one that actually offers it (matching
        // the page's pre-spine behaviour). On Family/profile.php, which has
        // no gate, this call is the only one and fires normally.
        offerDraftRestoreIfVisible(root);
    }

    function offerDraftRestoreIfVisible(root) {
        // Defensive: root must be the [data-family-entry-form] element itself
        // (it has a classList/dataset to read), never the document it was found
        // in - a Document has neither and would throw on the check below.
        if (!root || root.nodeType !== 1) {
            return;
        }

        var formEl = root.querySelector('form');

        // The entry page's wrapper is never itself d-none (only the gated
        // sections inside it are, once the spine replaced the single hidden
        // body this page used to have), so that classList check no longer
        // suppresses anything here on its own. A shut control-number gate
        // does the suppressing instead: root.querySelector('[data-control-
        // number-gate]') is null on Family/profile.php (the edit page, which
        // shares this partial but has no gate), so gateShut is always false
        // there and this still offers a restore normally. Without this, an
        // officer who declines the prompt before entering a control number
        // loses the draft for good (askRestoreDraft's decline path calls
        // clearDraft()), and accepting binds head/member data to whichever
        // control number they type next - possibly a different record.
        var gate = root.querySelector('[data-control-number-gate]');
        var gateSections = root.querySelectorAll('[data-entry-section]');
        var gateShut = !!gate && gateSections.length > 0 && Array.prototype.every.call(gateSections, function (section) {
            return section.classList.contains('d-none');
        });

        if (!formEl || !isCreateForm(root) || root.classList.contains('d-none') || gateShut || root.dataset.familyDraftOffered === '1') {
            return;
        }

        var draft = readDraft();

        if (draftIsEmpty(draft)) {
            return;
        }

        root.dataset.familyDraftOffered = '1';

        askRestoreDraft(formEl, draft.savedAt).then(function (restore) {
            if (restore) {
                restoreDraftIntoForm(root, draft);
            } else {
                clearDraft();
                setDraftStatus(formEl, '');
            }
        });
    }

    // Intercept modal close: when an Add (create) form has unsaved work, ask the
    // worker to keep (save draft) or discard before the modal actually hides.
    function bindCloseGuard() {
        var modalEl = document.getElementById('familyModal');

        if (!modalEl || modalEl.dataset.familyCloseGuardBound === '1') {
            return;
        }

        modalEl.dataset.familyCloseGuardBound = '1';

        modalEl.addEventListener('hide.bs.modal', function (event) {
            if (modalEl.dataset.familyCloseConfirmed === '1') {
                delete modalEl.dataset.familyCloseConfirmed;
                return;
            }

            var root = modalEl.querySelector('[data-family-entry-form]');
            var form = root ? root.querySelector('form') : null;

            if (!root || !form || !isCreateForm(root)) {
                return;
            }

            var snapshot = snapshotForm(form);

            if (draftIsEmpty(snapshot)) {
                return;
            }

            event.preventDefault();

            askKeepOrDiscard(form).then(function (keep) {
                if (keep) {
                    saveDraftNow(form);
                } else {
                    clearDraft();
                }

                closeFamilyModal();
            });
        });
    }

    // ---- standalone Data Entry page (Family/entry) -------------------------
    // The entry page is a normal navigation, not a modal load, so nothing ever
    // fires registerDashboardModal's onLoaded callback for it. It wires itself
    // up on page load instead, then drives the control-number gate: the spine's
    // later sections ([data-entry-section]) keep d-none until the number checks
    // out available.

    function setGateStatus(field, status, text, isError) {
        field.classList.toggle('is-invalid', !!isError);

        if (status) {
            status.textContent = text;
            status.classList.toggle('text-danger', !!isError);
        }
    }

    function bindControlNumberGate(gate) {
        var field = gate.querySelector('#controlNumber');
        var status = gate.querySelector('[data-control-number-status]');
        var image = gate.querySelector('[data-control-number-qr]');
        // The spine interleaves headings with content, so the gated sections are
        // several nodes rather than one wrapper. [data-family-entry-form] sits on
        // the outer <div>, one level above the <form>, matching Family/profile.php:
        // seven call sites elsewhere in this file do root.querySelector('form') to
        // reach the <form> itself, and would silently find nothing if root WERE the
        // form. offerDraftRestoreIfVisible below and the one inside
        // initFamilyEntryModal still share one familyDraftOffered flag because both
        // resolve to this same root node.
        var sections = document.querySelectorAll('[data-entry-section]');
        var root = document.querySelector('[data-family-entry-form]');
        var timer = null;
        var sequence = 0;

        if (!field || !sections.length || !root) {
            return;
        }

        function setSectionsHidden(hidden) {
            Array.prototype.forEach.call(sections, function (section) {
                section.classList.toggle('d-none', hidden);
            });
        }

        // Mirrors the visually-hidden wording stepper.php itself prefixes onto a
        // step's label ('Completed, ' / 'Needs attention, '), so state here is
        // never colour alone either. 'Locked, ' is this page's own addition: the
        // two later steps are real links (so click-to-scroll and focus still
        // work), but their content is d-none until the gate opens, so a screen
        // reader needs to be told they currently do nothing.
        function setStepLink(step, opts) {
            var link = step.querySelector('.stepper-step-link');
            var prefix = step.querySelector('[data-step-state-prefix]');

            if (!link) {
                return;
            }

            if (opts.current) {
                link.setAttribute('aria-current', 'step');
            } else {
                link.removeAttribute('aria-current');
            }

            if (opts.disabled) {
                link.setAttribute('aria-disabled', 'true');
            } else {
                link.removeAttribute('aria-disabled');
            }

            if (prefix) {
                prefix.textContent = opts.prefixText || '';
            }
        }

        function setStepStates(unlocked) {
            var steps = document.querySelectorAll('#entrySpine .stepper-step');

            if (steps.length < 4) {
                return;
            }

            // Once the gate opens all later steps are editable, so none may keep
            // the muted 'upcoming' look: a step you can type into must not read as
            // locked. 'available' is the unlocked-but-not-focused state, and the
            // focus tracking below moves 'current' onto whichever one is in use.
            steps[0].setAttribute('data-state', unlocked ? 'done' : 'current');
            steps[1].setAttribute('data-state', unlocked ? 'current' : 'upcoming');
            steps[2].setAttribute('data-state', unlocked ? 'available' : 'upcoming');
            steps[3].setAttribute('data-state', unlocked ? 'available' : 'upcoming');

            setStepLink(steps[0], {
                current: !unlocked,
                disabled: false,
                prefixText: unlocked ? 'Completed, ' : ''
            });
            setStepLink(steps[1], {
                current: unlocked,
                disabled: !unlocked,
                prefixText: unlocked ? '' : 'Locked, '
            });
            setStepLink(steps[2], {
                current: false,
                disabled: !unlocked,
                prefixText: unlocked ? '' : 'Locked, '
            });
            setStepLink(steps[3], {
                current: false,
                disabled: !unlocked,
                prefixText: unlocked ? '' : 'Locked, '
            });
        }

        function clearQr() {
            if (image) {
                image.classList.add('d-none');
                image.removeAttribute('src');
                image.setAttribute('alt', '');
            }
        }

        function realControlNumberField() {
            return root.querySelector('[data-entry-control-number], [name="qr_control_no"]');
        }

        field.addEventListener('input', function () {
            setSectionsHidden(true);
            setStepStates(false);
            clearQr();
            setGateStatus(field, status, '', false);
            window.clearTimeout(timer);

            // A previous available check may have already written this control
            // number into the hidden field the form actually posts. Editing the
            // visible input to something else (even an invalid value the browser
            // still lets through, like a non-numeric string with no pattern to
            // reject it) must not leave that stale value behind - otherwise Save
            // stays enabled and the record saves under the abandoned number.
            var realField = realControlNumberField();

            if (realField) {
                realField.value = '';
            }

            var value = String(field.value || '').trim();
            var mySequence = ++sequence;

            if (value === '' || !field.checkValidity()) {
                return;
            }

            timer = window.setTimeout(function () {
                var url = new URL(gate.dataset.qrCheckUrl, window.location.href);
                url.searchParams.set('control_no', value);
                url.searchParams.set('head_id', '0');

                window.fetch(url.toString(), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                }).then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                }).then(function (result) {
                    if (mySequence !== sequence) {
                        return;
                    }

                    if (result.ok && result.data.available) {
                        setGateStatus(field, status, 'Control number is available.', false);
                        setSectionsHidden(false);
                        setStepStates(true);

                        // The preview is never a gate: a response without a `qr`
                        // field still unlocks the form.
                        if (image && result.data.qr) {
                            image.setAttribute('src', result.data.qr);
                            // The server formats the label the same way the printed card
                            // does (ControlNumber::format()); falling back to the raw typed
                            // value only covers an old response shape without the field.
                            image.setAttribute('alt', 'QR code for control number ' + (result.data.control_no_label || value));
                            image.classList.remove('d-none');
                        }

                        var realField = realControlNumberField();

                        if (realField) {
                            realField.value = value;
                        }

                        offerDraftRestoreIfVisible(root);
                    } else {
                        setGateStatus(field, status, (result.data && result.data.message) || 'The control number could not be validated.', true);
                    }
                }).catch(function () {
                    if (mySequence === sequence) {
                        setGateStatus(field, status, 'A network error occurred. Please try again.', true);
                    }
                });
            }, 350);
        });
    }

    function initFamilyEntryPage() {
        // Keyed on [data-family-entry-form] - the same marker initFamilyEntryModal()
        // looks for - rather than the control-number gate, so the Family Profile page
        // (Family/profile.php, which has no gate) wires up the same member-row/Other-
        // select/AJAX-submit behavior the Data Entry page gets. The gate step itself
        // only runs when a gate is actually present, so the entry page's gating is
        // unchanged.
        var root = document.querySelector('[data-family-entry-form]');

        if (!root) {
            return;
        }

        initFamilyEntryModal(document);

        var gate = document.querySelector('[data-control-number-gate]');

        if (gate) {
            bindControlNumberGate(gate);
            bindSpineFocusTracking();
        }
    }

    // Follows the caret down the spine: the step holding the focused control becomes
    // 'current' (the one with the halo) and its unlocked siblings fall back to
    // 'available'. A step still marked 'upcoming' is genuinely locked, so it is left
    // alone - this only reshuffles state among steps the gate has already opened.
    function bindSpineFocusTracking() {
        var spine = document.getElementById('entrySpine');

        if (!spine) {
            return;
        }

        spine.addEventListener('focusin', function (event) {
            var content = event.target.closest('.stepper-step-content');
            var focused = content ? content.closest('.stepper-step') : null;

            if (!focused || focused.getAttribute('data-state') === 'upcoming') {
                return;
            }

            Array.prototype.forEach.call(spine.querySelectorAll('.stepper-step'), function (step) {
                var state = step.getAttribute('data-state');

                if (state === 'upcoming' || state === 'error') {
                    return;
                }

                if (step === focused) {
                    step.setAttribute('data-state', 'current');
                    step.querySelector('.stepper-step-link').setAttribute('aria-current', 'step');

                    return;
                }

                // Step 1 stays 'done': its control number is filled in and checked,
                // which is a different thing from merely being reachable.
                if (state !== 'done') {
                    step.setAttribute('data-state', 'available');
                }

                step.querySelector('.stepper-step-link').removeAttribute('aria-current');
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindCloseGuard);
        document.addEventListener('DOMContentLoaded', initFamilyEntryPage);
    } else {
        bindCloseGuard();
        initFamilyEntryPage();
    }
})(window, document);

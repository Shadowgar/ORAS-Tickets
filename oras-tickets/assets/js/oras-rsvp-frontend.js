/* Minimal RSVP frontend script: intercept form submit, send via fetch, show inline notice, update UI */
(function () {
    'use strict';

    function ensureVirtualEmailModal(block) {
        var existing = block.querySelector('.oras-rsvp-email-modal');
        if (existing) {
            return existing;
        }

        var modal = document.createElement('div');
        modal.className = 'oras-rsvp-email-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = '' +
            '<div class="oras-rsvp-email-modal__backdrop"></div>' +
            '<div class="oras-rsvp-email-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="oras-rsvp-email-title">' +
                '<h3 id="oras-rsvp-email-title">Enter Email for RSVP Confirmation</h3>' +
                '<p>Please provide an email address. We will send event details for your selected attendance type.</p>' +
                '<label for="oras-rsvp-virtual-email-input">Email address</label>' +
                '<input id="oras-rsvp-virtual-email-input" type="email" class="oras-rsvp-email-input" autocomplete="email" required />' +
                '<div class="oras-rsvp-email-error" aria-live="polite"></div>' +
                '<div class="oras-rsvp-email-actions">' +
                    '<button type="button" class="oras-rsvp-button oras-rsvp-button-secondary" data-oras-email-close="1">Close</button>' +
                    '<button type="button" class="oras-rsvp-button oras-rsvp-button-primary" data-oras-email-submit="1">Submit</button>' +
                '</div>' +
            '</div>';

        block.appendChild(modal);
        return modal;
    }

    function openVirtualEmailModal(modal) {
        modal.setAttribute('aria-hidden', 'false');
        var input = modal.querySelector('.oras-rsvp-email-input');
        var error = modal.querySelector('.oras-rsvp-email-error');
        if (error) {
            error.textContent = '';
        }
        if (input) {
            input.value = '';
            input.focus();
        }
    }

    function closeVirtualEmailModal(modal) {
        modal.setAttribute('aria-hidden', 'true');
    }

    function attendanceModeLabel(mode) {
        if (mode === 'virtual') {
            return 'Virtual';
        }
        if (mode === 'onsite') {
            return 'On-site';
        }
        return '';
    }

    function statusClassName(status) {
        if (status === 'yes') {
            return 'oras-rsvp-status oras-rsvp-status-yes';
        }
        if (status === 'waitlist') {
            return 'oras-rsvp-status oras-rsvp-status-waitlist';
        }
        if (status === 'no' || status === 'none') {
            return 'oras-rsvp-status oras-rsvp-status-no';
        }

        return 'oras-rsvp-status';
    }

    function updateStatus(block, statusValue, message) {
        var status = block.querySelector('.oras-rsvp-status');
        if (!status) {
            return;
        }

        status.className = statusClassName(statusValue);
        if (statusValue === 'yes' || statusValue === 'waitlist') {
            status.innerHTML = '<strong>' + message + '</strong>';
            return;
        }

        status.textContent = message;
    }

    function findVirtualAccessElement() {
        var button = document.querySelector('.tribe-events-virtual-link-button');
        if (button) {
            return button;
        }

        return document.querySelector('.tribe-events-virtual-single-zoom-details, .tribe-events-virtual-single-api-details');
    }

    function moveVirtualAccessBlock(block) {
        if (!block || block.getAttribute('data-oras-virtual-moved') === '1') {
            return;
        }

        var ticketsSection = document.querySelector('.oras-tickets-section');
        var rsvpBlock = document.querySelector('.oras-rsvp-block');
        if (!rsvpBlock) {
            return;
        }

        var targetParent = rsvpBlock.parentNode;
        if (!targetParent) {
            return;
        }

        var movedBlock = block;
        if (block.classList && block.classList.contains('tribe-events-virtual-link-button')) {
            var wrapper = document.createElement('div');
            wrapper.className = 'oras-virtual-access-primary';
            wrapper.setAttribute('data-oras-virtual-moved', '1');
            wrapper.appendChild(block);
            movedBlock = wrapper;
        }

        if (ticketsSection && ticketsSection.parentNode === targetParent) {
            ticketsSection.insertAdjacentElement('afterend', movedBlock);
        } else {
            targetParent.insertBefore(movedBlock, rsvpBlock);
        }

        block.setAttribute('data-oras-virtual-moved', '1');
    }

    function initVirtualAccessPlacement() {
        function tryMoveVirtualAccess() {
            var virtualBlock = findVirtualAccessElement();

            if (!virtualBlock || !document.querySelector('.oras-rsvp-block')) {
                return false;
            }

            moveVirtualAccessBlock(virtualBlock);
            return virtualBlock.getAttribute('data-oras-virtual-moved') === '1';
        }

        if (tryMoveVirtualAccess()) {
            return;
        }

        var attempts = 0;
        var maxAttempts = 20;
        var interval = window.setInterval(function () {
            attempts += 1;

            if (tryMoveVirtualAccess() || attempts >= maxAttempts) {
                window.clearInterval(interval);
            }
        }, 300);

        if (!window.MutationObserver || !document.body) {
            return;
        }

        var observer = new MutationObserver(function () {
            if (tryMoveVirtualAccess()) {
                observer.disconnect();
                window.clearInterval(interval);
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        window.setTimeout(function () {
            observer.disconnect();
            window.clearInterval(interval);
        }, 10000);
    }

    function initBlock(block) {
        if (!block) return;
        var form = block.querySelector('form');
        var notice = block.querySelector('.oras-rsvp-ajax-notice');
        var virtualEmailModal = ensureVirtualEmailModal(block);
        var lastIntentButton = null;
        if (!form) return;

        function submitRsvpAjax(submitter) {
            var fd = new FormData();
            var elts = form.elements;
            var i;
            for (i = 0; i < elts.length; i++) {
                var e = elts[i];
                if (!e.name) continue;
                if (e.type === 'checkbox' || e.type === 'radio') {
                    if (!e.checked) continue;
                }
                fd.append(e.name, e.value);
            }

            try {
                if (submitter && submitter.name && submitter.value) {
                    fd.set(submitter.name, submitter.value);
                }
            } catch (err) {
                // ignore
            }
            fd.append('oras_ajax', '1');

            var postUrl = form.getAttribute('action') || '';
            if (!postUrl) {
                postUrl = window.location.href;
            }

            fetch(postUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (r) {
                return r.json();
            }).then(function (data) {
                if (!notice) return;
                notice.innerHTML = '';
                if (data && data.success) {
                    var msg = (data.data && data.data.message) ? data.data.message : 'RSVP updated.';
                    var noticeMessage = (data.data && data.data.notice) ? data.data.notice : 'Your RSVP was updated.';
                    var el = document.createElement('div');
                    el.className = 'oras-rsvp-notice oras-rsvp-notice-success';
                    el.textContent = noticeMessage;
                    notice.appendChild(el);

                    var badge = block.querySelector('.oras-rsvp-badge');
                    var yes = form.querySelector('button[name="intent"][value="yes"]');
                    var no = form.querySelector('button[name="intent"][value="no"]');

                    var s = data.data && data.data.status ? data.data.status : null;
                    if (s === 'none' || s === null && msg.toLowerCase().indexOf('removed') !== -1) {
                        updateStatus(block, 'no', 'You are not attending this event.');
                        if (badge && badge.parentNode) {
                            badge.parentNode.removeChild(badge);
                        }
                        if (yes) {
                            yes.disabled = false;
                            yes.removeAttribute('aria-pressed');
                        }
                    } else if (s === 'yes') {
                        var attendanceMode = data.data && data.data.attendance_mode ? attendanceModeLabel(data.data.attendance_mode) : '';
                        updateStatus(block, 'yes', msg);
                        if (yes) {
                            yes.disabled = true;
                            yes.setAttribute('aria-pressed', 'true');
                        }
                        if (no) {
                            no.disabled = false;
                            no.removeAttribute('aria-pressed');
                        }
                        if (!badge) {
                            var span = document.createElement('span');
                            span.className = 'oras-rsvp-badge';
                            span.textContent = attendanceMode ? 'Status: RSVPed for ' + attendanceMode + ' ✅' : 'Status: You are RSVPed ✅';
                            var status = block.querySelector('.oras-rsvp-status');
                            if (status && status.parentNode) {
                                status.parentNode.insertBefore(span, status.nextSibling);
                            }
                        } else if (attendanceMode) {
                            badge.textContent = 'Status: RSVPed for ' + attendanceMode + ' ✅';
                        }

                        window.location.reload();
                        return;
                    } else if (s === 'waitlist') {
                        updateStatus(block, 'waitlist', msg);
                        if (yes) {
                            yes.disabled = false;
                            yes.removeAttribute('aria-pressed');
                        }
                        window.location.reload();
                        return;
                    } else {
                        updateStatus(block, s, msg);
                    }
                } else {
                    var err = (data && data.data && data.data.message) ? data.data.message : 'Unable to update RSVP.';
                    if (err.indexOf('Please enter a valid email address to receive event details.') !== -1) {
                        openVirtualEmailModal(virtualEmailModal);
                        return;
                    }
                    var el = document.createElement('div');
                    el.className = 'oras-rsvp-notice oras-rsvp-notice-error';
                    el.textContent = err;
                    notice.appendChild(el);
                }
            }).catch(function () {
                if (!notice) return;
                var el = document.createElement('div');
                el.className = 'oras-rsvp-notice oras-rsvp-notice-error';
                el.textContent = 'Unable to update RSVP.';
                notice.appendChild(el);
            });
        }

        form.addEventListener('submit', function (ev) {
            // Allow normal submission for non-JS or when user holds modifier keys
            if (ev.shiftKey || ev.altKey || ev.ctrlKey || ev.metaKey) return;
            ev.preventDefault();

            var submitter = ev.submitter || lastIntentButton || document.activeElement;
            var checkedAttendance = form.querySelector('input[name="attendance_mode"]:checked');
            var attendanceMode = checkedAttendance ? checkedAttendance.value : '';
            var intent = submitter && submitter.name === 'intent' ? submitter.value : '';

            if (intent === 'yes') {
                openVirtualEmailModal(virtualEmailModal);
                return;
            }

            submitRsvpAjax(submitter);
        }, false);

        var intentButtons = form.querySelectorAll('button[name="intent"]');
        for (var idx = 0; idx < intentButtons.length; idx++) {
            intentButtons[idx].addEventListener('click', function (ev) {
                lastIntentButton = ev.currentTarget;
            });
        }

        virtualEmailModal.addEventListener('click', function (ev) {
            var target = ev.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }

            if (target.getAttribute('data-oras-email-close') === '1') {
                closeVirtualEmailModal(virtualEmailModal);
            }
        });

        var virtualSubmit = virtualEmailModal.querySelector('[data-oras-email-submit="1"]');
        if (virtualSubmit) {
            virtualSubmit.addEventListener('click', function () {
                var input = virtualEmailModal.querySelector('.oras-rsvp-email-input');
                var error = virtualEmailModal.querySelector('.oras-rsvp-email-error');
                var email = input && typeof input.value === 'string' ? input.value.trim() : '';
                var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailPattern.test(email)) {
                    if (error) {
                        error.textContent = 'Please enter a valid email address.';
                    }
                    return;
                }

                var hiddenEmail = form.querySelector('input[name="virtual_email"]');
                if (!hiddenEmail) {
                    hiddenEmail = document.createElement('input');
                    hiddenEmail.type = 'hidden';
                    hiddenEmail.name = 'virtual_email';
                    form.appendChild(hiddenEmail);
                }
                hiddenEmail.value = email;

                closeVirtualEmailModal(virtualEmailModal);
                submitRsvpAjax(form.querySelector('button[name="intent"][value="yes"]'));
            });
        }
    }

    function init() {
        initVirtualAccessPlacement();

        var blocks = document.querySelectorAll('.oras-rsvp-block');
        for (var i = 0; i < blocks.length; i++) {
            initBlock(blocks[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

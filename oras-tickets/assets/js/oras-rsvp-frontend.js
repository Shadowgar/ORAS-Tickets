/* Minimal RSVP frontend script: intercept form submit, send via fetch, show inline notice, update UI */
(function () {
    'use strict';

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
        var virtualBlock = findVirtualAccessElement();
        if (!virtualBlock) {
            return;
        }

        moveVirtualAccessBlock(virtualBlock);
    }

    function initBlock(block) {
        if (!block) return;
        var form = block.querySelector('form');
        var notice = block.querySelector('.oras-rsvp-ajax-notice');
        if (!form) return;

        form.addEventListener('submit', function (ev) {
            // Allow normal submission for non-JS or when user holds modifier keys
            if (ev.shiftKey || ev.altKey || ev.ctrlKey || ev.metaKey) return;
            ev.preventDefault();

            // Ensure we capture which submit button was used (intent)
            var submitter = ev.submitter || document.activeElement;
            var fd = new FormData();
            // copy form fields
            var elts = form.elements;
            for (var i = 0; i < elts.length; i++) {
                var e = elts[i];
                if (!e.name) continue;
                if (e.type === 'checkbox' || e.type === 'radio') {
                    if (!e.checked) continue;
                }
                fd.append(e.name, e.value);
            }
            // ensure intent is present (from the clicked button)
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
                        // Show not attending state
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
                        // add badge if missing
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
                    } else if (s === 'waitlist') {
                        updateStatus(block, 'waitlist', msg);
                        if (yes) {
                            yes.disabled = false;
                            yes.removeAttribute('aria-pressed');
                        }
                    } else {
                        updateStatus(block, s, msg);
                    }
                } else {
                    var err = (data && data.data && data.data.message) ? data.data.message : 'Unable to update RSVP.';
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
        }, false);
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

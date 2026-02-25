/* Minimal RSVP frontend script: intercept form submit, send via fetch, show inline notice, update UI */
(function () {
    'use strict';

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

            fetch(form.action, {
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
                    var el = document.createElement('div');
                    el.className = 'oras-rsvp-notice oras-rsvp-notice-success';
                    el.textContent = msg;
                    notice.appendChild(el);

                    var status = block.querySelector('.oras-rsvp-status');
                    var badge = block.querySelector('.oras-rsvp-badge');
                    var yes = form.querySelector('button[name="intent"][value="yes"]');
                    var no = form.querySelector('button[name="intent"][value="no"]');

                    var s = data.data && data.data.status ? data.data.status : null;
                    if (s === 'none' || s === null && msg.toLowerCase().indexOf('removed') !== -1) {
                        // Show not attending state
                        if (status) {
                            status.innerHTML = '<p class="oras-rsvp-status oras-rsvp-status-no">' + 'You are not attending this event.' + '</p>';
                        }
                        if (badge && badge.parentNode) {
                            badge.parentNode.removeChild(badge);
                        }
                        if (yes) {
                            yes.disabled = false;
                            yes.removeAttribute('aria-pressed');
                        }
                    } else if (s === 'yes') {
                        if (status) {
                            status.innerHTML = '';
                            var p = document.createElement('p');
                            p.className = 'oras-rsvp-status oras-rsvp-status-yes';
                            p.innerHTML = '<strong>' + msg + '</strong>';
                            status.appendChild(p);
                        }
                        if (yes) {
                            yes.disabled = true;
                            yes.setAttribute('aria-pressed', 'true');
                        }
                        // add badge if missing
                        if (!badge) {
                            var span = document.createElement('span');
                            span.className = 'oras-rsvp-badge';
                            span.style.cssText = 'display:inline-block;margin-left:8px;padding:2px 6px;background:#e6ffed;border:1px solid #bdeccf;border-radius:4px;font-size:90%';
                            span.textContent = 'Status: You are RSVPed ✅';
                            if (status && status.parentNode) {
                                status.parentNode.insertBefore(span, status.nextSibling);
                            }
                        }
                    } else if (s === 'waitlist') {
                        if (status) {
                            status.innerHTML = '<p class="oras-rsvp-status oras-rsvp-status-waitlist"><strong>' + msg + '</strong></p>';
                        }
                    } else {
                        // fallback: show message in status
                        if (status) {
                            status.innerHTML = '<p class="oras-rsvp-status">' + msg + '</p>';
                        }
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

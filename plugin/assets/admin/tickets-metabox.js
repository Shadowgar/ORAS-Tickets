(function(){
    'use strict';

    function nextIndex(){
        var inputs = document.querySelectorAll('input[name="oras_tickets_index[]"]');
        var max = -1;
        for (var i=0;i<inputs.length;i++){
            var v = parseInt(inputs[i].value,10);
            if (!isNaN(v) && v > max) max = v;
        }
        return max + 1;
    }

    function replaceIndexTokens(fragment, idx){
        var walker = document.createTreeWalker(fragment, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, null);
        var node = walker.currentNode;
        while ( node ) {
            if ( node.nodeType === Node.ELEMENT_NODE ) {
                var attrs = node.attributes;
                if ( attrs ) {
                    for ( var i = 0; i < attrs.length; i++ ) {
                        var attr = attrs[i];
                        if ( attr && attr.value && attr.value.indexOf('__INDEX__') !== -1 ) {
                            node.setAttribute(attr.name, attr.value.replace(/__INDEX__/g, idx));
                        }
                    }
                }
            } else if ( node.nodeType === Node.TEXT_NODE ) {
                if ( node.nodeValue && node.nodeValue.indexOf('__INDEX__') !== -1 ) {
                    node.nodeValue = node.nodeValue.replace(/__INDEX__/g, idx);
                }
            }
            node = walker.nextNode();
        }
    }

    function activateTicket(idx){
        var rows = document.querySelectorAll('#oras-tickets-table .oras-ticket-row');
        var activeRow = null;
        for (var i = 0; i < rows.length; i++){
            var rowIndex = rows[i].getAttribute('data-index');
            var isActive = String(rowIndex) === String(idx);
            var rowPanel = rows[i].querySelector('.oras-ticket-panel');
            rows[i].style.display = isActive ? 'block' : 'none';
            if ( rowPanel ) {
                rowPanel.style.display = isActive ? 'block' : 'none';
                rowPanel.classList.remove('is-active');
                rowPanel.classList.add('is-hidden');
            }
            if ( isActive ) {
                activeRow = rows[i];
            }
        }

        if ( activeRow ) {
            var panel = activeRow.querySelector('.oras-ticket-panel');
            if ( panel ) {
                panel.style.display = 'block';
                panel.classList.add('is-active');
                panel.classList.remove('is-hidden');
            }

            var panelWrap = activeRow.querySelector('.panel-wrap');
            if ( panelWrap && ! panelWrap.querySelector('.wc-tabs li.active') ) {
                initInnerTabs(panelWrap);
            }
        }

        var tabs = document.querySelectorAll('#oras-ticket-tabs .oras-ticket-tab');
        for (var j = 0; j < tabs.length; j++){
            tabs[j].classList.remove('button-primary');
            tabs[j].classList.remove('is-active');
        }
        var activeTab = document.querySelector('#oras-ticket-tabs .oras-ticket-tab[data-index="' + idx + '"]');
        if ( activeTab ) {
            activeTab.classList.add('button-primary');
            activeTab.classList.add('is-active');
        }
    }

    function initInnerTabs(panelWrap){
        if ( ! panelWrap ) {
            return;
        }
        var tabs = panelWrap.querySelectorAll('.wc-tabs li');
        for ( var i = 0; i < tabs.length; i++ ) {
            tabs[i].classList.remove('active');
        }
        var firstTab = panelWrap.querySelector('.wc-tabs li');
        if ( firstTab ) {
            firstTab.classList.add('active');
        }
        var panels = panelWrap.querySelectorAll('.panel');
        for ( var j = 0; j < panels.length; j++ ) {
            panels[j].style.display = 'none';
        }
        if ( firstTab ) {
            var link = firstTab.querySelector('a');
            if ( link ) {
                var targetId = link.getAttribute('href');
                if ( targetId ) {
                    var targetPanel = panelWrap.querySelector(targetId);
                    if ( targetPanel ) {
                        targetPanel.style.display = 'block';
                    }
                }
            }
        }
    }

    function syncPhaseToggle(phaseItem){
        if ( ! phaseItem ) {
            return;
        }
        var toggle = phaseItem.querySelector('.oras-phase-toggle');
        if ( ! toggle ) {
            return;
        }
        if ( phaseItem.classList.contains('is-collapsed') ) {
            toggle.textContent = 'Advanced';
        } else {
            toggle.textContent = 'Hide advanced';
        }
    }

    function initPhaseToggles(scope){
        var root = scope || document;
        var items = root.querySelectorAll('.oras-phase-item');
        for ( var i = 0; i < items.length; i++ ) {
            syncPhaseToggle(items[i]);
        }
    }

    function parseLocalDateTime(value){
        if ( ! value ) {
            return null;
        }
        var dt = new Date(value);
        if ( isNaN(dt.getTime()) ) {
            return null;
        }
        return dt.getTime();
    }

    function getSaleStatus(startValue, endValue){
        var startTs = parseLocalDateTime(startValue);
        var endTs = parseLocalDateTime(endValue);
        if ( startTs === null && endTs === null ) {
            return 'Always';
        }
        var now = Date.now();
        if ( startTs !== null && now < startTs ) {
            return 'Scheduled';
        }
        if ( endTs !== null && now > endTs ) {
            return 'Ended';
        }
        return 'On sale';
    }

    function ensureTabSpans(tab){
        var title = tab.querySelector('.oras-ticket-tab-title');
        var meta = tab.querySelector('.oras-ticket-tab-meta');
        if ( title && meta ) {
            return { title: title, meta: meta };
        }
        tab.innerHTML = '';
        title = document.createElement('span');
        title.className = 'oras-ticket-tab-title';
        meta = document.createElement('span');
        meta.className = 'oras-ticket-tab-meta';
        tab.appendChild(title);
        tab.appendChild(meta);
        return { title: title, meta: meta };
    }

    function updateTicketTab(panel){
        if ( ! panel ) {
            return;
        }
        var idx = panel.getAttribute('data-index');
        if ( idx === null ) {
            return;
        }
        var tab = document.querySelector('#oras-ticket-tabs .oras-ticket-tab[data-index="' + idx + '"]');
        if ( ! tab ) {
            return;
        }

        var nameInput = panel.querySelector('input[name="oras_tickets_tickets[' + idx + '][name]"]');
        var priceInput = panel.querySelector('input[name="oras_tickets_tickets[' + idx + '][price]"]');
        var startInput = panel.querySelector('input[name="oras_tickets_tickets[' + idx + '][sale_start]"]');
        var endInput = panel.querySelector('input[name="oras_tickets_tickets[' + idx + '][sale_end]"]');

        var titleText = nameInput && nameInput.value ? nameInput.value : 'Ticket #' + idx;
        var priceValue = priceInput && priceInput.value ? priceInput.value : '0.00';
        var status = getSaleStatus(startInput ? startInput.value : '', endInput ? endInput.value : '');

        var spans = ensureTabSpans(tab);
        spans.title.textContent = titleText;
        spans.meta.textContent = '$' + priceValue + ' • ' + status;
    }

    function hasPhaseInputData(phaseItem){
        if ( ! phaseItem ) {
            return false;
        }
        var inputs = phaseItem.querySelectorAll('input[type="text"]');
        for ( var i = 0; i < inputs.length; i++ ) {
            if ( (inputs[i].value || '').trim() !== '' ) {
                return true;
            }
        }
        return false;
    }

    function humanizeKey(value){
        var text = (value || '').replace(/[_-]+/g, ' ').trim();
        if ( text === '' ) {
            return '';
        }
        return text.replace(/\w\S*/g, function(word){
            return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
        });
    }

    function init() {
        var addBtn = document.getElementById('oras-add-ticket');
        if ( addBtn ) {
            addBtn.addEventListener('click', function(){
                var tpl = document.getElementById('oras-ticket-template');
                if ( ! tpl ) return;
                var idx = nextIndex();
                var tbody = document.querySelector('#oras-tickets-table tbody');
                if ( ! tbody ) return;
                var fragment = tpl.content.cloneNode(true);
                replaceIndexTokens(fragment, idx);
                var row = fragment.querySelector('tr.oras-ticket-row');
                if ( ! row ) return;
                tbody.appendChild(row);

                var tabList = document.getElementById('oras-ticket-tabs');
                if ( tabList ) {
                    var li = document.createElement('li');
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'oras-ticket-tab';
                    btn.setAttribute('data-index', idx);
                    btn.style.width = '100%';
                    btn.style.textAlign = 'left';
                    var spans = ensureTabSpans(btn);
                    spans.title.textContent = 'Ticket #' + idx;
                    spans.meta.textContent = '$0.00 • Always';
                    li.appendChild(btn);
                    tabList.appendChild(li);
                }

                var panelWrap = row.querySelector('.panel-wrap');
                initInnerTabs(panelWrap);
                activateTicket(String(idx));
                initPhaseToggles(row);
            });
        }

        var metabox = document.getElementById('oras-tickets-metabox');
        if ( metabox ) {
            var tabsList = document.getElementById('oras-ticket-tabs');
            if ( tabsList ) {
                tabsList.addEventListener('click', function(e){
                    var t = e.target || e.srcElement;
                    var btn = t && t.closest ? t.closest('.oras-ticket-tab') : null;
                    if ( ! btn ) {
                        return;
                    }
                    var idx = btn.dataset ? btn.dataset.index : btn.getAttribute('data-index');
                    if ( idx === null || typeof idx === 'undefined' ) {
                        return;
                    }
                    activateTicket(idx);
                });
            }

            metabox.addEventListener('click', function(e){
                var t = e.target || e.srcElement;
                if ( t && t.classList && t.classList.contains('oras-phase-toggle') ) {
                    e.preventDefault();
                    var phaseItem = t.closest('.oras-phase-item');
                    if ( phaseItem ) {
                        phaseItem.classList.toggle('is-collapsed');
                        syncPhaseToggle(phaseItem);
                    }
                    return;
                }
                if ( t && t.tagName && t.tagName.toLowerCase() === 'a' && t.closest('.oras-ticket-data') && t.closest('.wc-tabs') ) {
                    e.preventDefault();

                    var panelWrap = t.closest('.panel-wrap');
                    if ( ! panelWrap ) {
                        return;
                    }

                    var tabs = panelWrap.querySelectorAll('.wc-tabs li');
                    for ( var i = 0; i < tabs.length; i++ ) {
                        tabs[i].classList.remove('active');
                    }

                    var tabItem = t.closest('li');
                    if ( tabItem ) {
                        tabItem.classList.add('active');
                    }

                    var panels = panelWrap.querySelectorAll('.panel');
                    for ( var j = 0; j < panels.length; j++ ) {
                        panels[j].style.display = 'none';
                    }

                    var targetId = t.getAttribute('href');
                    if ( targetId ) {
                        var targetPanel = panelWrap.querySelector(targetId);
                        if ( targetPanel ) {
                            targetPanel.style.display = 'block';
                        }
                    }

                    return;
                }
                if ( t && t.classList && t.classList.contains('oras-remove-ticket') ) {
                    var panel = t.closest('.oras-ticket-panel');
                    var idx = panel ? panel.getAttribute('data-index') : null;
                    if ( idx === null ) {
                        return;
                    }

                    var row = document.querySelector('#oras-tickets-table .oras-ticket-row[data-index="' + idx + '"]');
                    if ( row && row.parentNode ) {
                        row.parentNode.removeChild(row);
                    }

                    var tab = document.querySelector('#oras-ticket-tabs .oras-ticket-tab[data-index="' + idx + '"]');
                    if ( tab ) {
                        var li = tab.closest('li');
                        if ( li && li.parentNode ) {
                            li.parentNode.removeChild(li);
                        } else if ( tab.parentNode ) {
                            tab.parentNode.removeChild(tab);
                        }
                    }

                    var hidden = document.querySelector('input[name="oras_tickets_index[]"][value="' + idx + '"]');
                    if ( hidden && hidden.parentNode ) {
                        hidden.parentNode.removeChild(hidden);
                    }

                    var emptyState = document.getElementById('oras-tickets-empty');
                    var table = document.getElementById('oras-tickets-table');
                    var remaining = document.querySelectorAll('#oras-tickets-table .oras-ticket-row');
                    if ( remaining.length > 0 ) {
                        if ( table ) {
                            table.style.display = 'block';
                        }
                        if ( emptyState ) {
                            emptyState.style.display = 'none';
                        }
                        var firstIdx = remaining[0].getAttribute('data-index');
                        if ( firstIdx !== null ) {
                            activateTicket(firstIdx);
                        }
                    } else {
                        if ( table ) {
                            table.style.display = 'none';
                        }
                        if ( emptyState ) {
                            emptyState.style.display = 'block';
                        }
                    }
                }

                if ( t && t.classList && t.classList.contains('oras-phase-remove') ) {
                    var phaseRow = t.closest('.oras-phase-item');
                    if ( phaseRow && phaseRow.parentNode ) {
                        if ( hasPhaseInputData(phaseRow) ) {
                            if ( ! window.confirm('Remove this pricing phase?') ) {
                                return;
                            }
                        }
                        phaseRow.parentNode.removeChild(phaseRow);
                    }
                }

                if ( t && t.classList && t.classList.contains('oras-phase-add') ) {
                    var ticketRow = t.closest('tr.oras-ticket-row');
                    if ( ! ticketRow ) {
                        return;
                    }
                    var template = ticketRow.querySelector('template.oras-phase-template');
                    if ( ! template ) {
                        return;
                    }
                    var list = ticketRow.querySelector('.oras-phase-list');
                    if ( ! list ) {
                        return;
                    }

                    var max = -1;
                    var rows = list.querySelectorAll('[data-phase-index]');
                    for ( var i = 0; i < rows.length; i++ ) {
                        var v = parseInt(rows[i].getAttribute('data-phase-index'), 10);
                        if ( ! isNaN(v) && v > max ) {
                            max = v;
                        }
                    }
                    var next = max + 1;

                    var content = template.innerHTML || '';
                    var html = content.replace(/__PHASE__/g, next);
                    var temp = document.createElement('div');
                    temp.innerHTML = html;
                    if ( temp.firstElementChild ) {
                        var newPhase = temp.firstElementChild;
                        newPhase.classList.add('is-collapsed');
                        list.appendChild(newPhase);
                        syncPhaseToggle(newPhase);

                        var keyInput = newPhase.querySelector('input[name*="[key]"]');
                        if ( keyInput ) {
                            keyInput.focus();
                        } else {
                            var labelInput = newPhase.querySelector('input[name*="[label]"]');
                            if ( labelInput ) {
                                labelInput.focus();
                            }
                        }
                    }
                }
            });

            metabox.addEventListener('focusout', function(e){
                var t = e.target || e.srcElement;
                if ( ! t || ! t.name || t.name.indexOf('[price_phases]') === -1 ) {
                    return;
                }
                if ( t.name.slice(-5) !== '[key]' ) {
                    return;
                }
                var phaseItem = t.closest('.oras-phase-item');
                if ( ! phaseItem ) {
                    return;
                }
                var labelInput = phaseItem.querySelector('input[name*="[label]"]');
                if ( labelInput && (labelInput.value || '').trim() === '' ) {
                    var suggestion = humanizeKey(t.value || '');
                    if ( suggestion !== '' ) {
                        labelInput.value = suggestion;
                    }
                }
            });

            var updateHandler = function(e){
                var t = e.target || e.srcElement;
                if ( ! t || ! t.name || t.name.indexOf('oras_tickets_tickets[') !== 0 ) {
                    return;
                }
                if ( t.name.indexOf('[name]') === -1 && t.name.indexOf('[price]') === -1 && t.name.indexOf('[sale_start]') === -1 && t.name.indexOf('[sale_end]') === -1 ) {
                    return;
                }
                var panel = t.closest('.oras-ticket-panel');
                if ( panel ) {
                    updateTicketTab(panel);
                }
            };

            metabox.addEventListener('input', updateHandler);
            metabox.addEventListener('change', updateHandler);
            initPhaseToggles(metabox);
            if ( tabsList ) {
                var firstTab = tabsList.querySelector('.oras-ticket-tab');
                if ( firstTab ) {
                    var firstIdx = firstTab.getAttribute('data-index');
                    if ( firstIdx !== null ) {
                        activateTicket(firstIdx);
                        var firstPanel = document.querySelector('#oras-tickets-table .oras-ticket-panel[data-index="' + firstIdx + '"]');
                        updateTicketTab(firstPanel);
                    }
                }
            }
        }
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

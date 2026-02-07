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

    function init() {
        var addBtn = document.getElementById('oras-add-ticket');
        if ( addBtn ) {
            addBtn.addEventListener('click', function(){
                var tpl = document.getElementById('oras-ticket-template');
                if ( ! tpl ) return;
                var idx = nextIndex();
                // Use template innerHTML so the template element itself is not submitted.
                var content = tpl.innerHTML || '';
                var html = content.replace(/__INDEX__/g, idx);
                var tbody = document.querySelector('#oras-tickets-table tbody');
                if ( ! tbody ) return;
                var temp = document.createElement('tbody');
                temp.innerHTML = html;
                tbody.appendChild(temp.firstElementChild);
            });
        }

        var metabox = document.getElementById('oras-tickets-metabox');
        if ( metabox ) {
            metabox.addEventListener('click', function(e){
                var t = e.target || e.srcElement;
                if ( t && t.classList && t.classList.contains('oras-remove-ticket') ) {
                    var row = t.closest('tr');
                    if ( row && row.parentNode ) {
                        row.parentNode.removeChild(row);
                    }
                }

                if ( t && t.classList && t.classList.contains('oras-phase-remove') ) {
                    var phaseRow = t.closest('tr');
                    if ( phaseRow && phaseRow.parentNode ) {
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
                    var tbody = ticketRow.querySelector('.oras-phase-table tbody');
                    if ( ! tbody ) {
                        return;
                    }

                    var max = -1;
                    var rows = tbody.querySelectorAll('tr[data-phase-index]');
                    for ( var i = 0; i < rows.length; i++ ) {
                        var v = parseInt(rows[i].getAttribute('data-phase-index'), 10);
                        if ( ! isNaN(v) && v > max ) {
                            max = v;
                        }
                    }
                    var next = max + 1;

                    var content = template.innerHTML || '';
                    var html = content.replace(/__PHASE__/g, next);
                    var temp = document.createElement('tbody');
                    temp.innerHTML = html;
                    if ( temp.firstElementChild ) {
                        tbody.appendChild(temp.firstElementChild);
                    }
                }
            });
        }
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

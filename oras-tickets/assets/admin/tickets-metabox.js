(function () {
	'use strict';

	function nextIndex(){
		var inputs = document.querySelectorAll( 'input[name="oras_tickets_index[]"]' );
		var max    = -1;
		for ( var input of inputs ) {
			var v = Number.parseInt( input.value, 10 );
			if ( ! Number.isNaN( v ) && v > max ) {
				max = v;
			}
		}
		return max + 1;
	}

	function replaceTokenInFragment(fragment, token, value){
		var walker = document.createTreeWalker( fragment, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, null );
		var node   = walker.currentNode;
		while ( node ) {
			if ( node.nodeType === Node.ELEMENT_NODE ) {
				for ( var attr of Array.from( node.attributes || [] ) ) {
					if ( attr.value?.includes( token ) ) {
						node.setAttribute( attr.name, attr.value.replaceAll( token, value ) );
					}
				}
			} else if ( node.nodeType === Node.TEXT_NODE && node.nodeValue?.includes( token ) ) {
				node.nodeValue = node.nodeValue.replaceAll( token, value );
			}
			node = walker.nextNode();
		}
	}

	function replaceIndexTokens(fragment, idx){
		replaceTokenInFragment( fragment, '__INDEX__', String( idx ) );
	}

	function replacePhaseTokens(fragment, phaseIndex){
		replaceTokenInFragment( fragment, '__PHASE__', String( phaseIndex ) );
	}

	function setTicketRowState(row, isActive){
		var rowPanel = row.querySelector( '.oras-ticket-panel' );
		row.style.display = isActive ? 'block' : 'none';
		if ( rowPanel ) {
			rowPanel.style.display = isActive ? 'block' : 'none';
			rowPanel.classList.toggle( 'is-active', isActive );
			rowPanel.classList.toggle( 'is-hidden', ! isActive );
		}
	}

	function activateTicket(idx){
		var rows      = document.querySelectorAll( '#oras-tickets-table .oras-ticket-row' );
		var activeRow = null;
		for ( var row of rows ) {
			var isActive = String( row.dataset.index ) === String( idx );
			setTicketRowState( row, isActive );
			if ( isActive ) {
				activeRow = row;
			}
		}

		if ( activeRow ) {
			var panel = activeRow.querySelector( '.oras-ticket-panel' );
			if ( panel ) {
				panel.style.display = 'block';
				panel.classList.toggle( 'is-active', true );
				panel.classList.toggle( 'is-hidden', false );
			}

			var panelWrap = activeRow.querySelector( '.panel-wrap' );
			if ( panelWrap && ! panelWrap.querySelector( '.wc-tabs li.active' ) ) {
				initInnerTabs( panelWrap );
			}
		}

		var tabs = document.querySelectorAll( '#oras-ticket-tabs .oras-ticket-tab' );
		for ( var tab of tabs ) {
			var isActiveTab = String( tab.dataset.index ) === String( idx );
			tab.classList.toggle( 'button-primary', isActiveTab );
			tab.classList.toggle( 'is-active', isActiveTab );
		}
	}

	function initInnerTabs(panelWrap){
		if ( ! panelWrap ) {
			return;
		}
		var tabs = panelWrap.querySelectorAll( '.wc-tabs li' );
		for ( var tab of tabs ) {
			tab.classList.remove( 'active' );
		}
		var firstTab = panelWrap.querySelector( '.wc-tabs li' );
		if ( firstTab ) {
			firstTab.classList.add( 'active' );
		}
		var panels = panelWrap.querySelectorAll( '.panel' );
		for ( var panel of panels ) {
			panel.style.display = 'none';
		}
		if ( firstTab ) {
			var link = firstTab.querySelector( 'a' );
			if ( link ) {
				var targetId = link.getAttribute( 'href' );
				if ( targetId ) {
					var targetPanel = panelWrap.querySelector( targetId );
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
		var toggle = phaseItem.querySelector( '.oras-phase-toggle' );
		if ( ! toggle ) {
			return;
		}
		if ( phaseItem.classList.contains( 'is-collapsed' ) ) {
			toggle.textContent = 'Advanced';
		} else {
			toggle.textContent = 'Hide advanced';
		}
	}

	function initPhaseToggles(scope){
		var root  = scope || document;
		var items = root.querySelectorAll( '.oras-phase-item' );
		for ( var item of items ) {
			syncPhaseToggle( item );
		}
	}

	function parseLocalDateTime(value){
		if ( ! value ) {
			return null;
		}
		var dt = new Date( value );
		if ( Number.isNaN( dt.getTime() ) ) {
			return null;
		}
		return dt.getTime();
	}

	function getSaleStatus(startValue, endValue){
		var startTs = parseLocalDateTime( startValue );
		var endTs   = parseLocalDateTime( endValue );
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
		var title = tab.querySelector( '.oras-ticket-tab-title' );
		var meta  = tab.querySelector( '.oras-ticket-tab-meta' );
		if ( title && meta ) {
			return { title: title, meta: meta };
		}
		tab.innerHTML   = '';
		title           = document.createElement( 'span' );
		title.className = 'oras-ticket-tab-title';
		meta            = document.createElement( 'span' );
		meta.className  = 'oras-ticket-tab-meta';
		tab.appendChild( title );
		tab.appendChild( meta );
		return { title: title, meta: meta };
	}

	function updateTicketTab(panel){
		if ( ! panel ) {
			return;
		}
		var idx = panel.dataset.index;
		if ( idx === undefined ) {
			return;
		}
		var tab = document.querySelector( '#oras-ticket-tabs .oras-ticket-tab[data-index="' + idx + '"]' );
		if ( ! tab ) {
			return;
		}

		var nameInput  = panel.querySelector( 'input[name="oras_tickets_tickets[' + idx + '][name]"]' );
		var priceInput = panel.querySelector( 'input[name="oras_tickets_tickets[' + idx + '][price]"]' );
		var startInput = panel.querySelector( 'input[name="oras_tickets_tickets[' + idx + '][sale_start]"]' );
		var endInput   = panel.querySelector( 'input[name="oras_tickets_tickets[' + idx + '][sale_end]"]' );

		var titleText  = nameInput && nameInput.value ? nameInput.value : 'Ticket #' + idx;
		var priceValue = priceInput && priceInput.value ? priceInput.value : '0.00';
		var status     = getSaleStatus( startInput ? startInput.value : '', endInput ? endInput.value : '' );

		var spans               = ensureTabSpans( tab );
		spans.title.textContent = titleText;
		spans.meta.textContent  = '$' + priceValue + ' • ' + status;
	}

	function hasPhaseInputData(phaseItem){
		if ( ! phaseItem ) {
			return false;
		}
		var inputs = phaseItem.querySelectorAll( 'input[type="text"]' );
		for ( var input of inputs ) {
			if ( (input.value || '').trim() !== '' ) {
				return true;
			}
		}
		return false;
	}

	function humanizeKey(value){
		var text = (value || '').replaceAll( /[_-]+/g, ' ' ).trim();
		if ( text === '' ) {
			return '';
		}
		return text.replaceAll(
			/\w\S*/g,
			function (word) {
				return word.charAt( 0 ).toUpperCase() + word.slice( 1 ).toLowerCase();
			}
		);
	}

	function handlePhaseToggleClick(event, target){
		if ( ! target?.classList?.contains( 'oras-phase-toggle' ) ) {
			return false;
		}
		event.preventDefault();
		var phaseItem = target.closest( '.oras-phase-item' );
		if ( phaseItem ) {
			phaseItem.classList.toggle( 'is-collapsed' );
			syncPhaseToggle( phaseItem );
		}
		return true;
	}

	function handleInnerTabClick(event, target){
		if ( target?.tagName?.toLowerCase() !== 'a' || ! target.closest( '.oras-ticket-data' ) || ! target.closest( '.wc-tabs' ) ) {
			return false;
		}

		event.preventDefault();
		var panelWrap = target.closest( '.panel-wrap' );
		if ( ! panelWrap ) {
			return true;
		}

		var tabs = panelWrap.querySelectorAll( '.wc-tabs li' );
		for ( var tab of tabs ) {
			tab.classList.remove( 'active' );
		}

		target.closest( 'li' )?.classList.add( 'active' );

		var panels = panelWrap.querySelectorAll( '.panel' );
		for ( var panel of panels ) {
			panel.style.display = 'none';
		}

		var targetId = target.getAttribute( 'href' );
		if ( targetId ) {
			panelWrap.querySelector( targetId )?.style.setProperty( 'display', 'block' );
		}
		return true;
	}

	function handleRemoveTicketClick(target){
		if ( ! target?.classList?.contains( 'oras-remove-ticket' ) ) {
			return false;
		}

		var idx = target.closest( '.oras-ticket-panel' )?.dataset.index;
		if ( ! idx ) {
			return true;
		}

		document.querySelector( '#oras-tickets-table .oras-ticket-row[data-index="' + idx + '"]' )?.remove();

		var tab = document.querySelector( '#oras-ticket-tabs .oras-ticket-tab[data-index="' + idx + '"]' );
		tab?.closest( 'li' )?.remove();
		if ( tab && ! tab.closest( 'li' ) ) {
			tab.remove();
		}

		document.querySelector( 'input[name="oras_tickets_index[]"][value="' + idx + '"]' )?.remove();

		var emptyState = document.getElementById( 'oras-tickets-empty' );
		var table      = document.getElementById( 'oras-tickets-table' );
		var remaining  = document.querySelectorAll( '#oras-tickets-table .oras-ticket-row' );
		if ( remaining.length > 0 ) {
			if ( table ) {
				table.style.display = 'block';
			}
			if ( emptyState ) {
				emptyState.style.display = 'none';
			}
			var firstIdx = remaining[0]?.dataset.index;
			if ( firstIdx !== undefined ) {
				activateTicket( firstIdx );
			}
		} else {
			if ( table ) {
				table.style.display = 'none';
			}
			if ( emptyState ) {
				emptyState.style.display = 'block';
			}
		}

		return true;
	}

	function handlePhaseRemoveClick(target){
		if ( ! target?.classList?.contains( 'oras-phase-remove' ) ) {
			return false;
		}

		var phaseRow = target.closest( '.oras-phase-item' );
		if ( ! phaseRow ) {
			return true;
		}
		if ( hasPhaseInputData( phaseRow ) && ! globalThis.confirm( 'Remove this pricing phase?' ) ) {
			return true;
		}
		phaseRow.remove();
		return true;
	}

	function nextPhaseIndex(list){
		var max  = -1;
		var rows = list.querySelectorAll( '[data-phase-index]' );
		for ( var row of rows ) {
			var v = Number.parseInt( row.dataset.phaseIndex || '', 10 );
			if ( ! Number.isNaN( v ) && v > max ) {
				max = v;
			}
		}
		return max + 1;
	}

	function focusPhaseInput(newPhase){
		var keyInput = newPhase.querySelector( 'input[name*="[key]"]' );
		if ( keyInput ) {
			keyInput.focus();
			return;
		}
		newPhase.querySelector( 'input[name*="[label]"]' )?.focus();
	}

	function handlePhaseAddClick(target){
		if ( ! target?.classList?.contains( 'oras-phase-add' ) ) {
			return false;
		}

		var ticketRow = target.closest( 'tr.oras-ticket-row' );
		var template  = ticketRow?.querySelector( 'template.oras-phase-template' );
		var list      = ticketRow?.querySelector( '.oras-phase-list' );
		if ( ! ticketRow || ! template?.content || ! list ) {
			return true;
		}

		var phaseFragment = template.content.cloneNode( true );
		replacePhaseTokens( phaseFragment, nextPhaseIndex( list ) );

		var newPhase = phaseFragment.firstElementChild;
		if ( newPhase ) {
			newPhase.classList.add( 'is-collapsed' );
			list.appendChild( phaseFragment );
			syncPhaseToggle( newPhase );
			focusPhaseInput( newPhase );
		}
		return true;
	}

	function handleMetaboxClick(event){
		var target = event.target;
		if ( ! target ) {
			return;
		}
		if ( handlePhaseToggleClick( event, target ) ) {
			return;
		}
		if ( handleInnerTabClick( event, target ) ) {
			return;
		}
		if ( handleRemoveTicketClick( target ) ) {
			return;
		}
		if ( handlePhaseRemoveClick( target ) ) {
			return;
		}
		handlePhaseAddClick( target );
	}

	function isTicketTabField(name){
		return (
			name.includes( '[name]' ) ||
			name.includes( '[price]' ) ||
			name.includes( '[sale_start]' ) ||
			name.includes( '[sale_end]' )
		);
	}

	function init() {
		var addBtn = document.getElementById( 'oras-add-ticket' );
		if ( addBtn ) {
			addBtn.addEventListener(
				'click',
				function () {
					var tpl = document.getElementById( 'oras-ticket-template' );
					if ( ! tpl ) {
						return;
					}
					var idx   = nextIndex();
					var tbody = document.querySelector( '#oras-tickets-table tbody' );
					if ( ! tbody ) {
						return;
					}
					var fragment = tpl.content.cloneNode( true );
					replaceIndexTokens( fragment, idx );
					var row = fragment.querySelector( 'tr.oras-ticket-row' );
					if ( ! row ) {
						return;
					}
					tbody.appendChild( row );

					var tabList = document.getElementById( 'oras-ticket-tabs' );
						if ( tabList ) {
							var li        = document.createElement( 'li' );
							var btn       = document.createElement( 'button' );
							btn.type      = 'button';
							btn.className = 'oras-ticket-tab';
							btn.dataset.index = String( idx );
							btn.style.width         = '100%';
							btn.style.textAlign     = 'left';
							var spans               = ensureTabSpans( btn );
							spans.title.textContent = 'Ticket #' + idx;
						spans.meta.textContent  = '$0.00 • Always';
						li.appendChild( btn );
						tabList.appendChild( li );
					}

					var panelWrap = row.querySelector( '.panel-wrap' );
					initInnerTabs( panelWrap );
					activateTicket( String( idx ) );
					initPhaseToggles( row );
				}
			);
		}

		var metabox = document.getElementById( 'oras-tickets-metabox' );
		if ( metabox ) {
			var tabsList = document.getElementById( 'oras-ticket-tabs' );
				if ( tabsList ) {
					tabsList.addEventListener(
						'click',
						function (e) {
							var btn = e.target?.closest( '.oras-ticket-tab' );
							if ( ! btn ) {
								return;
							}
							var idx = btn.dataset.index;
							if ( idx === undefined ) {
								return;
							}
							activateTicket( idx );
						}
					);
				}

				metabox.addEventListener(
					'click',
					handleMetaboxClick
				);

				metabox.addEventListener(
					'focusout',
					function (e) {
						var t = e.target;
						if ( ! t?.name?.includes( '[price_phases]' ) ) {
							return;
						}
						if ( ! t.name.endsWith( '[key]' ) ) {
							return;
						}
						var phaseItem = t.closest( '.oras-phase-item' );
						if ( ! phaseItem ) {
						return;
					}
					var labelInput = phaseItem.querySelector( 'input[name*="[label]"]' );
					if ( labelInput && (labelInput.value || '').trim() === '' ) {
						var suggestion = humanizeKey( t.value || '' );
						if ( suggestion !== '' ) {
							labelInput.value = suggestion;
						}
					}
				}
			);

				var updateHandler = function (e) {
					var t = e.target;
					if ( ! t?.name?.startsWith( 'oras_tickets_tickets[' ) ) {
						return;
					}
					if ( ! isTicketTabField( t.name ) ) {
						return;
					}
					var panel = t.closest( '.oras-ticket-panel' );
					if ( panel ) {
						updateTicketTab( panel );
				}
			};

			metabox.addEventListener( 'input', updateHandler );
			metabox.addEventListener( 'change', updateHandler );
			initPhaseToggles( metabox );
				if ( tabsList ) {
					var firstTab = tabsList.querySelector( '.oras-ticket-tab' );
					if ( firstTab ) {
						var firstIdx = firstTab.dataset.index;
						if ( firstIdx !== undefined ) {
							activateTicket( firstIdx );
							var firstPanel = document.querySelector( '#oras-tickets-table .oras-ticket-panel[data-index="' + firstIdx + '"]' );
							updateTicketTab( firstPanel );
						}
				}
			}
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();

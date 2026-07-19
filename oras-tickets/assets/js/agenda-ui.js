(function () {
	function getFilterValue(agenda, filterName) {
		const select = agenda.querySelector( '[data-agenda-filter="' + filterName + '"]' );
		return select ? select.value : '';
	}

	function updateFilterStatus(agenda, visibleCount) {
		const status = agenda.querySelector( '[data-agenda-filter-status]' );
		if ( ! status ) {
			return;
		}

		status.textContent = visibleCount + ( visibleCount === 1 ? ' session shown' : ' sessions shown' );
	}

	function applyFilters(agenda) {
		const activeType = getFilterValue( agenda, 'type' );
		const activeLocation = getFilterValue( agenda, 'location' );
		const cards = agenda.querySelectorAll( '.oras-agenda__session-card' );

		for ( const card of cards ) {
			const typeMatches = ! activeType || card.dataset.agendaType === activeType;
			const locationMatches = ! activeLocation || card.dataset.agendaLocation === activeLocation;
			const cardVisible = typeMatches && locationMatches;
			card.hidden = ! cardVisible;
		}

		const groups = agenda.querySelectorAll( '.oras-agenda__time-band, .oras-agenda__ongoing, .oras-agenda__unscheduled' );
		for ( const group of groups ) {
			group.hidden = ! group.querySelector( '.oras-agenda__session-card:not([hidden])' );
		}

		const activePanel = agenda.querySelector( '[data-day-panel]:not([hidden])' );
		const visibleCount = activePanel ? activePanel.querySelectorAll( '.oras-agenda__session-card:not([hidden])' ).length : 0;
		updateFilterStatus( agenda, visibleCount );
	}

	function resetFilters(agenda) {
		const selects = agenda.querySelectorAll( '[data-agenda-filter]' );
		for ( const select of selects ) {
			select.value = '';
		}

		applyFilters( agenda );
	}

	function activateDay(agenda, dayIndex, shouldFocus) {
		if ( ! agenda || dayIndex === null || dayIndex === undefined ) {
			return;
		}

		const tabs = agenda.querySelectorAll( '[data-day-tab]' );
		const panels = agenda.querySelectorAll( '[data-day-panel]' );
		const dayIndexText = String( dayIndex );

		for ( const tab of tabs ) {
			const tabSelected = tab.dataset.dayTab === dayIndexText;
			tab.setAttribute( 'aria-selected', tabSelected ? 'true' : 'false' );
			tab.tabIndex = tabSelected ? 0 : -1;
			if ( tabSelected && shouldFocus ) {
				tab.focus();
			}
		}

		for ( const panel of panels ) {
			const panelActive = panel.dataset.dayPanel === dayIndexText;
			panel.hidden = ! panelActive;
		}

		applyFilters( agenda );
	}

	function activateDayWithNowItem(agenda) {
		const activeItem = agenda.querySelector( '.oras-agenda__item--now' );
		if ( ! activeItem ) {
			return;
		}

		const panel = activeItem.closest( '[data-day-panel]' );
		if ( ! panel ) {
			return;
		}

		activateDay( agenda, panel.dataset.dayPanel, false );
	}

	function handleTabKeydown(agenda, event, tab) {
		const tabs = Array.from( agenda.querySelectorAll( '[data-day-tab]' ) );
		const currentIndex = tabs.indexOf( tab );
		if ( currentIndex < 0 || tabs.length < 1 ) {
			return;
		}

		let nextIndex = null;
		switch ( event.key ) {
			case 'ArrowRight':
				nextIndex = ( currentIndex + 1 ) % tabs.length;
				break;
			case 'ArrowLeft':
				nextIndex = ( currentIndex - 1 + tabs.length ) % tabs.length;
				break;
			case 'Home':
				nextIndex = 0;
				break;
			case 'End':
				nextIndex = tabs.length - 1;
				break;
			default:
				return;
		}

		event.preventDefault();
		activateDay( agenda, tabs[ nextIndex ].dataset.dayTab, true );
	}

	function initializeAgenda(agenda) {
		agenda.addEventListener(
			'click',
			function (event) {
				const tab = event.target.closest( '[data-day-tab]' );
				if ( tab ) {
					activateDay( agenda, tab.dataset.dayTab, false );
					return;
				}

				const reset = event.target.closest( '[data-agenda-filter-reset]' );
				if ( reset ) {
					resetFilters( agenda );
					return;
				}

				const descToggle = event.target.closest( '.oras-agenda__details' );
				if ( descToggle ) {
					const desc = descToggle.nextElementSibling;
					if ( ! desc || ! desc.classList.contains( 'oras-agenda__desc' ) ) {
						return;
					}

					const expanded = descToggle.getAttribute( 'aria-expanded' ) === 'true';
					descToggle.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
					desc.hidden = expanded;
				}
			}
		);

		agenda.addEventListener(
			'keydown',
			function (event) {
				const tab = event.target.closest( '[data-day-tab]' );
				if ( tab ) {
					handleTabKeydown( agenda, event, tab );
				}
			}
		);

		agenda.addEventListener(
			'change',
			function (event) {
				if ( event.target.matches( '[data-agenda-filter]' ) ) {
					applyFilters( agenda );
				}
			}
		);

		const selectedTab = agenda.querySelector( '[data-day-tab][aria-selected="true"]' ) || agenda.querySelector( '[data-day-tab]' );
		if ( selectedTab ) {
			activateDay( agenda, selectedTab.dataset.dayTab, false );
		} else {
			applyFilters( agenda );
		}

		activateDayWithNowItem( agenda );
		globalThis.setTimeout(
			function () {
				activateDayWithNowItem( agenda );
			},
			120
		);
	}

	document.addEventListener(
		'DOMContentLoaded',
		function () {
			const agendas = document.querySelectorAll( '.oras-agenda' );
			for ( const agenda of agendas ) {
				initializeAgenda( agenda );
			}
		}
	);
})();

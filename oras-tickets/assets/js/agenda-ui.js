(function () {
	function activateDay(agenda, dayIndex) {
		if ( ! agenda || dayIndex === null || dayIndex === undefined ) {
			return;
		}

		const tabs = agenda.querySelectorAll( '[data-day-tab]' );
		const panels = agenda.querySelectorAll( '[data-day-panel]' );
		const dayIndexText = String( dayIndex );

		for ( const tab of tabs ) {
			const tabSelected = tab.dataset.dayTab === dayIndexText;
			tab.setAttribute( 'aria-selected', tabSelected ? 'true' : 'false' );
		}

		for ( const panel of panels ) {
			const panelActive = panel.dataset.dayPanel === dayIndexText;
			panel.hidden = ! panelActive;
		}
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

		activateDay( agenda, panel.dataset.dayPanel );
	}

	document.addEventListener(
		'DOMContentLoaded',
		function () {
			const agenda = document.querySelector( '.oras-agenda' );
			if ( ! agenda ) {
				return;
			}

			agenda.addEventListener(
				'click',
				function (event) {
					const tab = event.target.closest( '[data-day-tab]' );
					if ( tab ) {
						activateDay( agenda, tab.dataset.dayTab );
						return;
					}

					const descToggle = event.target.closest( '.oras-agenda__details' );
					if ( descToggle ) {
						const desc = descToggle.nextElementSibling;
						if ( ! desc?.classList.contains( 'oras-agenda__desc' ) ) {
							return;
						}

						const expanded = descToggle.getAttribute( 'aria-expanded' ) === 'true';
						descToggle.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
						desc.hidden = expanded;
					}
				}
			);

			activateDayWithNowItem( agenda );
			globalThis.setTimeout(
				function () {
					activateDayWithNowItem( agenda );
				},
				120
			);
		}
	);
})();

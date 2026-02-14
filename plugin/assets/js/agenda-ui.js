(function () {
	function activateDay(agenda, dayIndex) {
		if ( ! agenda || dayIndex === null || typeof dayIndex === 'undefined') {
			return;
		}

		var tabs   = agenda.querySelectorAll( '[data-day-tab]' );
		var panels = agenda.querySelectorAll( '[data-day-panel]' );

		for (var i = 0; i < tabs.length; i++) {
			var tabSelected = tabs[i].getAttribute( 'data-day-tab' ) === String( dayIndex );
			tabs[i].setAttribute( 'aria-selected', tabSelected ? 'true' : 'false' );
		}

		for (var j = 0; j < panels.length; j++) {
			var panelActive  = panels[j].getAttribute( 'data-day-panel' ) === String( dayIndex );
			panels[j].hidden = ! panelActive;
		}
	}

	function activateDayWithNowItem(agenda) {
		var activeItem = agenda.querySelector( '.oras-agenda__item--now' );
		if ( ! activeItem) {
			return;
		}

		var panel = activeItem.closest( '[data-day-panel]' );
		if ( ! panel) {
			return;
		}

		activateDay( agenda, panel.getAttribute( 'data-day-panel' ) );
	}

	document.addEventListener(
		'DOMContentLoaded',
		function () {
			var agenda = document.querySelector( '.oras-agenda' );
			if ( ! agenda) {
				return;
			}

			agenda.addEventListener(
				'click',
				function (event) {
					var tab = event.target.closest( '[data-day-tab]' );
					if (tab) {
						activateDay( agenda, tab.getAttribute( 'data-day-tab' ) );
						return;
					}

					var descToggle = event.target.closest( '.oras-agenda__details' );
					if (descToggle) {
						var desc = descToggle.nextElementSibling;
						if ( ! desc || ! desc.classList.contains( 'oras-agenda__desc' )) {
							return;
						}

						var expanded = descToggle.getAttribute( 'aria-expanded' ) === 'true';
						descToggle.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
						desc.hidden = expanded;
					}
				}
			);

			activateDayWithNowItem( agenda );
			window.setTimeout(
				function () {
					activateDayWithNowItem( agenda );
				},
				120
			);
		}
	);
})();

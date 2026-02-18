(function () {
	function toNowParts(timeZone) {
		var formatter = new Intl.DateTimeFormat(
			'en-CA',
			{
				timeZone: timeZone,
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit',
				hour12: false,
			}
		);

		var parts = formatter.formatToParts( new Date() );
		var map   = {};

		for (var i = 0; i < parts.length; i++) {
			if (parts[i].type !== 'literal') {
				map[parts[i].type] = parts[i].value;
			}
		}

		return {
			date: map.year + '-' + map.month + '-' + map.day,
			hour: parseInt( map.hour || '0', 10 ),
			minute: parseInt( map.minute || '0', 10 ),
		};
	}

	function parseHm(value) {
		if ( ! value || typeof value !== 'string') {
			return null;
		}

		var m = value.match( /^(\d{2}):(\d{2})$/ );
		if ( ! m) {
			return null;
		}

		var hour   = parseInt( m[1], 10 );
		var minute = parseInt( m[2], 10 );

		if (hour < 0 || hour > 23 || minute < 0 || minute > 59) {
			return null;
		}

		return { hour: hour, minute: minute };
	}

	function toMinutes(hour, minute) {
		return (hour * 60) + minute;
	}

	function ensureBadge(slot, label) {
		var container = slot.querySelector( '.oras-agenda__body' ) || slot;
		var existing  = container.querySelector( '.oras-agenda__now-badge' );
		if (existing) {
			existing.textContent = label;
			return;
		}

		var badge         = document.createElement( 'span' );
		badge.className   = 'oras-agenda__now-badge';
		badge.textContent = label;
		container.appendChild( badge );
	}

	document.addEventListener(
		'DOMContentLoaded',
		function () {
			if (typeof ORAS_AGENDA_NOW !== 'object' || ! ORAS_AGENDA_NOW) {
				return;
			}

			var timezone      = typeof ORAS_AGENDA_NOW.tz === 'string' && ORAS_AGENDA_NOW.tz !== '' ? ORAS_AGENDA_NOW.tz : 'UTC';
			var autoscroll    = ! ! ORAS_AGENDA_NOW.autoscroll;
			var label         = typeof ORAS_AGENDA_NOW.label === 'string' && ORAS_AGENDA_NOW.label !== '' ? ORAS_AGENDA_NOW.label : 'Currently happening';
			var didAutoscroll = false;
			var isInitialRun  = true;

			function tick() {
				var slots = document.querySelectorAll( '.oras-agenda__item[data-agenda-date][data-start][data-end]' );
				if ( ! slots.length) {
					isInitialRun = false;
					return;
				}

				var today      = toNowParts( timezone );
				var todayDate  = today.date;
				var nowMinutes = toMinutes( today.hour, today.minute );

				for (var i = 0; i < slots.length; i++) {
					slots[i].classList.remove( 'oras-agenda__item--now' );
					var oldBadge = slots[i].querySelector( '.oras-agenda__now-badge' );
					if (oldBadge && oldBadge.parentNode) {
						oldBadge.parentNode.removeChild( oldBadge );
					}
				}

				var activeSlot = null;

				for (var j = 0; j < slots.length; j++) {
					var slot      = slots[j];
					var dateText  = slot.getAttribute( 'data-agenda-date' ) || '';
					var startText = slot.getAttribute( 'data-start' ) || '';
					var endText   = slot.getAttribute( 'data-end' ) || '';

					var start = parseHm( startText );
					var end   = parseHm( endText );

					if ( ! /^\d{4}-\d{2}-\d{2}$/.test( dateText ) || ! start || ! end || dateText !== todayDate) {
						continue;
					}

					var startMinutes = toMinutes( start.hour, start.minute );
					var endMinutes   = toMinutes( end.hour, end.minute );

					if (endMinutes <= startMinutes) {
						continue;
					}

					if (startMinutes <= nowMinutes && nowMinutes < endMinutes) {
						activeSlot = slot;
						break;
					}
				}

				if ( ! activeSlot) {
					isInitialRun = false;
					return;
				}

				activeSlot.classList.add( 'oras-agenda__item--now' );
				ensureBadge( activeSlot, label );

				if (autoscroll && isInitialRun && ! didAutoscroll) {
					didAutoscroll = true;
					activeSlot.scrollIntoView( { block: 'center', behavior: 'smooth' } );
				}

				isInitialRun = false;
			}

			tick();
			window.setInterval( tick, 60000 );
		}
	);
})();

(function () {
	function toNowParts(timeZone) {
		const formatter = new Intl.DateTimeFormat(
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

		const parts = formatter.formatToParts( new Date() );
		const map = {};

		for ( const part of parts ) {
			if ( part.type !== 'literal' ) {
				map[part.type] = part.value;
			}
		}

		return {
			date: map.year + '-' + map.month + '-' + map.day,
			hour: Number.parseInt( map.hour || '0', 10 ),
			minute: Number.parseInt( map.minute || '0', 10 ),
		};
	}

	function parseHm(value) {
		if ( ! value || typeof value !== 'string' ) {
			return null;
		}

		const match = /^(\d{2}):(\d{2})$/.exec( value );
		if ( ! match ) {
			return null;
		}

		const hour = Number.parseInt( match[1], 10 );
		const minute = Number.parseInt( match[2], 10 );

		if ( hour < 0 || hour > 23 || minute < 0 || minute > 59 ) {
			return null;
		}

		return { hour: hour, minute: minute };
	}

	function toMinutes(hour, minute) {
		return ( hour * 60 ) + minute;
	}

	function ensureBadge(slot, label) {
		const container = slot.querySelector( '.oras-agenda__body' ) || slot;
		const existing = container.querySelector( '.oras-agenda__now-badge' );
		if ( existing ) {
			existing.textContent = label;
			return;
		}

		const badge = document.createElement( 'span' );
		badge.className = 'oras-agenda__now-badge';
		badge.textContent = label;
		container.appendChild( badge );
	}

	function clearNowMarkers(slots) {
		for ( const slot of slots ) {
			slot.classList.remove( 'oras-agenda__item--now' );
			slot.querySelector( '.oras-agenda__now-badge' )?.remove();
		}
	}

	function findActiveSlot(slots, todayDate, nowMinutes) {
		for ( const slot of slots ) {
			const dateText = slot.dataset.agendaDate || '';
			const startText = slot.dataset.start || '';
			const endText = slot.dataset.end || '';
			const start = parseHm( startText );
			const end = parseHm( endText );

			if ( ! /^\d{4}-\d{2}-\d{2}$/.test( dateText ) || ! start || ! end || dateText !== todayDate ) {
				continue;
			}

			const startMinutes = toMinutes( start.hour, start.minute );
			const endMinutes = toMinutes( end.hour, end.minute );
			if ( endMinutes <= startMinutes ) {
				continue;
			}

			if ( startMinutes <= nowMinutes && nowMinutes < endMinutes ) {
				return slot;
			}
		}

		return null;
	}

	document.addEventListener(
		'DOMContentLoaded',
		function () {
			if ( typeof ORAS_AGENDA_NOW !== 'object' || ! ORAS_AGENDA_NOW ) {
				return;
			}

			const timezone = typeof ORAS_AGENDA_NOW.tz === 'string' && ORAS_AGENDA_NOW.tz !== '' ? ORAS_AGENDA_NOW.tz : 'UTC';
			const autoscroll = !! ORAS_AGENDA_NOW.autoscroll;
			const label = typeof ORAS_AGENDA_NOW.label === 'string' && ORAS_AGENDA_NOW.label !== '' ? ORAS_AGENDA_NOW.label : 'Currently happening';
			let didAutoscroll = false;
			let isInitialRun = true;

			function tick() {
				const slots = document.querySelectorAll( '.oras-agenda__item[data-agenda-date][data-start][data-end]' );
				if ( ! slots.length ) {
					isInitialRun = false;
					return;
				}

				const today = toNowParts( timezone );
				const nowMinutes = toMinutes( today.hour, today.minute );

				clearNowMarkers( slots );
				const activeSlot = findActiveSlot( slots, today.date, nowMinutes );
				if ( ! activeSlot ) {
					isInitialRun = false;
					return;
				}

				activeSlot.classList.add( 'oras-agenda__item--now' );
				ensureBadge( activeSlot, label );

				if ( autoscroll && isInitialRun && ! didAutoscroll ) {
					didAutoscroll = true;
					activeSlot.scrollIntoView( { block: 'center', behavior: 'smooth' } );
				}

				isInitialRun = false;
			}

			tick();
			globalThis.setInterval( tick, 60000 );
		}
	);
})();

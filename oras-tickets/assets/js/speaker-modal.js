(function () {
	const dataNode = document.getElementById( 'oras-speaker-data' );
	const drawer = document.getElementById( 'oras-speaker-drawer' );

	if ( ! dataNode || ! drawer ) {
		return;
	}

	let payload = [];
	try {
		payload = JSON.parse( dataNode.textContent || '[]' );
	} catch (error) {
		globalThis.console?.warn?.( 'Unable to parse speaker payload', error );
		return;
	}

	if ( ! Array.isArray( payload ) || payload.length === 0 ) {
		return;
	}

	const speakersById = {};
	for ( const speaker of payload ) {
		if ( speaker && speaker.id !== undefined ) {
			speakersById[String( speaker.id )] = speaker;
		}
	}

	const panel = drawer.querySelector( '.oras-speaker-drawer__panel' );
	const closeButton = drawer.querySelector( '.oras-speaker-drawer__close' );
	const headshot = drawer.querySelector( '.oras-speaker-drawer__headshot' );
	const nameNode = drawer.querySelector( '.oras-speaker-drawer__name' );
	const affiliationNode = drawer.querySelector( '.oras-speaker-drawer__affiliation' );
	const bioNode = drawer.querySelector( '.oras-speaker-drawer__bio' );
	const websiteLink = drawer.querySelector( '.oras-speaker-drawer__website' );
	const profileLink = drawer.querySelector( '.oras-speaker-drawer__profile' );
	let lastTrigger = null;

	if ( ! panel || ! closeButton || ! headshot || ! nameNode || ! affiliationNode || ! bioNode || ! websiteLink || ! profileLink ) {
		return;
	}

	if ( drawer.parentElement !== document.body ) {
		document.body.appendChild( drawer );
	}

	function getFocusableElements() {
		return Array.from(
			panel.querySelectorAll( 'a[href]:not([hidden]), button:not([disabled]):not([hidden]), [tabindex]:not([tabindex="-1"]):not([hidden])' )
		).filter(
			function (element) {
				return element.getClientRects().length > 0;
			}
		);
	}

	function setOptionalLink(link, url) {
		const safeUrl = typeof url === 'string' ? url.trim() : '';
		let parsedUrl = null;
		try {
			parsedUrl = new URL( safeUrl, globalThis.location.href );
		} catch (error) {
			parsedUrl = null;
		}

		if ( ! parsedUrl || ! [ 'http:', 'https:' ].includes( parsedUrl.protocol ) ) {
			link.removeAttribute( 'href' );
			link.hidden = true;
			return;
		}

		link.href = parsedUrl.href;
		link.hidden = false;
	}

	function openDrawer(speaker, triggerButton) {
		if ( ! speaker ) {
			return;
		}

		lastTrigger = triggerButton || null;
		nameNode.textContent = speaker.name || '';

		const affiliation = typeof speaker.affiliation === 'string' ? speaker.affiliation.trim() : '';
		if ( affiliation !== '' && affiliation.toLowerCase() !== 'n/a' ) {
			affiliationNode.textContent = affiliation;
			affiliationNode.hidden = false;
		} else {
			affiliationNode.textContent = '';
			affiliationNode.hidden = true;
		}

		bioNode.textContent = speaker.bio_short || '';

		const headshotUrl = typeof speaker.headshot_url === 'string' ? speaker.headshot_url.trim() : '';
		if ( headshotUrl === '' ) {
			headshot.removeAttribute( 'src' );
			headshot.alt = '';
			headshot.hidden = true;
			drawer.classList.add( 'oras-speaker-drawer--no-headshot' );
		} else {
			headshot.src = headshotUrl;
			headshot.alt = speaker.headshot_alt || speaker.name || '';
			headshot.hidden = false;
			drawer.classList.remove( 'oras-speaker-drawer--no-headshot' );
		}

		setOptionalLink( websiteLink, speaker.website_url );
		setOptionalLink( profileLink, speaker.permalink );

		drawer.hidden = false;
		document.body.classList.add( 'oras-speaker-drawer-open' );
		closeButton.focus();
	}

	function closeDrawer() {
		if ( drawer.hidden ) {
			return;
		}

		drawer.hidden = true;
		drawer.classList.remove( 'oras-speaker-drawer--no-headshot' );
		document.body.classList.remove( 'oras-speaker-drawer-open' );

		if ( lastTrigger && typeof lastTrigger.focus === 'function' ) {
			lastTrigger.focus();
		}
	}

	document.addEventListener(
		'click',
		function (event) {
			const eventTarget = event.target instanceof Element ? event.target : null;
			if ( ! eventTarget ) {
				return;
			}

			const trigger = eventTarget.closest( '.oras-agenda__speaker-link[data-speaker-id]' );
			if ( trigger ) {
				const speaker = speakersById[trigger.dataset.speakerId || ''];
				if ( speaker ) {
					event.preventDefault();
					openDrawer( speaker, trigger );
				}
				return;
			}

			if ( ! drawer.hidden && eventTarget.closest( '[data-speaker-close]' ) ) {
				event.preventDefault();
				closeDrawer();
			}
		}
	);

	document.addEventListener(
		'keydown',
		function (event) {
			if ( drawer.hidden ) {
				return;
			}

			if ( event.key === 'Escape' ) {
				event.preventDefault();
				closeDrawer();
				return;
			}

			if ( event.key === 'Tab' ) {
				const focusableElements = getFocusableElements();
				if ( focusableElements.length === 0 ) {
					event.preventDefault();
					return;
				}

				const firstElement = focusableElements[0];
				const lastElement = focusableElements[focusableElements.length - 1];
				if ( ! panel.contains( document.activeElement ) ) {
					event.preventDefault();
					firstElement.focus();
				} else if ( event.shiftKey && document.activeElement === firstElement ) {
					event.preventDefault();
					lastElement.focus();
				} else if ( ! event.shiftKey && document.activeElement === lastElement ) {
					event.preventDefault();
					firstElement.focus();
				}
			}
		}
	);
})();

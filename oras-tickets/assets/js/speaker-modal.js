(function () {
	const dataNode = document.getElementById( 'oras-speaker-data' );
	const modal = document.getElementById( 'oras-speaker-modal' );

	if ( ! dataNode || ! modal ) {
		return;
	}

	let payload = [];
	try {
		payload = JSON.parse( dataNode.textContent || '[]' );
	} catch (error) {
		globalThis.console?.warn?.( 'Unable to parse speaker payload', error );
		payload = [];
	}

	if ( ! Array.isArray( payload ) || payload.length === 0 ) {
		return;
	}

	const byId = {};
	payload.forEach(
		function (speaker) {
			if ( speaker && speaker.id !== undefined ) {
				byId[String( speaker.id )] = speaker;
			}
		}
	);

	const closeButton = modal.querySelector( '.oras-modal__close' );
	const headshot = modal.querySelector( '.oras-modal__headshot' );
	const nameNode = modal.querySelector( '.oras-modal__name' );
	const affiliationNode = modal.querySelector( '.oras-modal__affiliation' );
	const bioNode = modal.querySelector( '.oras-modal__bio' );
	const websiteLink = modal.querySelector( '.oras-modal__website' );
	const profileLink = modal.querySelector( '.oras-modal__profile' );
	let lastTrigger = null;

	if ( ! closeButton || ! headshot || ! nameNode || ! affiliationNode || ! bioNode || ! websiteLink || ! profileLink ) {
		return;
	}

	function openModal(speaker, triggerButton) {
		if ( ! speaker ) {
			return;
		}

		lastTrigger = triggerButton || null;
		nameNode.textContent = speaker.name || '';

		const affiliation = typeof speaker.affiliation === 'string' ? speaker.affiliation.trim() : '';
		if ( affiliation && affiliation.toLowerCase() !== 'n/a' ) {
			affiliationNode.textContent = affiliation;
			affiliationNode.hidden = false;
		} else {
			affiliationNode.textContent = '';
			affiliationNode.hidden = true;
		}

		bioNode.textContent = speaker.bio_short || '';

		const headshotUrl = typeof speaker.headshot_url === 'string' ? speaker.headshot_url.trim() : '';
		if ( headshotUrl === '' ) {
			headshot.src = '';
			headshot.hidden = true;
			modal.classList.add( 'oras-modal--no-headshot' );
		} else {
			headshot.src = headshotUrl;
			headshot.alt = speaker.name || '';
			headshot.hidden = false;
			modal.classList.remove( 'oras-modal--no-headshot' );
		}

		if ( speaker.website_url ) {
			websiteLink.href = speaker.website_url;
			websiteLink.hidden = false;
		} else {
			websiteLink.removeAttribute( 'href' );
			websiteLink.hidden = true;
		}

		if ( speaker.permalink ) {
			profileLink.href = speaker.permalink;
			profileLink.hidden = false;
		} else {
			profileLink.removeAttribute( 'href' );
			profileLink.hidden = true;
		}

		modal.hidden = false;
		closeButton.focus();
	}

	function closeModal() {
		if ( modal.hidden ) {
			return;
		}

		modal.hidden = true;
		modal.classList.remove( 'oras-modal--no-headshot' );

		if ( typeof lastTrigger?.focus === 'function' ) {
			lastTrigger.focus();
		}
	}

	document.querySelectorAll( '.oras-agenda__speaker-link[data-speaker-id]' ).forEach(
		function (button) {
			button.addEventListener(
				'click',
				function (event) {
					const speakerId = button.dataset.speakerId || '';
					const speaker = byId[speakerId];
					if ( ! speaker ) {
						return;
					}

					event.preventDefault();
					openModal( speaker, button );
				}
			);
		}
	);

	modal.addEventListener(
		'click',
		function (event) {
			if ( event.target?.closest( '[data-close]' ) ) {
				event.preventDefault();
				closeModal();
			}
		}
	);

	document.addEventListener(
		'keydown',
		function (event) {
			if ( event.key === 'Escape' && ! modal.hidden ) {
				closeModal();
			}
		}
	);
})();

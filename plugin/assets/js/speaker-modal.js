(function () {
	var dataNode = document.getElementById( 'oras-speaker-data' );
	var modal    = document.getElementById( 'oras-speaker-modal' );

	if ( ! dataNode || ! modal) {
		return;
	}

	var payload = [];
	try {
		payload = JSON.parse( dataNode.textContent || '[]' );
	} catch (error) {
		payload = [];
	}

	if ( ! Array.isArray( payload ) || payload.length === 0) {
		return;
	}

	var byId = {};
	payload.forEach(
		function (speaker) {
			if (speaker && typeof speaker.id !== 'undefined') {
				byId[String( speaker.id )] = speaker;
			}
		}
	);

	var closeButton     = modal.querySelector( '.oras-modal__close' );
	var headshot        = modal.querySelector( '.oras-modal__headshot' );
	var nameNode        = modal.querySelector( '.oras-modal__name' );
	var affiliationNode = modal.querySelector( '.oras-modal__affiliation' );
	var bioNode         = modal.querySelector( '.oras-modal__bio' );
	var websiteLink     = modal.querySelector( '.oras-modal__website' );
	var profileLink     = modal.querySelector( '.oras-modal__profile' );
	var lastTrigger     = null;

	if ( ! closeButton || ! headshot || ! nameNode || ! affiliationNode || ! bioNode || ! websiteLink || ! profileLink) {
		return;
	}

	function openModal(speaker, triggerButton) {
		if ( ! speaker) {
			return;
		}

		lastTrigger = triggerButton || null;

		var name             = speaker.name || '';
		nameNode.textContent = name;

		var affiliation = typeof speaker.affiliation === 'string' ? speaker.affiliation.trim() : '';
		if (affiliation && affiliation.toLowerCase() !== 'n/a') {
			affiliationNode.textContent = affiliation;
			affiliationNode.hidden      = false;
		} else {
			affiliationNode.textContent = '';
			affiliationNode.hidden      = true;
		}

		bioNode.textContent = speaker.bio_short || '';

		var headshotUrl = typeof speaker.headshot_url === 'string' ? speaker.headshot_url.trim() : '';

		if (headshotUrl !== '') {
			headshot.src    = headshotUrl;
			headshot.alt    = speaker.name || '';
			headshot.hidden = false;
			modal.classList.remove( 'oras-modal--no-headshot' );
		} else {
			headshot.src    = '';
			headshot.hidden = true;
			modal.classList.add( 'oras-modal--no-headshot' );
		}

		if (speaker.website_url) {
			websiteLink.href   = speaker.website_url;
			websiteLink.hidden = false;
		} else {
			websiteLink.removeAttribute( 'href' );
			websiteLink.hidden = true;
		}

		if (speaker.permalink) {
			profileLink.href   = speaker.permalink;
			profileLink.hidden = false;
		} else {
			profileLink.removeAttribute( 'href' );
			profileLink.hidden = true;
		}

		modal.hidden = false;
		closeButton.focus();
	}

	function closeModal() {
		if (modal.hidden) {
			return;
		}

		modal.hidden = true;
		modal.classList.remove( 'oras-modal--no-headshot' );

		if (lastTrigger && typeof lastTrigger.focus === 'function') {
			lastTrigger.focus();
		}
	}

	document.querySelectorAll( '.oras-agenda__speaker-link[data-speaker-id]' ).forEach(
		function (button) {
			button.addEventListener(
				'click',
				function (event) {
					var speakerId = button.getAttribute( 'data-speaker-id' ) || '';
					var speaker   = byId[speakerId];
					if ( ! speaker) {
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
			if (event.target && event.target.closest( '[data-close]' )) {
				event.preventDefault();
				closeModal();
			}
		}
	);

	document.addEventListener(
		'keydown',
		function (event) {
			if (event.key === 'Escape' && ! modal.hidden) {
				closeModal();
			}
		}
	);
})();

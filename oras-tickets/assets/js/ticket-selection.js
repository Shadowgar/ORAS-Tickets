( function () {
	'use strict';

	function initializeTicketForm( form ) {
		var quantityInputs = form.querySelectorAll( 'input[type="number"][name^="oras_qty["]' );
		var submitButton = form.querySelector( '.oras-ticket-selection-submit' );
		var helpText = form.querySelector( '.oras-ticket-selection-help' );

		if ( ! quantityInputs.length || ! submitButton || ! helpText ) {
			return;
		}

		function updateSelectionState() {
			var hasSelection = Array.prototype.some.call( quantityInputs, function ( input ) {
				return ! input.disabled && parseInt( input.value, 10 ) > 0;
			} );

			submitButton.disabled = ! hasSelection;
			submitButton.setAttribute( 'aria-disabled', hasSelection ? 'false' : 'true' );
			helpText.textContent = hasSelection ? helpText.dataset.readyMessage : helpText.dataset.emptyMessage;
		}

		quantityInputs.forEach( function ( input ) {
			input.addEventListener( 'input', updateSelectionState );
			input.addEventListener( 'change', updateSelectionState );
		} );

		updateSelectionState();
	}

	document.querySelectorAll( '.oras-ticket-selection-form' ).forEach( initializeTicketForm );
}() );

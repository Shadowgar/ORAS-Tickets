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

	function initializeTicketQuestionWizard( form ) {
		var wizard = form.querySelector( '.oras-ticket-question-wizard' );
		var questionPanel = wizard ? wizard.querySelector( '.oras-event-questions' ) : null;
		var fields = questionPanel ? Array.prototype.slice.call( questionPanel.querySelectorAll( '.oras-event-question-field' ) ) : [];
		var progress = wizard ? wizard.querySelector( '.oras-ticket-question-progress' ) : null;
		var finalPrompt = wizard ? wizard.querySelector( '.oras-ticket-question-final-prompt' ) : null;
		var controls = wizard ? wizard.querySelector( '.oras-ticket-question-controls' ) : null;
		var purchase = form.querySelector( '.oras-ticket-question-purchase' );

		if ( ! wizard || ! questionPanel || ! progress || ! finalPrompt || ! controls || ! purchase || ! fields.length ) {
			return;
		}

		wizard.classList.add( 'is-ready' );
		questionPanel.classList.add( 'oras-ticket-question-slider' );
		controls.innerHTML = '' +
			'<button type="button" class="button oras-ticket-question-back">Back</button>' +
			'<button type="button" class="button oras-ticket-question-next">Next</button>';

		var back = controls.querySelector( '.oras-ticket-question-back' );
		var next = controls.querySelector( '.oras-ticket-question-next' );
		var purchaseButton = purchase.querySelector( 'button[type="submit"]' );
		var currentIndex = 0;
		var autoAdvanceTimer = 0;

		function validateCurrentQuestion( report ) {
			var inputs = fields[ currentIndex ].querySelectorAll( 'input, select, textarea' );

			for ( var inputIndex = 0; inputIndex < inputs.length; inputIndex++ ) {
				if ( typeof inputs[ inputIndex ].checkValidity === 'function' && ! inputs[ inputIndex ].checkValidity() ) {
					if ( report !== false && typeof inputs[ inputIndex ].reportValidity === 'function' ) {
						inputs[ inputIndex ].reportValidity();
					}
					return false;
				}
			}

			return true;
		}

		function validateAllQuestions() {
			for ( var fieldIndex = 0; fieldIndex < fields.length; fieldIndex++ ) {
				var inputs = fields[ fieldIndex ].querySelectorAll( 'input, select, textarea' );
				for ( var inputIndex = 0; inputIndex < inputs.length; inputIndex++ ) {
					if ( typeof inputs[ inputIndex ].checkValidity === 'function' && ! inputs[ inputIndex ].checkValidity() ) {
						return false;
					}
				}
			}

			return true;
		}

		function setFieldEnabled( field, enabled ) {
			var inputs = field.querySelectorAll( 'input, select, textarea' );
			for ( var inputIndex = 0; inputIndex < inputs.length; inputIndex++ ) {
				inputs[ inputIndex ].disabled = ! enabled;
			}
		}

		function focusCurrentQuestion() {
			var input = fields[ currentIndex ].querySelector( 'input, select, textarea' );
			if ( input && typeof input.focus === 'function' ) {
				input.focus( { preventScroll: true } );
			}
		}

		function showQuestion( index, direction, shouldFocus ) {
			currentIndex = Math.max( 0, Math.min( index, fields.length - 1 ) );
			var isLast = currentIndex === fields.length - 1;

			for ( var fieldIndex = 0; fieldIndex < fields.length; fieldIndex++ ) {
				var isActive = fieldIndex === currentIndex;
				fields[ fieldIndex ].hidden = ! isActive;
				fields[ fieldIndex ].classList.toggle( 'is-active', isActive );
				fields[ fieldIndex ].classList.toggle( 'is-slide-forward', isActive && direction === 'forward' );
				fields[ fieldIndex ].classList.toggle( 'is-slide-back', isActive && direction === 'back' );
				setFieldEnabled( fields[ fieldIndex ], isActive || isLast );
			}

			progress.textContent = 'Question ' + ( currentIndex + 1 ) + ' of ' + fields.length;
			back.disabled = currentIndex === 0;
			next.hidden = isLast;
			finalPrompt.hidden = ! isLast;
			purchase.hidden = ! isLast;
			if ( purchaseButton ) {
				purchaseButton.disabled = isLast && ! validateAllQuestions();
			}

			if ( shouldFocus ) {
				focusCurrentQuestion();
			}
		}

		back.addEventListener( 'click', function () {
			window.clearTimeout( autoAdvanceTimer );
			showQuestion( currentIndex - 1, 'back', true );
		} );

		next.addEventListener( 'click', function () {
			if ( validateCurrentQuestion() ) {
				showQuestion( currentIndex + 1, 'forward', true );
			}
		} );

		for ( var fieldIndex = 0; fieldIndex < fields.length; fieldIndex++ ) {
			var controlsInField = fields[ fieldIndex ].querySelectorAll( 'input, select, textarea' );
			for ( var controlIndex = 0; controlIndex < controlsInField.length; controlIndex++ ) {
				controlsInField[ controlIndex ].addEventListener( 'input', function () {
					if ( currentIndex === fields.length - 1 && purchaseButton ) {
						purchaseButton.disabled = ! validateAllQuestions();
					}
				} );
				controlsInField[ controlIndex ].addEventListener( 'change', function () {
					if ( currentIndex === fields.length - 1 && purchaseButton ) {
						purchaseButton.disabled = ! validateAllQuestions();
					}

					if ( this.tagName !== 'SELECT' && this.type !== 'radio' ) {
						return;
					}

					if ( currentIndex === fields.length - 1 || ! validateCurrentQuestion( false ) ) {
						return;
					}

					window.clearTimeout( autoAdvanceTimer );
					autoAdvanceTimer = window.setTimeout( function () {
						showQuestion( currentIndex + 1, 'forward', true );
					}, 180 );
				} );
			}
		}

		showQuestion( 0, 'forward', false );
	}

	document.querySelectorAll( '.oras-ticket-selection-form' ).forEach( initializeTicketForm );
	document.querySelectorAll( '.oras-ticket-question-form' ).forEach( initializeTicketQuestionWizard );
}() );

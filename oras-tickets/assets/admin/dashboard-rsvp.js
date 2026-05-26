jQuery( document ).ready( function( $ ) {
	'use strict';

	var adminBase = orasDashboardRsvp.adminBaseUrl || ( globalThis.ajaxurl ? globalThis.ajaxurl.replace("admin-ajax.php","") : "/wp-admin/" );
	var ALLOWED_ADMIN_POST_ACTIONS = {
		oras_rsvp_export_yes: true,
		oras_rsvp_export_waitlist: true,
		oras_rsvp_promote: true,
		oras_attendees_export_csv: true
	};
	var ALLOWED_SOURCE_FILTERS = {
		all: true,
		tickets: true,
		rsvp: true,
		both: true
	};
	var ALLOWED_TICKET_STATUSES = {
		all: true,
		completed: true,
		processing: true,
		'on-hold': true,
		refunded: true,
		cancelled: true,
		failed: true
	};

	function sanitizeEventId( value ) {
		var parsed = Number.parseInt( value, 10 );
		if ( Number.isNaN( parsed ) || parsed <= 0 ) {
			return '';
		}
		return String( parsed );
	}

	function sanitizeEnum( value, allowedValues, fallback ) {
		var normalized = String( value || '' );
		return Object.hasOwn( allowedValues, normalized ) ? normalized : fallback;
	}

	function sanitizeSearchTerm( value ) {
		var normalized = String( value || '' ).trim().replaceAll( /[\u0000-\u001F\u007F]/g, '' );
		if ( normalized.length > 200 ) {
			normalized = normalized.slice( 0, 200 );
		}
		return normalized;
	}

	function escapeHtml( value ) {
		return String( value ?? '' )
			.replaceAll( '&', '&amp;' )
			.replaceAll( '<', '&lt;' )
			.replaceAll( '>', '&gt;' )
			.replaceAll( '"', '&quot;' )
			.replaceAll( "'", '&#39;' );
	}

	function normalizeInt( value ) {
		var parsed = Number.parseInt( value, 10 );
		return Number.isNaN( parsed ) ? 0 : parsed;
	}

	function formatDateLabel( value ) {
		var normalized = String( value || '' ).trim();
		if ( normalized === '' ) {
			return '';
		}

		return normalized + ' UTC';
	}

	function getTrustedAdminPostUrl() {
		var fallback = new URL( adminBase + 'admin-post.php', document.baseURI );
		fallback.search = '';
		fallback.hash = '';
		var rawUrl = typeof orasDashboardRsvp.adminPostUrl === 'string' ? orasDashboardRsvp.adminPostUrl : '';
		if ( rawUrl === '' ) {
			return fallback.toString();
		}
			try {
				var candidate = new URL( rawUrl, document.baseURI );
				if ( candidate.origin !== fallback.origin ) {
					return fallback.toString();
			}
			if ( candidate.pathname !== fallback.pathname ) {
				return fallback.toString();
			}
			if ( candidate.username || candidate.password ) {
				return fallback.toString();
			}
				candidate.search = '';
				candidate.hash = '';
				return candidate.toString();
			} catch ( err ) {
				globalThis.console?.warn?.( 'Invalid adminPostUrl, using fallback', err );
				return fallback.toString();
			}
		}

	function submitAdminPostGet( action, params ) {
		if ( ! Object.hasOwn( ALLOWED_ADMIN_POST_ACTIONS, action ) ) {
			return;
		}

		var nonce = String( orasDashboardRsvp.nonce || '' );
		if ( nonce === '' ) {
			return;
		}

		var payload = {};
		var input = params || {};
		var keys = Object.keys( input );
		for ( var key of keys ) {
			payload[ key ] = input[ key ];
		}
		payload.action = action;
		payload._wpnonce = nonce;

		var form = document.createElement( 'form' );
		form.method = 'GET';
		form.action = getTrustedAdminPostUrl();
		form.style.display = 'none';

		var payloadKeys = Object.keys( payload );
		for ( var key of payloadKeys ) {
			if ( payload[key] === null || payload[key] === undefined ) {
				continue;
			}

			var hidden = document.createElement( 'input' );
			hidden.type = 'hidden';
			hidden.name = key;
			hidden.value = String( payload[key] );
			form.appendChild( hidden );
		}

		document.body.appendChild( form );
		form.submit();
		form.remove();
	}

	var $selector = $( '#oras-rsvp-event-selector' );
	var $stats = $( '#oras-rsvp-stats' );
	var $actions = $( '#oras-rsvp-actions' );
	var $list = $( '#oras-rsvp-list' );
	var $capacity = $( '#oras-rsvp-capacity' );
	var $yesCount = $( '#oras-rsvp-yes-count' );
	var $waitlistCount = $( '#oras-rsvp-waitlist-count' );
	var $isFull = $( '#oras-rsvp-is-full' );
	var $attendeesBody = $( '#oras-rsvp-attendees-body' );
	var $waitlistOps = $( '#oras-waitlist-ops' );
	var $waitlistQueueBody = $( '#oras-waitlist-queue-body' );
	var $waitlistHistoryBody = $( '#oras-waitlist-history-body' );
	var $waitlistBulkCount = $( '#oras-waitlist-bulk-count' );
	var $waitlistBulkPromote = $( '#oras-waitlist-bulk-promote' );
	var $waitlistRefresh = $( '#oras-waitlist-refresh' );
	var $waitlistOperationMessage = $( '#oras-waitlist-operation-message' );
	var $attendeesEventSelector = $( '#oras-attendees-event-selector' );
	var $attendeesFilters = $( '#oras-attendees-filters' );
	var $attendeesSummary = $( '#oras-attendees-summary' );
	var $attendeesTotalRows = $( '#oras-attendees-total-rows' );
	var $attendeesTotalOrders = $( '#oras-attendees-total-orders' );
	var $attendeesTotalTickets = $( '#oras-attendees-total-tickets' );
	var $attendeesLoading = $( '#oras-attendees-loading' );
	var $attendeesTableContainer = $( '#oras-attendees-table-container' );
	var $attendeesBodyTable = $( '#oras-attendees-body' );
	var $attendeesSourceFilter = $( '#oras-attendees-source-filter' );
	var $attendeesTicketStatusFilter = $( '#oras-attendees-ticket-status-filter' );
	var $attendeesGuestsOnly = $( '#oras-attendees-guests-only' );
	var $attendeesHasNoteOnly = $( '#oras-attendees-has-note-only' );
	var $attendeesSearch = $( '#oras-attendees-search' );
	var $attendeesExportCsv = $( '#oras-attendees-export-csv' );
	var $attendeesPrint = $( '#oras-attendees-print' );
	var $attendeesMessagePanel = $( '#oras-attendees-message-panel' );
	var $attendeesMessageSubject = $( '#oras-attendees-message-subject' );
	var $attendeesMessageBody = $( '#oras-attendees-message-body' );
	var $attendeesMessageBcc = $( '#oras-attendees-message-bcc' );
	var $attendeesMessageCcMe = $( '#oras-attendees-message-cc-me' );
	var $attendeesSendEmail = $( '#oras-attendees-send-email' );

	$selector.on( 'change', function() {
		var eventId = $( this ).val();
		if ( ! eventId ) {
			$stats.hide();
			$actions.hide();
			$list.hide();
			$waitlistOps.hide();
			setWaitlistMessage( '', false );
			return;
		}

		loadRsvpData( eventId );
	} );

	$( '#oras-rsvp-export-yes' ).on( 'click', function() {
		var eventId = sanitizeEventId( $selector.val() );
		if ( ! eventId ) {
			return;
		}
		submitAdminPostGet(
			'oras_rsvp_export_yes',
			{
				event_id: eventId
			}
		);
	} );

	$( '#oras-rsvp-export-waitlist' ).on( 'click', function() {
		var eventId = sanitizeEventId( $selector.val() );
		if ( ! eventId ) {
			return;
		}
		submitAdminPostGet(
			'oras_rsvp_export_waitlist',
			{
				event_id: eventId
			}
		);
	} );

	$( '#oras-rsvp-promote' ).on( 'click', function() {
		var eventId = sanitizeEventId( $selector.val() );
		if ( ! eventId ) {
			return;
		}
		submitAdminPostGet(
			'oras_rsvp_promote',
			{
				event_id: eventId
			}
		);
	} );

	function loadRsvpData( eventId ) {
		$.ajax( {
			url: orasDashboardRsvp.ajaxUrl,
			type: 'POST',
			data: {
				action: 'oras_rsvp_dashboard_data',
				event_id: eventId,
				nonce: orasDashboardRsvp.nonce
			},
				success: function( response ) {
					if ( response.success ) {
						updateStats( response.data.stats );
						updateAttendees( response.data.attendees );
						$stats.show();
						$actions.show();
						$list.show();
						$waitlistOps.show();
						loadWaitlistQueueData( eventId, true );
					} else {
						alert( 'Error loading RSVP data: ' + response.data );
					}
				},
			error: function( xhr, status, error ) {
				alert( 'AJAX error occurred while loading RSVP data.' );
			}
		} );
	}

	function setWaitlistMessage( message, isError ) {
		$waitlistOperationMessage.text( String( message || '' ) );
		$waitlistOperationMessage.css( 'color', isError ? '#b32d2e' : '#2271b1' );
	}

	function loadWaitlistQueueData( eventId, quiet ) {
		var cleanEventId = sanitizeEventId( eventId );
		if ( ! cleanEventId ) {
			return;
		}

		$.ajax( {
			url: orasDashboardRsvp.ajaxUrl,
			type: 'POST',
			data: {
				action: 'oras_waitlist_queue_data',
				event_id: cleanEventId,
				nonce: orasDashboardRsvp.nonce
			},
			success: function( response ) {
				if ( ! response.success ) {
					setWaitlistMessage( 'Unable to load waitlist queue data.', true );
					return;
				}

				populateWaitlistQueueTable( Array.isArray( response.data.queue ) ? response.data.queue : [] );
				populateWaitlistHistoryTable( Array.isArray( response.data.history ) ? response.data.history : [] );
				if ( ! quiet ) {
					setWaitlistMessage( 'Queue refreshed.', false );
				}
			},
			error: function() {
				setWaitlistMessage( 'Network error while loading queue data.', true );
			}
		} );
	}

	function populateWaitlistQueueTable( queueRows ) {
		$waitlistQueueBody.empty();

		if ( queueRows.length === 0 ) {
			$waitlistQueueBody
				.append( $( '<tr/>' )
					.append( $( '<td/>' ).attr( 'colspan', 6 ).text( 'No attendees are currently waiting.' ) ) );
			return;
		}

		$.each( queueRows, function( index, row ) {
			var userId = normalizeInt( row.user_id );
			var position = normalizeInt( row.position ) > 0 ? normalizeInt( row.position ) : ( index + 1 );
			var joinedAt = formatDateLabel( row.joined_at );
			var $actions = $( '<td/>' );
			var $promoteButton = $( '<button/>' )
				.attr( {
					type: 'button',
					'class': 'button button-small oras-waitlist-promote-user'
				} )
				.attr( 'data-user-id', String( userId ) )
				.text( 'Promote' );
			var $removeButton = $( '<button/>' )
				.attr( {
					type: 'button',
					'class': 'button button-small oras-waitlist-remove-user'
				} )
				.attr( 'data-user-id', String( userId ) )
				.text( 'Remove' );

			$actions.append( $promoteButton ).append( ' ' ).append( $removeButton );

			$waitlistQueueBody.append(
				$( '<tr/>' )
					.append( $( '<td/>' ).text( String( position ) ) )
					.append( $( '<td/>' ).text( String( row.name ?? '' ) ) )
					.append( $( '<td/>' ).text( String( row.email ?? '' ) ) )
					.append( $( '<td/>' ).text( String( joinedAt ) ) )
					.append( $( '<td/>' ).text( String( row.source ?? '' ) ) )
					.append( $actions )
			);
		} );
	}

	function populateWaitlistHistoryTable( historyRows ) {
		$waitlistHistoryBody.empty();

		if ( historyRows.length === 0 ) {
			$waitlistHistoryBody
				.append( $( '<tr/>' )
					.append( $( '<td/>' ).attr( 'colspan', 6 ).text( 'No waitlist history entries yet.' ) ) );
			return;
		}

		$.each( historyRows, function( index, row ) {
			var updatedAt = formatDateLabel( row.updated_at );

			$waitlistHistoryBody.append(
				$( '<tr/>' )
					.append( $( '<td/>' ).text( String( row.name ?? '' ) ) )
					.append( $( '<td/>' ).text( String( row.status ?? '' ) ) )
					.append( $( '<td/>' ).text( String( row.last_action ?? '' ) ) )
					.append( $( '<td/>' ).text( String( row.source ?? '' ) ) )
					.append( $( '<td/>' ).text( String( row.actor_name ?? '' ) ) )
					.append( $( '<td/>' ).text( String( updatedAt ) ) )
			);
		} );
	}

	$waitlistRefresh.on( 'click', function() {
		var eventId = sanitizeEventId( $selector.val() );
		if ( ! eventId ) {
			return;
		}

		loadWaitlistQueueData( eventId );
	} );

	$waitlistBulkPromote.on( 'click', function() {
		var eventId = sanitizeEventId( $selector.val() );
		if ( ! eventId ) {
			return;
		}

		var count = normalizeInt( $waitlistBulkCount.val() );
		if ( count <= 0 ) {
			count = 1;
		}
		if ( count > 25 ) {
			count = 25;
		}

		if ( ! globalThis.confirm( 'Promote the next ' + count + ' attendee(s) from the waitlist queue?' ) ) {
			return;
		}

		$waitlistBulkPromote.prop( 'disabled', true );
		setWaitlistMessage( 'Running bulk promotion from queue...', false );

		$.ajax( {
			url: orasDashboardRsvp.ajaxUrl,
			type: 'POST',
			data: {
				action: 'oras_waitlist_bulk_promote',
				event_id: eventId,
				count: String( count ),
				nonce: orasDashboardRsvp.nonce
			},
			success: function( response ) {
				$waitlistBulkPromote.prop( 'disabled', false );
				if ( response.success ) {
					var promoted = normalizeInt( response.data.promoted_count );
					setWaitlistMessage( 'Promoted ' + promoted + ' attendee(s) from queue.', false );
					loadRsvpData( eventId );
				} else {
					setWaitlistMessage( String( response.data || 'Unable to run bulk promotion.' ), true );
				}
			},
			error: function() {
				$waitlistBulkPromote.prop( 'disabled', false );
				setWaitlistMessage( 'Network error during bulk promotion.', true );
			}
		} );
	} );

	$( document ).on( 'click', '.oras-waitlist-promote-user', function() {
		var eventId = sanitizeEventId( $selector.val() );
		var userId = normalizeInt( $( this ).data( 'user-id' ) );
		var $button = $( this );
		if ( ! eventId || userId <= 0 ) {
			return;
		}

		if ( ! globalThis.confirm( 'Promote this attendee from waitlist to RSVP Yes?' ) ) {
			return;
		}

		$button.prop( 'disabled', true );
		setWaitlistMessage( 'Promoting selected waitlist attendee...', false );
		$.ajax( {
			url: orasDashboardRsvp.ajaxUrl,
			type: 'POST',
			data: {
				action: 'oras_waitlist_promote_user',
				event_id: eventId,
				user_id: String( userId ),
				nonce: orasDashboardRsvp.nonce
			},
			success: function( response ) {
				$button.prop( 'disabled', false );
				if ( response.success ) {
					setWaitlistMessage( 'Selected waitlist attendee promoted.', false );
					loadRsvpData( eventId );
				} else {
					setWaitlistMessage( String( response.data || 'Unable to promote selected waitlist attendee.' ), true );
				}
			},
			error: function() {
				$button.prop( 'disabled', false );
				setWaitlistMessage( 'Network error promoting selected attendee.', true );
			}
		} );
	} );

	$( document ).on( 'click', '.oras-waitlist-remove-user', function() {
		var eventId = sanitizeEventId( $selector.val() );
		var userId = normalizeInt( $( this ).data( 'user-id' ) );
		var $button = $( this );
		if ( ! eventId || userId <= 0 ) {
			return;
		}

		if ( ! globalThis.confirm( 'Remove this attendee from the waitlist queue and mark RSVP as No?' ) ) {
			return;
		}

		$button.prop( 'disabled', true );
		setWaitlistMessage( 'Removing attendee from waitlist queue...', false );
		$.ajax( {
			url: orasDashboardRsvp.ajaxUrl,
			type: 'POST',
			data: {
				action: 'oras_waitlist_remove_user',
				event_id: eventId,
				user_id: String( userId ),
				nonce: orasDashboardRsvp.nonce
			},
			success: function( response ) {
				$button.prop( 'disabled', false );
				if ( response.success ) {
					setWaitlistMessage( 'Attendee removed from waitlist queue.', false );
					loadRsvpData( eventId );
				} else {
					setWaitlistMessage( String( response.data || 'Unable to remove attendee from queue.' ), true );
				}
			},
			error: function() {
				$button.prop( 'disabled', false );
				setWaitlistMessage( 'Network error removing attendee.', true );
			}
		} );
	} );

	function updateStats( stats ) {
		$capacity.text( stats.capacity );
		$yesCount.text( stats.yes_count );
		$waitlistCount.text( stats.waitlist_count );
		$isFull.text( stats.is_full ? 'Yes' : 'No' );
	}

	function updateAttendees( attendees ) {
		$attendeesBody.empty();
		if ( attendees.length === 0 ) {
			$attendeesBody
				.append( $( '<tr/>' )
					.append( $( '<td/>' ).attr( 'colspan', 4 ).text( 'No attendees found.' ) ) );
			return;
		}

		$.each( attendees, function( index, attendee ) {
			var userId = normalizeInt( attendee.user_id );
			var statusKey = String( attendee.status_key ?? '' );
			var canRemove = Boolean( attendee.can_remove ) && userId > 0;
			var $actions = $( '<td/>' );

			if ( canRemove ) {
				$actions.append(
					$( '<button/>' )
						.attr( {
							type: 'button',
							'class': 'button button-small oras-rsvp-remove-user'
						} )
						.attr( 'data-user-id', String( userId ) )
						.attr( 'data-status-key', statusKey )
						.text( statusKey === 'waitlist' ? 'Remove from Waitlist' : 'Remove RSVP' )
				);
			}

			$attendeesBody.append(
				$( '<tr/>' )
					.append( $( '<td/>' ).text( String( attendee.name ?? '' ) ) )
					.append( $( '<td/>' ).text( String( attendee.email ?? '' ) ) )
					.append( $( '<td/>' ).text( String( attendee.status ?? '' ) ) )
					.append( $actions )
			);
		} );
	}

	$( document ).on( 'click', '.oras-rsvp-remove-user', function() {
		var eventId = sanitizeEventId( $selector.val() );
		var userId = normalizeInt( $( this ).data( 'user-id' ) );
		var statusKey = String( $( this ).data( 'status-key' ) || '' );
		var $button = $( this );
		var confirmMessage = statusKey === 'waitlist'
			? 'Remove this attendee from the waitlist and mark them as not attending?'
			: 'Remove this attendee from the RSVP list and mark them as not attending?';

		if ( ! eventId || userId <= 0 ) {
			return;
		}

		if ( ! globalThis.confirm( confirmMessage ) ) {
			return;
		}

		$button.prop( 'disabled', true );
		setWaitlistMessage( 'Updating RSVP status...', false );

		$.ajax( {
			url: orasDashboardRsvp.ajaxUrl,
			type: 'POST',
			data: {
				action: 'oras_rsvp_remove_attendee',
				event_id: eventId,
				user_id: String( userId ),
				nonce: orasDashboardRsvp.nonce
			},
			success: function( response ) {
				$button.prop( 'disabled', false );
				if ( response.success ) {
					setWaitlistMessage( 'Attendee removed from RSVP.', false );
					loadRsvpData( eventId );
				} else {
					setWaitlistMessage( String( response.data || 'Unable to update RSVP.' ), true );
				}
			},
			error: function() {
				$button.prop( 'disabled', false );
				setWaitlistMessage( 'Network error updating RSVP.', true );
			}
		} );
	} );

	$attendeesEventSelector.on( 'change', function() {
		var eventId = $( this ).val();
		if ( ! eventId ) {
			$attendeesFilters.hide();
			$attendeesSummary.hide();
			$attendeesLoading.hide();
			$attendeesMessagePanel.hide();
			$attendeesTableContainer.hide();
			return;
		}
		$attendeesFilters.show();
		$attendeesMessagePanel.show();
		loadAttendeesData( eventId );
	} );

	$attendeesSourceFilter.on( 'change', function() {
		var eventId = $attendeesEventSelector.val();
		if ( eventId ) {
			loadAttendeesData( eventId );
		}
	} );

	$attendeesTicketStatusFilter.on( 'change', function() {
		var eventId = $attendeesEventSelector.val();
		if ( eventId ) {
			loadAttendeesData( eventId );
		}
	} );

	$attendeesGuestsOnly.on( 'change', function() {
		var eventId = $attendeesEventSelector.val();
		if ( eventId ) {
			loadAttendeesData( eventId );
		}
	} );

	$attendeesHasNoteOnly.on( 'change', function() {
		var eventId = $attendeesEventSelector.val();
		if ( eventId ) {
			loadAttendeesData( eventId );
		}
	} );

	$attendeesSearch.on( 'input', function() {
		var eventId = $attendeesEventSelector.val();
		if ( eventId ) {
			loadAttendeesData( eventId );
		}
	} );

	$attendeesPrint.on( 'click', function() {
		globalThis.print();
	} );

	$attendeesExportCsv.on( 'click', function() {
		var eventId = sanitizeEventId( $attendeesEventSelector.val() );
		if ( ! eventId ) {
			return;
		}
		var sourceFilter = sanitizeEnum( $attendeesSourceFilter.val(), ALLOWED_SOURCE_FILTERS, 'all' );
		var ticketStatus = sanitizeEnum( $attendeesTicketStatusFilter.val(), ALLOWED_TICKET_STATUSES, 'all' );
		var guestsOnly = $attendeesGuestsOnly.is( ':checked' ) ? '1' : '0';
		var hasNoteOnly = $attendeesHasNoteOnly.is( ':checked' ) ? '1' : '0';
		var search = sanitizeSearchTerm( $attendeesSearch.val() );
		submitAdminPostGet(
			'oras_attendees_export_csv',
			{
				event_id: eventId,
				source_filter: sourceFilter,
				ticket_status: ticketStatus,
				guests_only: guestsOnly,
				has_note_only: hasNoteOnly,
				search: search
			}
		);
	} );

	$attendeesSendEmail.on( 'click', function() {
		var eventId = $attendeesEventSelector.val();
		if ( ! eventId ) {
			alert( 'Please select an event first.' );
			return;
		}

		var subject = $attendeesMessageSubject.val().trim();
		var message = $attendeesMessageBody.val().trim();
		var bcc = $attendeesMessageBcc.is( ':checked' ) ? '1' : '0';
		var ccMe = $attendeesMessageCcMe.is( ':checked' ) ? '1' : '0';

		if ( ! subject || ! message ) {
			alert( 'Please fill in both subject and message.' );
			return;
		}

		if ( ! confirm( 'Are you sure you want to send this email to the filtered attendees?' ) ) {
			return;
		}

		var sourceFilter = $attendeesSourceFilter.val();
		var ticketStatus = $attendeesTicketStatusFilter.val();
		var guestsOnly = $attendeesGuestsOnly.is( ':checked' ) ? '1' : '0';
		var hasNoteOnly = $attendeesHasNoteOnly.is( ':checked' ) ? '1' : '0';
		var search = $attendeesSearch.val().trim();

		$attendeesSendEmail.prop( 'disabled', true ).text( 'Sending...' );

		$.ajax( {
			url: orasDashboardRsvp.ajaxUrl,
			type: 'POST',
			data: {
				action: 'oras_attendees_send_email',
				event_id: eventId,
				source_filter: sourceFilter,
				ticket_status: ticketStatus,
				guests_only: guestsOnly,
				has_note_only: hasNoteOnly,
				search: search,
				subject: subject,
				message: message,
				bcc: bcc,
				cc_me: ccMe,
				nonce: orasDashboardRsvp.nonce
			},
			success: function( response ) {
				$attendeesSendEmail.prop( 'disabled', false ).text( 'Send Email' );

				if ( response.success ) {
					alert( 'Email sent successfully! Sent to ' + response.data.recipients + ' recipients in ' + response.data.chunks + ' chunks.' );
					$attendeesMessageSubject.val( '' );
					$attendeesMessageBody.val( '' );
				} else {
					alert( 'Error sending email: ' + ( response.data || 'Unknown error' ) );
				}
			},
			error: function() {
				$attendeesSendEmail.prop( 'disabled', false ).text( 'Send Email' );
				alert( 'Network error sending email.' );
			}
		} );
	} );

	function loadAttendeesData( eventId ) {
		var sourceFilter = $attendeesSourceFilter.val();
		var ticketStatus = $attendeesTicketStatusFilter.val();
		var guestsOnly = $attendeesGuestsOnly.is( ':checked' ) ? '1' : '0';
		var hasNoteOnly = $attendeesHasNoteOnly.is( ':checked' ) ? '1' : '0';
		var search = $attendeesSearch.val().trim();

		setAttendeesLoading( true );

		$.ajax( {
			url: orasDashboardRsvp.ajaxUrl,
			type: 'POST',
			data: {
				action: 'oras_attendees_dashboard_data',
				event_id: eventId,
				source_filter: sourceFilter,
				ticket_status: ticketStatus,
				guests_only: guestsOnly,
				has_note_only: hasNoteOnly,
				search: search,
				nonce: orasDashboardRsvp.nonce
			},
			success: function( response ) {
				if ( response.success ) {
					populateAttendeesTable( response.data.attendees );
					populateAttendeesSummary( response.data.summary || null );
					$attendeesTableContainer.show();
					if ( globalThis.ORAS_ATTENDEES_AUTO_PRINT === true ) {
						globalThis.ORAS_ATTENDEES_AUTO_PRINT = false;
						globalThis.setTimeout( function() { globalThis.print(); }, 150 );
					}
				} else {
					alert( 'Error loading attendees: ' + ( response.data || 'Unknown error' ) );
				}
			},
			error: function( xhr ) {
				var detail = '';
				if ( xhr && typeof xhr.responseText === 'string' ) {
					detail = xhr.responseText.slice( 0, 220 );
				}
				alert( 'Network error loading attendees.' + ( detail ? '\n\n' + detail : '' ) );
			},
			complete: function() {
				setAttendeesLoading( false );
			}
		} );
	}

	function setAttendeesLoading( isLoading ) {
		if ( isLoading ) {
			$attendeesLoading.show();
			$attendeesTableContainer.hide();
			$attendeesSummary.hide();
			return;
		}

		$attendeesLoading.hide();
	}

	function populateAttendeesSummary( summary ) {
		var data = summary && typeof summary === 'object' ? summary : {};
		$attendeesTotalRows.text( String( normalizeInt( data.total_rows ) ) );
		$attendeesTotalOrders.text( String( normalizeInt( data.total_orders ) ) );
		$attendeesTotalTickets.text( String( normalizeInt( data.total_tickets ) ) );
		$attendeesSummary.show();
	}

	function populateAttendeesTable( attendees ) {
		$attendeesBodyTable.empty();
		if ( attendees.length === 0 ) {
			$attendeesBodyTable
				.append( $( '<tr/>' )
					.append( $( '<td/>' ).attr( 'colspan', 12 ).text( 'No attendees found.' ) ) );
			return;
		}

		$.each( attendees, function( index, attendee ) {
			var userId = normalizeInt( attendee.user_id );
			var orderId = normalizeInt( attendee.order_id );
			var attendeeKey = String( attendee.attendee_key ?? '' );
			var emailValue = String( attendee.email ?? '' ).trim();
			var emailHref = 'mailto:' + encodeURIComponent( emailValue );
			var userIdLabel = userId > 0 ? String( userId ) : '';
			var orderIdLabel = orderId > 0 ? String( orderId ) : '';
			var noteRaw = String( attendee.note ?? '' );
			var $actionsCell = $( '<td/>' );
			var $noteCell = $( '<td/>' );
			var $row = $( '<tr/>' );
			var hasActions = false;

			if ( userId > 0 ) {
				$actionsCell.append(
					$( '<a/>' )
						.attr( {
							href: adminBase + 'user-edit.php?user_id=' + userId,
							target: '_blank'
						} )
						.text( 'View User' )
				);
				hasActions = true;
			}

			if ( orderId > 0 ) {
				if ( hasActions ) {
					$actionsCell.append( ' | ' );
				}
				$actionsCell.append(
					$( '<a/>' )
						.attr( {
							href: adminBase + 'post.php?post=' + orderId + '&action=edit',
							target: '_blank'
						} )
						.text( 'View Order' )
				);
				hasActions = true;
			}

			if ( hasActions ) {
				$actionsCell.append( ' | ' );
			}
			$actionsCell.append(
				$( '<a/>' )
					.attr( {
						href: '#',
						'class': 'oras-edit-note',
						'data-key': attendeeKey
					} )
					.text( 'Edit Note' )
			);
			hasActions = true;

			$actionsCell.append( ' | ' );
			$actionsCell.append( $( '<a/>' ).attr( 'href', emailHref ).text( 'Email' ) );

			var notePreview = noteRaw;
			if ( notePreview.length > 60 ) {
				notePreview = notePreview.substring( 0, 60 ) + '...';
			}
			$noteCell
				.append( $( '<span/>' ).addClass( 'oras-note-preview' ).text( notePreview ) )
				.append(
					$( '<div/>' )
						.addClass( 'oras-note-editor' )
						.css( {
							display: 'none',
							'margin-top': '5px'
						} )
						.append(
							$( '<textarea/>' )
								.addClass( 'oras-note-text' )
								.attr( {
									rows: 3
								} )
								.css( 'width', '100%' )
								.val( noteRaw )
						)
						.append(
							$( '<p/>' )
								.append(
									$( '<button/>' )
										.attr( {
											type: 'button',
											'class': 'button button-small oras-note-save',
											'data-key': attendeeKey
										} )
										.text( 'Save' )
								)
								.append( ' ' )
								.append(
									$( '<button/>' )
										.attr( {
											type: 'button',
											'class': 'button button-small oras-note-cancel'
										} )
										.text( 'Cancel' )
								)
						)
				);

			$row
				.append( $( '<td/>' ).text( String( attendee.name ?? '' ) ) )
				.append( $( '<td/>' ).text( String( emailValue ) ) )
				.append( $( '<td/>' ).text( String( attendee.source ?? '' ) ) )
				.append( $( '<td/>' ).text( String( attendee.phone ?? '' ) ) )
				.append( $( '<td/>' ).text( String( attendee.address ?? '' ) ) )
				.append( $( '<td/>' ).text( String( attendee.item_label ?? '' ) ) )
				.append( $( '<td/>' ).text( String( attendee.quantity ?? '' ) ) )
				.append( $( '<td/>' ).text( userIdLabel ) )
				.append( $( '<td/>' ).text( orderIdLabel ) )
				.append( $( '<td/>' ).text( String( attendee.order_status ?? '' ) ) )
				.append( $noteCell )
				.append( $actionsCell );

			$attendeesBodyTable.append( $row );
		} );
	}

	$( document ).on( 'click', '.oras-edit-note', function( e ) {
		e.preventDefault();
		var $cell = $( this ).closest( 'tr' ).find( 'td' ).eq( 10 );
		$cell.find( '.oras-note-preview' ).hide();
		$cell.find( '.oras-note-editor' ).show();
	} );

	$( document ).on( 'click', '.oras-note-cancel', function() {
		var $cell = $( this ).closest( 'td' );
		$cell.find( '.oras-note-editor' ).hide();
		$cell.find( '.oras-note-preview' ).show();
	} );

	$( document ).on( 'click', '.oras-note-save', function() {
		var eventId = $attendeesEventSelector.val();
		var attendeeKey = $( this ).data( 'key' );
		var $cell = $( this ).closest( 'td' );
		var note = $cell.find( '.oras-note-text' ).val().trim();

		$.ajax( {
			url: orasDashboardRsvp.ajaxUrl,
			type: 'POST',
			data: {
				action: 'oras_attendees_save_note',
				event_id: eventId,
				attendee_key: attendeeKey,
				note: note,
				nonce: orasDashboardRsvp.nonce
			},
			success: function( response ) {
				if ( response.success ) {
					loadAttendeesData( eventId );
				} else {
					alert( 'Error saving note: ' + ( response.data || 'Unknown error' ) );
				}
			},
			error: function() {
				alert( 'Network error saving note.' );
			}
		} );
	} );

	var initialEventId = sanitizeEventId( $attendeesEventSelector.val() );
	if ( initialEventId ) {
		$attendeesFilters.show();
		$attendeesMessagePanel.show();
		loadAttendeesData( initialEventId );
	}
} );

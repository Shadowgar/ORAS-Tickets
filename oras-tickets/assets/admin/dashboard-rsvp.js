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
	var $attendeesTableContainer = $( '#oras-attendees-table-container' );
	var $attendeesBodyTable = $( '#oras-attendees-body' );
	var $attendeesSourceFilter = $( '#oras-attendees-source-filter' );
	var $attendeesTicketStatusFilter = $( '#oras-attendees-ticket-status-filter' );
	var $attendeesGuestsOnly = $( '#oras-attendees-guests-only' );
	var $attendeesHasNoteOnly = $( '#oras-attendees-has-note-only' );
	var $attendeesSearch = $( '#oras-attendees-search' );
	var $attendeesExportCsv = $( '#oras-attendees-export-csv' );
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
			$waitlistQueueBody.append( '<tr><td colspan="6">No users currently waiting.</td></tr>' );
			return;
		}

		$.each( queueRows, function( index, row ) {
			var userId = normalizeInt( row.user_id );
			var position = normalizeInt( row.position ) > 0 ? normalizeInt( row.position ) : ( index + 1 );
			var name = escapeHtml( row.name );
			var email = escapeHtml( row.email );
			var joinedAt = escapeHtml( formatDateLabel( row.joined_at ) );
			var source = escapeHtml( row.source );
			var actionButtons = '<button type="button" class="button button-small oras-waitlist-promote-user" data-user-id="' + userId + '">Promote</button> ' +
				'<button type="button" class="button button-small oras-waitlist-remove-user" data-user-id="' + userId + '">Remove</button>';

			var html = '<tr>' +
				'<td>' + escapeHtml( String( position ) ) + '</td>' +
				'<td>' + name + '</td>' +
				'<td>' + email + '</td>' +
				'<td>' + joinedAt + '</td>' +
				'<td>' + source + '</td>' +
				'<td>' + actionButtons + '</td>' +
				'</tr>';

			$waitlistQueueBody.append( html );
		} );
	}

	function populateWaitlistHistoryTable( historyRows ) {
		$waitlistHistoryBody.empty();

		if ( historyRows.length === 0 ) {
			$waitlistHistoryBody.append( '<tr><td colspan="6">No waitlist history entries found.</td></tr>' );
			return;
		}

		$.each( historyRows, function( index, row ) {
			var name = escapeHtml( row.name );
			var status = escapeHtml( row.status );
			var lastAction = escapeHtml( row.last_action );
			var source = escapeHtml( row.source );
			var actorName = escapeHtml( row.actor_name || '' );
			var updatedAt = escapeHtml( formatDateLabel( row.updated_at ) );
			var html = '<tr>' +
				'<td>' + name + '</td>' +
				'<td>' + status + '</td>' +
				'<td>' + lastAction + '</td>' +
				'<td>' + source + '</td>' +
				'<td>' + actorName + '</td>' +
				'<td>' + updatedAt + '</td>' +
				'</tr>';

			$waitlistHistoryBody.append( html );
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

		$waitlistBulkPromote.prop( 'disabled', true );
		setWaitlistMessage( 'Promoting from waitlist...', false );

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
					setWaitlistMessage( 'Promoted ' + promoted + ' attendee(s).', false );
					loadRsvpData( eventId );
				} else {
					setWaitlistMessage( String( response.data || 'Unable to promote users.' ), true );
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
		if ( ! eventId || userId <= 0 ) {
			return;
		}

		setWaitlistMessage( 'Promoting selected attendee...', false );
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
				if ( response.success ) {
					setWaitlistMessage( 'Selected attendee promoted.', false );
					loadRsvpData( eventId );
				} else {
					setWaitlistMessage( String( response.data || 'Unable to promote selected attendee.' ), true );
				}
			},
			error: function() {
				setWaitlistMessage( 'Network error promoting selected attendee.', true );
			}
		} );
	} );

	$( document ).on( 'click', '.oras-waitlist-remove-user', function() {
		var eventId = sanitizeEventId( $selector.val() );
		var userId = normalizeInt( $( this ).data( 'user-id' ) );
		if ( ! eventId || userId <= 0 ) {
			return;
		}

		if ( ! globalThis.confirm( 'Remove this attendee from the waitlist?' ) ) {
			return;
		}

		setWaitlistMessage( 'Removing attendee from waitlist...', false );
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
				if ( response.success ) {
					setWaitlistMessage( 'Attendee removed from waitlist.', false );
					loadRsvpData( eventId );
				} else {
					setWaitlistMessage( String( response.data || 'Unable to remove attendee.' ), true );
				}
			},
			error: function() {
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
			$attendeesBody.append( '<tr><td colspan="3">No attendees found.</td></tr>' );
			return;
		}

		$.each( attendees, function( index, attendee ) {
			var name = escapeHtml( attendee.name );
			var email = escapeHtml( attendee.email );
			var status = escapeHtml( attendee.status );
			var row = '<tr>' +
				'<td>' + name + '</td>' +
				'<td>' + email + '</td>' +
				'<td>' + status + '</td>' +
				'</tr>';
			$attendeesBody.append( row );
		} );
	}

	$attendeesEventSelector.on( 'change', function() {
		var eventId = $( this ).val();
		if ( ! eventId ) {
			$attendeesFilters.hide();
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
					$attendeesTableContainer.show();
				} else {
					alert( 'Error loading attendees: ' + ( response.data || 'Unknown error' ) );
				}
			},
			error: function() {
				alert( 'Network error loading attendees.' );
			}
		} );
	}

	function populateAttendeesTable( attendees ) {
		$attendeesBodyTable.empty();
		if ( attendees.length === 0 ) {
			$attendeesBodyTable.append( '<tr><td colspan="8">No attendees found.</td></tr>' );
			return;
		}

		$.each( attendees, function( index, attendee ) {
			var actions = [];
			var userId = normalizeInt( attendee.user_id );
			var orderId = normalizeInt( attendee.order_id );
			var attendeeKey = escapeHtml( attendee.attendee_key );
			var emailValue = String( attendee.email ?? '' ).trim();
			var emailHref = 'mailto:' + encodeURIComponent( emailValue );
			var name = escapeHtml( attendee.name );
			var email = escapeHtml( emailValue );
			var source = escapeHtml( attendee.source );
			var orderStatus = escapeHtml( attendee.order_status );
			var userIdLabel = userId > 0 ? String( userId ) : '';
			var orderIdLabel = orderId > 0 ? String( orderId ) : '';
			var noteRaw = String( attendee.note ?? '' );

			if ( userId > 0 ) {
				actions.push( '<a href="' + adminBase + 'user-edit.php?user_id=' + userId + '" target="_blank">View User</a>' );
			}

			if ( orderId > 0 ) {
				actions.push( '<a href="' + adminBase + 'post.php?post=' + orderId + '&action=edit" target="_blank">View Order</a>' );
			}

			actions.push( '<a href="#" class="oras-edit-note" data-key="' + attendeeKey + '">Edit Note</a>' );

			actions.push( '<a href="' + emailHref + '">Email</a>' );

			var notePreview = noteRaw;
			if ( notePreview.length > 60 ) {
				notePreview = notePreview.substring( 0, 60 ) + '...';
			}
			var notePreviewEscaped = escapeHtml( notePreview );
			var noteEscaped = escapeHtml( noteRaw );

			var row = '<tr>' +
				'<td>' + name + '</td>' +
				'<td>' + email + '</td>' +
				'<td>' + source + '</td>' +
				'<td>' + escapeHtml( userIdLabel ) + '</td>' +
				'<td>' + escapeHtml( orderIdLabel ) + '</td>' +
				'<td>' + orderStatus + '</td>' +
				'<td><span class="oras-note-preview">' + notePreviewEscaped + '</span><div class="oras-note-editor" style="display:none; margin-top:5px;"><textarea class="oras-note-text" rows="3" style="width:100%;">' + noteEscaped + '</textarea><p><button type="button" class="button button-small oras-note-save" data-key="' + attendeeKey + '">Save</button> <button type="button" class="button button-small oras-note-cancel">Cancel</button></p></div></td>' +
				'<td>' + actions.join( ' | ' ) + '</td>' +
				'</tr>';
			$attendeesBodyTable.append( row );
		} );
	}

	$( document ).on( 'click', '.oras-edit-note', function( e ) {
		e.preventDefault();
		var $cell = $( this ).closest( 'tr' ).find( 'td' ).eq( 6 );
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
} );

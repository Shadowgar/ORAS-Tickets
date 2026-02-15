jQuery( document ).ready( function( $ ) {
	'use strict';

	var adminBase = orasDashboardRsvp.adminBaseUrl || ( window.ajaxurl ? window.ajaxurl.replace("admin-ajax.php","") : "/wp-admin/" );

	var $selector = $( '#oras-rsvp-event-selector' );
	var $stats = $( '#oras-rsvp-stats' );
	var $actions = $( '#oras-rsvp-actions' );
	var $list = $( '#oras-rsvp-list' );
	var $capacity = $( '#oras-rsvp-capacity' );
	var $yesCount = $( '#oras-rsvp-yes-count' );
	var $waitlistCount = $( '#oras-rsvp-waitlist-count' );
	var $isFull = $( '#oras-rsvp-is-full' );
	var $attendeesBody = $( '#oras-rsvp-attendees-body' );
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
			return;
		}

		loadRsvpData( eventId );
	} );

	$( '#oras-rsvp-export-yes' ).on( 'click', function() {
		var eventId = $selector.val();
		if ( ! eventId ) {
			return;
		}
		window.location.href = orasDashboardRsvp.adminPostUrl + '?action=oras_rsvp_export_yes&event_id=' + eventId + '&_wpnonce=' + orasDashboardRsvp.nonce;
	} );

	$( '#oras-rsvp-export-waitlist' ).on( 'click', function() {
		var eventId = $selector.val();
		if ( ! eventId ) {
			return;
		}
		window.location.href = orasDashboardRsvp.adminPostUrl + '?action=oras_rsvp_export_waitlist&event_id=' + eventId + '&_wpnonce=' + orasDashboardRsvp.nonce;
	} );

	$( '#oras-rsvp-promote' ).on( 'click', function() {
		var eventId = $selector.val();
		if ( ! eventId ) {
			return;
		}
		window.location.href = orasDashboardRsvp.adminPostUrl + '?action=oras_rsvp_promote&event_id=' + eventId + '&_wpnonce=' + orasDashboardRsvp.nonce;
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
				} else {
					alert( 'Error loading RSVP data: ' + response.data );
				}
			},
			error: function( xhr, status, error ) {
				alert( 'AJAX error occurred while loading RSVP data.' );
			}
		} );
	}

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
			var row = '<tr>' +
				'<td>' + attendee.name + '</td>' +
				'<td>' + attendee.email + '</td>' +
				'<td>' + attendee.status + '</td>' +
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
		var eventId = $attendeesEventSelector.val();
		if ( ! eventId ) {
			return;
		}
		var sourceFilter = $attendeesSourceFilter.val();
		var ticketStatus = $attendeesTicketStatusFilter.val();
		var guestsOnly = $attendeesGuestsOnly.is( ':checked' ) ? '1' : '0';
		var hasNoteOnly = $attendeesHasNoteOnly.is( ':checked' ) ? '1' : '0';
		var search = $attendeesSearch.val().trim();
		var url = orasDashboardRsvp.adminPostUrl + '?action=oras_attendees_export_csv&event_id=' + eventId + '&source_filter=' + sourceFilter + '&ticket_status=' + ticketStatus + '&guests_only=' + guestsOnly + '&has_note_only=' + hasNoteOnly + '&search=' + encodeURIComponent( search ) + '&_wpnonce=' + orasDashboardRsvp.nonce;
		window.location.href = url;
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

			if ( attendee.user_id > 0 ) {
				actions.push( '<a href="' + adminBase + 'user-edit.php?user_id=' + parseInt(attendee.user_id,10) + '" target="_blank">View User</a>' );
			}

			if ( attendee.order_id > 0 ) {
				actions.push( '<a href="' + adminBase + 'post.php?post=' + parseInt(attendee.order_id,10) + '&action=edit" target="_blank">View Order</a>' );
			}

			actions.push( '<a href="#" class="oras-edit-note" data-key="' + attendee.attendee_key + '">Edit Note</a>' );

			actions.push( '<a href="mailto:' + attendee.email + '">Email</a>' );

			var notePreview = attendee.note || '';
			if ( notePreview.length > 60 ) {
				notePreview = notePreview.substring( 0, 60 ) + '...';
			}

			var row = '<tr>' +
				'<td>' + attendee.name + '</td>' +
				'<td>' + attendee.email + '</td>' +
				'<td>' + attendee.source + '</td>' +
				'<td>' + ( attendee.user_id || '' ) + '</td>' +
				'<td>' + ( attendee.order_id || '' ) + '</td>' +
				'<td>' + attendee.order_status + '</td>' +
				'<td><span class="oras-note-preview">' + notePreview + '</span><div class="oras-note-editor" style="display:none; margin-top:5px;"><textarea class="oras-note-text" rows="3" style="width:100%;">' + ( attendee.note || '' ) + '</textarea><p><button type="button" class="button button-small oras-note-save" data-key="' + attendee.attendee_key + '">Save</button> <button type="button" class="button button-small oras-note-cancel">Cancel</button></p></div></td>' +
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
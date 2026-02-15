jQuery( document ).ready( function( $ ) {
	'use strict';

	var $selector = $( '#oras-rsvp-event-selector' );
	var $stats = $( '#oras-rsvp-stats' );
	var $actions = $( '#oras-rsvp-actions' );
	var $list = $( '#oras-rsvp-list' );
	var $capacity = $( '#oras-rsvp-capacity' );
	var $yesCount = $( '#oras-rsvp-yes-count' );
	var $waitlistCount = $( '#oras-rsvp-waitlist-count' );
	var $isFull = $( '#oras-rsvp-is-full' );
	var $attendeesBody = $( '#oras-rsvp-attendees-body' );

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
} );
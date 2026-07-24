<?php

namespace ORAS\Tickets\Integrations\Zoom;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Api_Interface {

	public function get_meeting( string $meeting_id );

	public function get_meeting_invitation( string $meeting_id );

	/**
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $meeting_properties
	 */
	public function update_meeting( string $meeting_id, array $settings, array $meeting_properties = array() );

	/**
	 * @param array<string,mixed> $registrant
	 */
	public function add_meeting_registrant( string $meeting_id, array $registrant );

	public function update_registrant_status(
		string $meeting_id,
		string $registrant_id,
		string $email,
		string $action
	);
}

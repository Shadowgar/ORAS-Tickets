<?php

namespace ORAS\Tickets\Integrations\Zoom;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Registration_Repository {

	/**
	 * @return array<string,mixed>
	 */
	public function find_by_event_email( int $event_id, string $meeting_id, string $email ): array;

	/**
	 * @param array<string,mixed> $registration
	 * @return array<string,mixed>
	 */
	public function save_registration( array $registration ): array;

	public function activate_source( int $registration_id, string $source_type, string $source_ref ): bool;

	public function deactivate_source( int $registration_id, string $source_type, string $source_ref ): bool;

	public function has_active_sources( int $registration_id ): bool;

	public function update_status( int $registration_id, string $status, string $error = '' ): bool;
}

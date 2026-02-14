<?php
/**
 * Local stubs for optional plugin integrations.
 *
 * @package ORAS\Tickets
 */

declare(strict_types=1);

if (! function_exists('pmpro_getLevel')) {
	/**
	 * @param int $level_id
	 * @return array<string,mixed>|object|null
	 */
	function pmpro_getLevel($level_id) {
		return null;
	}
}

if (! function_exists('pmpro_changeMembershipLevel')) {
	/**
	 * @param int|array<int,mixed> $level
	 * @param int $user_id
	 * @param mixed $old_level_status
	 * @return bool
	 */
	function pmpro_changeMembershipLevel($level, $user_id = null, $old_level_status = 'inactive') {
		return false;
	}
}

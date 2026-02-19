<?php

namespace ORAS\Tickets;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Capabilities {

    public const CAPS = [
        'oras_tickets_manage_settings',
        'oras_tickets_manage_events',
        'oras_tickets_manage_rsvps',
        'oras_tickets_view_attendees',
        'oras_tickets_manage_attendees',
        'oras_tickets_checkin',
        'oras_tickets_send_notifications',
        'oras_tickets_view_reports',
        'oras_tickets_export_reports',
        'oras_tickets_manage_speakers',
    ];

    public static function add_caps(): void {
        if ( ! function_exists( 'get_role' ) ) {
            return;
        }

        $role = get_role( 'administrator' );
        if ( ! $role ) {
            return;
        }

        foreach ( self::CAPS as $cap ) {
            $role->add_cap( $cap );
        }
    }

    public static function remove_caps(): void {
        if ( ! function_exists( 'get_role' ) ) {
            return;
        }

        $role = get_role( 'administrator' );
        if ( ! $role ) {
            return;
        }

        foreach ( self::CAPS as $cap ) {
            $role->remove_cap( $cap );
        }
    }
}

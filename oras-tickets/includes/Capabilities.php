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
        'oras_tickets_view_board_dashboard',
        'oras_tickets_manage_speakers',
    ];

    public const TREASURER_ONLY_CAPS = [
        'oras_tickets_view_treasurer_reconciliation',
    ];

    public const BOARD_COMMUNICATION_CAPS = [
        'oras_tickets_view_board_dashboard',
        'oras_tickets_send_notifications',
        'oras_tickets_manage_rsvps',
    ];

    public static function add_caps(): void {
        if ( ! function_exists( 'get_role' ) ) {
            return;
        }

        $role_slugs = array( 'administrator', 'board', 'board_member' );

        foreach ( $role_slugs as $role_slug ) {
            $role = get_role( $role_slug );
            if ( ! $role ) {
                continue;
            }

            foreach ( self::CAPS as $cap ) {
                $role->add_cap( $cap );
            }
        }

        $treasurer_role_slugs = array( 'administrator', 'treasurer' );
        foreach ( $treasurer_role_slugs as $role_slug ) {
            $role = get_role( $role_slug );
            if ( ! $role ) {
                continue;
            }

            foreach ( self::TREASURER_ONLY_CAPS as $cap ) {
                $role->add_cap( $cap );
            }
        }
    }

    public static function remove_caps(): void {
        if ( ! function_exists( 'get_role' ) ) {
            return;
        }

        $role_slugs = array( 'administrator', 'board', 'board_member' );

        foreach ( $role_slugs as $role_slug ) {
            $role = get_role( $role_slug );
            if ( ! $role ) {
                continue;
            }

            foreach ( self::CAPS as $cap ) {
                $role->remove_cap( $cap );
            }
        }

        $treasurer_role_slugs = array( 'administrator', 'treasurer' );
        foreach ( $treasurer_role_slugs as $role_slug ) {
            $role = get_role( $role_slug );
            if ( ! $role ) {
                continue;
            }

            foreach ( self::TREASURER_ONLY_CAPS as $cap ) {
                $role->remove_cap( $cap );
            }
        }
    }

    public static function ensure_board_communication_caps(): void {
        if ( ! function_exists( 'get_role' ) ) {
            return;
        }

        $role_slugs = array( 'administrator', 'board', 'board_member' );

        foreach ( $role_slugs as $role_slug ) {
            $role = get_role( $role_slug );
            if ( ! $role ) {
                continue;
            }

            foreach ( self::BOARD_COMMUNICATION_CAPS as $cap ) {
                $role->add_cap( $cap );
            }
        }
    }
}

<?php

namespace ORAS\Tickets\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Settings_Page { // NOSONAR legacy WP class naming

    private const OPTION_KEY = 'oras_tickets_settings_v1';

    public function render(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'ORAS Tickets Settings', 'oras-tickets' ); ?></h1>

        <form method="post" action="options.php">
        <?php
        settings_fields( 'oras_tickets_settings' );
        do_settings_sections( 'oras_tickets_settings' );
        submit_button();
        ?>
        </form>
    </div>
        <?php
    }

    public static function register_settings(): void {
        register_setting(
            'oras_tickets_settings',
            self::OPTION_KEY,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( self::class, 'sanitize_settings' ),
                'default'           => self::get_default_settings(),
            )
        );

        add_settings_section(
            'oras_rsvp_defaults',
            __( 'RSVP Defaults', 'oras-tickets' ),
            array( self::class, 'render_rsvp_section' ),
            'oras_tickets_settings'
        );

        add_settings_field(
            'rsvp_default_enabled',
            __( 'Default Enabled', 'oras-tickets' ),
            array( self::class, 'render_checkbox_field' ),
            'oras_tickets_settings',
            'oras_rsvp_defaults',
            array(
                'field' => 'rsvp.default_enabled',
                'label' => __( 'Enable RSVP by default for new events', 'oras-tickets' ),
            )
        );

        add_settings_field(
            'rsvp_default_capacity',
            __( 'Default Capacity', 'oras-tickets' ),
            array( self::class, 'render_number_field' ),
            'oras_tickets_settings',
            'oras_rsvp_defaults',
            array(
                'field' => 'rsvp.default_capacity',
                'label' => __( 'Default capacity (0 = unlimited)', 'oras-tickets' ),
                'min'   => 0,
            )
        );

        add_settings_field(
            'rsvp_default_waitlist_enabled',
            __( 'Default Waitlist Enabled', 'oras-tickets' ),
            array( self::class, 'render_checkbox_field' ),
            'oras_tickets_settings',
            'oras_rsvp_defaults',
            array(
                'field' => 'rsvp.default_waitlist_enabled',
                'label' => __( 'Enable waitlist by default for new events', 'oras-tickets' ),
            )
        );

        add_settings_section(
            'oras_virtual_access_defaults',
            __( 'Virtual Access Defaults', 'oras-tickets' ),
            array( self::class, 'render_virtual_access_section' ),
            'oras_tickets_settings'
        );

        add_settings_field(
            'virtual_access_default_show_to',
            __( 'Default Show To', 'oras-tickets' ),
            array( self::class, 'render_select_field' ),
            'oras_tickets_settings',
            'oras_virtual_access_defaults',
            array(
                'field'   => 'virtual_access.default_show_to',
                'options' => array(
                    'everyone'    => __( 'Everyone', 'oras-tickets' ),
                    'logged_in'   => __( 'Logged In Users', 'oras-tickets' ),
                    'rsvp'        => __( 'RSVP Attendees', 'oras-tickets' ),
                    'ticket'      => __( 'Ticket Holders', 'oras-tickets' ),
                    'free_ticket' => __( 'Free Ticket Holders', 'oras-tickets' ),
                ),
            )
        );

        add_settings_section(
            'oras_tickets_defaults',
            __( 'Tickets Defaults', 'oras-tickets' ),
            array( self::class, 'render_tickets_section' ),
            'oras_tickets_settings'
        );

        add_settings_field(
            'tickets_auto_complete_ticket_only_orders',
            __( 'Auto-complete Ticket-only Orders', 'oras-tickets' ),
            array( self::class, 'render_checkbox_field' ),
            'oras_tickets_settings',
            'oras_tickets_defaults',
            array(
                'field' => 'tickets.auto_complete_ticket_only_orders',
                'label' => __( 'Automatically complete orders containing only tickets (no physical products)', 'oras-tickets' ),
            )
        );
    }

    public static function sanitize_settings( $input ): array {
        $defaults = self::get_default_settings();
$sanitized = array(
            'version'        => 1,
'rsvp'           => array(
                'default_enabled'          => ! empty( $input['rsvp']['default_enabled'] ),
                'default_capacity'         => absint( $input['rsvp']['default_capacity'] ?? 0 ),
                'default_waitlist_enabled' => ! empty( $input['rsvp']['default_waitlist_enabled'] ),
            ),
            'virtual_access' => array(
                'default_show_to' => self::sanitize_show_to( $input['virtual_access']['default_show_to'] ?? '' ),
            ),
            'tickets'        => array(
                'auto_complete_ticket_only_orders' => ! empty( $input['tickets']['auto_complete_ticket_only_orders'] ),
            ),
        );

        return $sanitized;
    }

    private static function sanitize_show_to( string $value ): string {
        $allowed = array( 'everyone', 'logged_in', 'rsvp', 'ticket', 'free_ticket' );
        $sanitized = sanitize_key( $value );
        return in_array( $sanitized, $allowed, true ) ? $sanitized : 'logged_in';
    }

    public static function get_default_settings(): array {
        return array(
            'version'        => 1,
            'rsvp'           => array(
                'default_enabled'          => false,
                'default_capacity'         => 0,
                'default_waitlist_enabled' => true,
            ),
            'virtual_access' => array(
                'default_show_to' => 'logged_in',
            ),
            'tickets'        => array(
                'auto_complete_ticket_only_orders' => true,
            ),
        );
    }

    public static function get_settings(): array {
        return get_option( self::OPTION_KEY, self::get_default_settings() );
    }

    public static function render_rsvp_section(): void {
        echo '<p>' . esc_html__( 'Configure default RSVP settings for new events.', 'oras-tickets' ) . '</p>';
    }

    public static function render_virtual_access_section(): void {
        echo '<p>' . esc_html__( 'Configure default virtual access settings for new events.', 'oras-tickets' ) . '</p>';
    }

    public static function render_tickets_section(): void {
        echo '<p>' . esc_html__( 'Configure default ticket settings.', 'oras-tickets' ) . '</p>';
    }

    public static function render_checkbox_field( array $args ): void {
        $settings = self::get_settings();
        $value = self::get_nested_value( $settings, $args['field'] );
        $name = self::OPTION_KEY . '[' . str_replace( '.', '][', $args['field'] ) . ']';
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $value ); ?> />
            <?php echo esc_html( $args['label'] ); ?>
        </label>
        <?php
    }

    public static function render_number_field( array $args ): void {
        $settings = self::get_settings();
        $value = self::get_nested_value( $settings, $args['field'] );
        $name = self::OPTION_KEY . '[' . str_replace( '.', '][', $args['field'] ) . ']';
        $min = $args['min'] ?? 0;
        ?>
        <input type="number" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $min ); ?>" />
        <?php
    }

    public static function render_select_field( array $args ): void {
        $settings = self::get_settings();
        $value = self::get_nested_value( $settings, $args['field'] );
        $name = self::OPTION_KEY . '[' . str_replace( '.', '][', $args['field'] ) . ']';
        ?>
        <select name="<?php echo esc_attr( $name ); ?>">
            <?php foreach ( $args['options'] as $option_value => $option_label ) : ?>
                <option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>>
                    <?php echo esc_html( $option_label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    private static function get_nested_value( array $data, string $path ) {
        $keys = explode( '.', $path );
        $value = $data;

        foreach ( $keys as $key ) {
            if ( ! isset( $value[ $key ] ) ) {
                return null;
            }
            $value = $value[ $key ];
        }

        return $value;
    }
}

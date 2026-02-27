<?php

namespace ORAS\Tickets\Admin\Pages;

if (! defined('ABSPATH')) {
    exit;
}

final class Settings_Page
{ // NOSONAR legacy WP class naming

    private const OPTION_KEY = 'oras_tickets_settings_v1';

    public function render(): void
    {
        if (! current_user_can('oras_tickets_manage_settings')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'oras-tickets'), '', array('response' => 403));
        }

        // Show repair capabilities button to site admins (manage_options) in case caps are missing
        $repair_notice = '';
        if (isset($_GET['oras_caps']) && $_GET['oras_caps'] === 'repaired') {
            $repair_notice = '<div class="updated notice is-dismissible"><p>' . esc_html__('Capabilities were repaired.', 'oras-tickets') . '</p></div>';
        }

        $qbo_notice = '';
        if ( isset( $_GET['oras_qbo_notice'] ) ) {
            $notice_text = urldecode( (string) wp_unslash( $_GET['oras_qbo_notice'] ) );
            $qbo_notice  = '<div class="updated notice is-dismissible"><p>' . esc_html( sanitize_text_field( $notice_text ) ) . '</p></div>';
        }

        $qbo_error = '';
        if ( isset( $_GET['oras_qbo_error'] ) ) {
            $error_text = urldecode( (string) wp_unslash( $_GET['oras_qbo_error'] ) );
            $qbo_error  = '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( $error_text ) ) . '</p></div>';
        }

?>
        <div class="wrap">
            <h1><?php echo esc_html__('ORAS Tickets Settings', 'oras-tickets'); ?></h1>
            <?php echo $repair_notice; // phpcs:ignore -- safe HTML output 
            ?>
            <?php echo $qbo_notice; // phpcs:ignore -- safe HTML output ?>
            <?php echo $qbo_error; // phpcs:ignore -- safe HTML output ?>

            <?php if (current_user_can('manage_options')) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1em;">
                    <?php wp_nonce_field('oras_repair_caps', 'oras_repair_caps_nonce'); ?>
                    <input type="hidden" name="action" value="oras_tickets_repair_caps" />
                    <button class="button button-secondary" type="submit" onclick="return confirm('<?php echo esc_js('Repairing capabilities will add permissions to the Administrator role. Continue?'); ?>');"><?php echo esc_html__('Repair Capabilities', 'oras-tickets'); ?></button>
                </form>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('oras_tickets_settings');
                do_settings_sections('oras_tickets_settings');
                submit_button();
                ?>
            </form>

            <?php $this->render_quickbooks_actions(); ?>
        </div>
    <?php
    }

    public static function register_settings(): void
    {
        register_setting(
            'oras_tickets_settings',
            self::OPTION_KEY,
            array(
                'type'              => 'array',
                'sanitize_callback' => array(self::class, 'sanitize_settings'),
                'default'           => self::get_default_settings(),
            )
        );

        add_settings_section(
            'oras_rsvp_defaults',
            __('RSVP Defaults', 'oras-tickets'),
            array(self::class, 'render_rsvp_section'),
            'oras_tickets_settings'
        );

        add_settings_field(
            'rsvp_default_enabled',
            __('Default Enabled', 'oras-tickets'),
            array(self::class, 'render_checkbox_field'),
            'oras_tickets_settings',
            'oras_rsvp_defaults',
            array(
                'field' => 'rsvp.default_enabled',
                'label' => __('Enable RSVP by default for new events', 'oras-tickets'),
            )
        );

        add_settings_field(
            'rsvp_default_capacity',
            __('Default Capacity', 'oras-tickets'),
            array(self::class, 'render_number_field'),
            'oras_tickets_settings',
            'oras_rsvp_defaults',
            array(
                'field' => 'rsvp.default_capacity',
                'label' => __('Default capacity (0 = unlimited)', 'oras-tickets'),
                'min'   => 0,
            )
        );

        add_settings_field(
            'rsvp_default_waitlist_enabled',
            __('Default Waitlist Enabled', 'oras-tickets'),
            array(self::class, 'render_checkbox_field'),
            'oras_tickets_settings',
            'oras_rsvp_defaults',
            array(
                'field' => 'rsvp.default_waitlist_enabled',
                'label' => __('Enable waitlist by default for new events', 'oras-tickets'),
            )
        );

        add_settings_section(
            'oras_virtual_access_defaults',
            __('Virtual Access Defaults', 'oras-tickets'),
            array(self::class, 'render_virtual_access_section'),
            'oras_tickets_settings'
        );

        add_settings_field(
            'virtual_access_default_show_to',
            __('Default Show To', 'oras-tickets'),
            array(self::class, 'render_select_field'),
            'oras_tickets_settings',
            'oras_virtual_access_defaults',
            array(
                'field'   => 'virtual_access.default_show_to',
                'options' => array(
                    'everyone'    => __('Everyone', 'oras-tickets'),
                    'logged_in'   => __('Logged In Users', 'oras-tickets'),
                    'rsvp'        => __('RSVP Attendees', 'oras-tickets'),
                    'ticket'      => __('Ticket Holders', 'oras-tickets'),
                    'free_ticket' => __('Free Ticket Holders', 'oras-tickets'),
                ),
            )
        );

        add_settings_section(
            'oras_tickets_defaults',
            __('Tickets Defaults', 'oras-tickets'),
            array(self::class, 'render_tickets_section'),
            'oras_tickets_settings'
        );

        add_settings_field(
            'tickets_auto_complete_ticket_only_orders',
            __('Auto-complete Ticket-only Orders', 'oras-tickets'),
            array(self::class, 'render_checkbox_field'),
            'oras_tickets_settings',
            'oras_tickets_defaults',
            array(
                'field' => 'tickets.auto_complete_ticket_only_orders',
                'label' => __('Automatically complete orders containing only tickets (no physical products)', 'oras-tickets'),
            )
        );

        add_settings_section(
            'oras_quickbooks_revenue_split',
            __('QuickBooks Revenue Split Sync', 'oras-tickets'),
            array(self::class, 'render_quickbooks_section'),
            'oras_tickets_settings'
        );

        add_settings_field(
            'quickbooks_enabled',
            __('Enable Sync', 'oras-tickets'),
            array(self::class, 'render_checkbox_field'),
            'oras_tickets_settings',
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.enabled',
                'label' => __('Enable QuickBooks JournalEntry revenue split for paid Woo orders', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_sandbox',
            __('Sandbox Mode', 'oras-tickets'),
            array(self::class, 'render_checkbox_field'),
            'oras_tickets_settings',
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.sandbox',
                'label' => __('Use QuickBooks Sandbox endpoints', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_client_id',
            __('Client ID', 'oras-tickets'),
            array(self::class, 'render_text_field'),
            'oras_tickets_settings',
            'oras_quickbooks_revenue_split',
            array(
                'field'       => 'quickbooks.client_id',
                'placeholder' => __('QuickBooks app client ID', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_client_secret',
            __('Client Secret', 'oras-tickets'),
            array(self::class, 'render_password_field'),
            'oras_tickets_settings',
            'oras_quickbooks_revenue_split',
            array(
                'field'       => 'quickbooks.client_secret',
                'placeholder' => __('QuickBooks app client secret', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_realm_id',
            __('Realm ID', 'oras-tickets'),
            array(self::class, 'render_text_field'),
            'oras_tickets_settings',
            'oras_quickbooks_revenue_split',
            array(
                'field'       => 'quickbooks.realm_id',
                'placeholder' => __('Populated after OAuth connection', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_clearing_account_id',
            __('Clearing Account', 'oras-tickets'),
            array(self::class, 'render_account_select_field'),
            'oras_tickets_settings',
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.clearing_account_id',
                'help'  => __('Debit account for split JournalEntry (offsets Stripe connector summary).', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_tickets_default_account_id',
            __('Default Ticket Income Account', 'oras-tickets'),
            array(self::class, 'render_account_select_field'),
            'oras_tickets_settings',
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.tickets_default_account_id',
            )
        );

        add_settings_field(
            'quickbooks_observer_account_id',
            __('Observer Pass Account', 'oras-tickets'),
            array(self::class, 'render_account_select_field'),
            'oras_tickets_settings',
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.observer_account_id',
            )
        );

        add_settings_field(
            'quickbooks_merchandise_account_id',
            __('Merchandise Account', 'oras-tickets'),
            array(self::class, 'render_account_select_field'),
            'oras_tickets_settings',
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.merchandise_account_id',
            )
        );

        add_settings_field(
            'quickbooks_unmapped_account_id',
            __('Fallback Unmapped Account', 'oras-tickets'),
            array(self::class, 'render_account_select_field'),
            'oras_tickets_settings',
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.unmapped_account_id',
            )
        );

        add_settings_field(
            'quickbooks_observer_category_slugs',
            __('Observer Category Slugs', 'oras-tickets'),
            array(self::class, 'render_text_field'),
            'oras_tickets_settings',
            'oras_quickbooks_revenue_split',
            array(
                'field'       => 'quickbooks.observer_category_slugs',
                'placeholder' => 'observer-pass,observer-passes',
            )
        );

        add_settings_field(
            'quickbooks_merch_category_slugs',
            __('Merch Category Slugs', 'oras-tickets'),
            array(self::class, 'render_text_field'),
            'oras_tickets_settings',
            'oras_quickbooks_revenue_split',
            array(
                'field'       => 'quickbooks.merch_category_slugs',
                'placeholder' => 'merch,merchandise,shirt,shirts,apparel',
            )
        );

        add_settings_field(
            'quickbooks_event_account_map',
            __('Per-Event Account Map', 'oras-tickets'),
            array(self::class, 'render_textarea_field'),
            'oras_tickets_settings',
            'oras_quickbooks_revenue_split',
            array(
                'field'       => 'quickbooks.event_account_map',
                'rows'        => 6,
                'placeholder' => "spring-stargaze=4001\nastroblast=4002",
                'help'        => __('Format: one mapping per line as event-slug=account-id', 'oras-tickets'),
            )
        );
    }

    public static function sanitize_settings($input): array
    {
        $current       = self::get_settings();
        $current_qbo   = isset( $current['quickbooks'] ) && is_array( $current['quickbooks'] ) ? $current['quickbooks'] : array();
        $defaults_qbo  = self::get_default_settings()['quickbooks'];
        $input_qbo     = isset( $input['quickbooks'] ) && is_array( $input['quickbooks'] ) ? $input['quickbooks'] : array();
        $client_secret = isset( $input_qbo['client_secret'] ) ? trim( (string) $input_qbo['client_secret'] ) : '';
        if ( $client_secret === '' ) {
            $client_secret = (string) ( $current_qbo['client_secret'] ?? '' );
        }

        $sanitized = array(
            'version'        => 1,
            'rsvp'           => array(
                'default_enabled'          => ! empty($input['rsvp']['default_enabled']),
                'default_capacity'         => absint($input['rsvp']['default_capacity'] ?? 0),
                'default_waitlist_enabled' => ! empty($input['rsvp']['default_waitlist_enabled']),
            ),
            'virtual_access' => array(
                'default_show_to' => self::sanitize_show_to($input['virtual_access']['default_show_to'] ?? ''),
            ),
            'tickets'        => array(
                'auto_complete_ticket_only_orders' => ! empty($input['tickets']['auto_complete_ticket_only_orders']),
            ),
            'quickbooks'     => array(
                'enabled'                    => ! empty( $input_qbo['enabled'] ),
                'sandbox'                    => ! empty( $input_qbo['sandbox'] ),
                'client_id'                  => sanitize_text_field( (string) ( $input_qbo['client_id'] ?? '' ) ),
                'client_secret'              => sanitize_text_field( $client_secret ),
                'realm_id'                   => sanitize_text_field( (string) ( $input_qbo['realm_id'] ?? '' ) ),
                'access_token'               => (string) ( $current_qbo['access_token'] ?? '' ),
                'refresh_token'              => (string) ( $current_qbo['refresh_token'] ?? '' ),
                'token_expires_at'           => (string) ( $current_qbo['token_expires_at'] ?? '' ),
                'refresh_token_expires_at'   => (string) ( $current_qbo['refresh_token_expires_at'] ?? '' ),
                'connected_at'               => (string) ( $current_qbo['connected_at'] ?? '' ),
                'clearing_account_id'        => sanitize_text_field( (string) ( $input_qbo['clearing_account_id'] ?? '' ) ),
                'tickets_default_account_id' => sanitize_text_field( (string) ( $input_qbo['tickets_default_account_id'] ?? '' ) ),
                'observer_account_id'        => sanitize_text_field( (string) ( $input_qbo['observer_account_id'] ?? '' ) ),
                'merchandise_account_id'     => sanitize_text_field( (string) ( $input_qbo['merchandise_account_id'] ?? '' ) ),
                'unmapped_account_id'        => sanitize_text_field( (string) ( $input_qbo['unmapped_account_id'] ?? '' ) ),
                'discount_mode'              => 'proportional',
                'observer_category_slugs'    => sanitize_text_field( (string) ( $input_qbo['observer_category_slugs'] ?? $defaults_qbo['observer_category_slugs'] ) ),
                'merch_category_slugs'       => sanitize_text_field( (string) ( $input_qbo['merch_category_slugs'] ?? $defaults_qbo['merch_category_slugs'] ) ),
                'event_account_map'          => sanitize_textarea_field( (string) ( $input_qbo['event_account_map'] ?? '' ) ),
                'account_cache'              => self::sanitize_account_cache( $current_qbo['account_cache'] ?? array() ),
                'last_error'                 => (string) ( $current_qbo['last_error'] ?? '' ),
            ),
        );

        return $sanitized;
    }

    private static function sanitize_show_to(string $value): string
    {
        $allowed = array('everyone', 'logged_in', 'rsvp', 'ticket', 'free_ticket');
        $sanitized = sanitize_key($value);
        return in_array($sanitized, $allowed, true) ? $sanitized : 'logged_in';
    }

    public static function get_default_settings(): array
    {
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
            'quickbooks'     => array(
                'enabled'                    => false,
                'sandbox'                    => true,
                'client_id'                  => '',
                'client_secret'              => '',
                'realm_id'                   => '',
                'access_token'               => '',
                'refresh_token'              => '',
                'token_expires_at'           => '',
                'refresh_token_expires_at'   => '',
                'connected_at'               => '',
                'clearing_account_id'        => '',
                'tickets_default_account_id' => '',
                'observer_account_id'        => '',
                'merchandise_account_id'     => '',
                'unmapped_account_id'        => '',
                'discount_mode'              => 'proportional',
                'observer_category_slugs'    => 'observer-pass,observer-passes',
                'merch_category_slugs'       => 'merch,merchandise,shirt,shirts,apparel',
                'event_account_map'          => '',
                'account_cache'              => array(),
                'last_error'                 => '',
            ),
        );
    }

    public static function get_settings(): array
    {
        $settings = get_option( self::OPTION_KEY, self::get_default_settings() );
        if ( ! is_array( $settings ) ) {
            $settings = self::get_default_settings();
        }

        return wp_parse_args( $settings, self::get_default_settings() );
    }

    public static function render_rsvp_section(): void
    {
        echo '<p>' . esc_html__('Configure default RSVP settings for new events.', 'oras-tickets') . '</p>';
    }

    public static function render_virtual_access_section(): void
    {
        echo '<p>' . esc_html__('Configure default virtual access settings for new events.', 'oras-tickets') . '</p>';
    }

    public static function render_tickets_section(): void
    {
        echo '<p>' . esc_html__('Configure default ticket settings.', 'oras-tickets') . '</p>';
    }

    public static function render_quickbooks_section(): void
    {
        echo '<p>' . esc_html__( 'Create one QuickBooks JournalEntry per paid Woo order, debiting a clearing account and crediting event/category income accounts.', 'oras-tickets' ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Discount handling:', 'oras-tickets' ) . '</strong> ' . esc_html__( 'Proportional allocation (line net totals) is used by this release.', 'oras-tickets' ) . '</p>';
    }

    public static function render_checkbox_field(array $args): void
    {
        $settings = self::get_settings();
        $value = self::get_nested_value($settings, $args['field']);
        $name = self::OPTION_KEY . '[' . str_replace('.', '][', $args['field']) . ']';
    ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($value); ?> />
            <?php echo esc_html($args['label']); ?>
        </label>
    <?php
    }

    public static function render_number_field(array $args): void
    {
        $settings = self::get_settings();
        $value = self::get_nested_value($settings, $args['field']);
        $name = self::OPTION_KEY . '[' . str_replace('.', '][', $args['field']) . ']';
        $min = $args['min'] ?? 0;
    ?>
        <input type="number" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" min="<?php echo esc_attr($min); ?>" />
    <?php
    }

    public static function render_select_field(array $args): void
    {
        $settings = self::get_settings();
        $value = self::get_nested_value($settings, $args['field']);
        $name = self::OPTION_KEY . '[' . str_replace('.', '][', $args['field']) . ']';
    ?>
        <select name="<?php echo esc_attr($name); ?>">
            <?php foreach ($args['options'] as $option_value => $option_label) : ?>
                <option value="<?php echo esc_attr($option_value); ?>" <?php selected($value, $option_value); ?>>
                    <?php echo esc_html($option_label); ?>
                </option>
            <?php endforeach; ?>
        </select>
<?php
    }

    public static function render_text_field( array $args ): void
    {
        $settings    = self::get_settings();
        $value       = self::get_nested_value( $settings, $args['field'] );
        $name        = self::OPTION_KEY . '[' . str_replace( '.', '][', $args['field'] ) . ']';
        $placeholder = isset( $args['placeholder'] ) ? (string) $args['placeholder'] : '';
        $help        = isset( $args['help'] ) ? (string) $args['help'] : '';
?>
        <input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
        <?php if ( $help !== '' ) : ?>
            <p class="description"><?php echo esc_html( $help ); ?></p>
        <?php endif; ?>
<?php
    }

    public static function render_password_field( array $args ): void
    {
        $settings    = self::get_settings();
        $value       = self::get_nested_value( $settings, $args['field'] );
        $name        = self::OPTION_KEY . '[' . str_replace( '.', '][', $args['field'] ) . ']';
        $placeholder = isset( $args['placeholder'] ) ? (string) $args['placeholder'] : '';
?>
        <input type="password" class="regular-text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="off" />
<?php
    }

    public static function render_textarea_field( array $args ): void
    {
        $settings    = self::get_settings();
        $value       = self::get_nested_value( $settings, $args['field'] );
        $name        = self::OPTION_KEY . '[' . str_replace( '.', '][', $args['field'] ) . ']';
        $rows        = isset( $args['rows'] ) ? absint( $args['rows'] ) : 4;
        $placeholder = isset( $args['placeholder'] ) ? (string) $args['placeholder'] : '';
        $help        = isset( $args['help'] ) ? (string) $args['help'] : '';
?>
        <textarea class="large-text code" rows="<?php echo esc_attr( max( 2, $rows ) ); ?>" name="<?php echo esc_attr( $name ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"><?php echo esc_textarea( (string) $value ); ?></textarea>
        <?php if ( $help !== '' ) : ?>
            <p class="description"><?php echo esc_html( $help ); ?></p>
        <?php endif; ?>
<?php
    }

    public static function render_account_select_field( array $args ): void
    {
        $settings = self::get_settings();
        $value    = (string) self::get_nested_value( $settings, $args['field'] );
        $name     = self::OPTION_KEY . '[' . str_replace( '.', '][', $args['field'] ) . ']';
        $help     = isset( $args['help'] ) ? (string) $args['help'] : '';
        $accounts = self::get_quickbooks_account_options();
?>
        <select name="<?php echo esc_attr( $name ); ?>">
            <option value=""><?php echo esc_html__( 'Select account (or leave blank)', 'oras-tickets' ); ?></option>
            <?php foreach ( $accounts as $account_id => $account_label ) : ?>
                <option value="<?php echo esc_attr( $account_id ); ?>" <?php selected( $value, $account_id ); ?>>
                    <?php echo esc_html( $account_label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ( $help !== '' ) : ?>
            <p class="description"><?php echo esc_html( $help ); ?></p>
        <?php endif; ?>
<?php
    }

    private function render_quickbooks_actions(): void
    {
?>
        <hr />
        <h2><?php echo esc_html__( 'QuickBooks Actions', 'oras-tickets' ); ?></h2>
        <p><?php echo esc_html__( 'Use these actions to authorize QuickBooks and verify API connectivity.', 'oras-tickets' ); ?></p>

        <div style="display:flex; gap:12px; flex-wrap:wrap; margin:12px 0 24px;">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'oras_tickets_qbo_oauth_start' ); ?>
                <input type="hidden" name="action" value="oras_tickets_qbo_oauth_start" />
                <button type="submit" class="button button-primary"><?php echo esc_html__( 'Connect / Reconnect QuickBooks', 'oras-tickets' ); ?></button>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'oras_tickets_qbo_test_connection' ); ?>
                <input type="hidden" name="action" value="oras_tickets_qbo_test_connection" />
                <button type="submit" class="button"><?php echo esc_html__( 'Test Connection + Refresh Accounts', 'oras-tickets' ); ?></button>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'oras_tickets_qbo_test_journal_entry' ); ?>
                <input type="hidden" name="action" value="oras_tickets_qbo_test_journal_entry" />
                <button type="submit" class="button"><?php echo esc_html__( 'Test JournalEntry', 'oras-tickets' ); ?></button>
            </form>
        </div>
<?php
    }

    /**
     * @return array<string,string>
     */
    private static function get_quickbooks_account_options(): array
    {
        $settings = self::get_settings();
        $cache    = isset( $settings['quickbooks']['account_cache'] ) && is_array( $settings['quickbooks']['account_cache'] )
            ? $settings['quickbooks']['account_cache']
            : array();

        $options = array();
        foreach ( $cache as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $id    = isset( $row['id'] ) ? sanitize_text_field( (string) $row['id'] ) : '';
            $label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : $id;
            $type  = isset( $row['type'] ) ? sanitize_text_field( (string) $row['type'] ) : '';
            if ( $id === '' ) {
                continue;
            }
            $options[ $id ] = $type !== '' ? sprintf( '%1$s (%2$s)', $label, $type ) : $label;
        }

        return $options;
    }

    private static function get_nested_value(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (! isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param mixed $cache
     * @return array<int,array<string,string>>
     */
    private static function sanitize_account_cache( $cache ): array
    {
        if ( ! is_array( $cache ) ) {
            return array();
        }

        $sanitized = array();
        foreach ( $cache as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $id = isset( $row['id'] ) ? sanitize_text_field( (string) $row['id'] ) : '';
            if ( $id === '' ) {
                continue;
            }
            $sanitized[] = array(
                'id'    => $id,
                'label' => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : $id,
                'type'  => isset( $row['type'] ) ? sanitize_text_field( (string) $row['type'] ) : '',
            );
        }

        return $sanitized;
    }
}

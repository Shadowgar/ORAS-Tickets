<?php

namespace ORAS\Tickets\Admin\Pages;

if (! defined('ABSPATH')) {
    exit;
}

final class Settings_Page
{ // NOSONAR legacy WP class naming

    private const OPTION_KEY = 'oras_tickets_settings_v1';
    private const PAGE_GENERAL = 'oras_tickets_settings';
    private const PAGE_QUICKBOOKS = 'oras_tickets_quickbooks';

    public function render(): void
    {
        $this->render_general();
    }

    public function render_general(): void
    {
        if (! current_user_can('oras_tickets_manage_settings')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'oras-tickets'), '', array('response' => 403));
        }

        // Show repair capabilities button to site admins (manage_options) in case caps are missing
        $repair_notice = '';
        if (isset($_GET['oras_caps']) && $_GET['oras_caps'] === 'repaired') {
            $repair_notice = '<div class="updated notice is-dismissible"><p>' . esc_html__('Capabilities were repaired.', 'oras-tickets') . '</p></div>';
        }

?>
        <div class="wrap">
            <h1><?php echo esc_html__('ORAS Tickets Settings', 'oras-tickets'); ?></h1>
            <?php echo $repair_notice; // phpcs:ignore -- safe HTML output 
            ?>

            <?php if (current_user_can('manage_options')) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1em;">
                    <?php wp_nonce_field('oras_repair_caps', 'oras_repair_caps_nonce'); ?>
                    <input type="hidden" name="action" value="oras_tickets_repair_caps" />
                    <button class="button button-secondary" type="submit" onclick="return confirm('<?php echo esc_js('Repairing capabilities will add permissions to the Administrator role. Continue?'); ?>');"><?php echo esc_html__('Repair Capabilities', 'oras-tickets'); ?></button>
                </form>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::PAGE_GENERAL);
                do_settings_sections(self::PAGE_GENERAL);
                submit_button();
                ?>
            </form>
        </div>
    <?php
    }

    public function render_quickbooks(): void
    {
        if (! current_user_can('oras_tickets_manage_settings')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'oras-tickets'), '', array('response' => 403));
        }

        $settings     = self::get_settings();
        $qbo_settings = isset($settings['quickbooks']) && is_array($settings['quickbooks'])
            ? $settings['quickbooks']
            : array();

        $qbo_notice = '';
        if (isset($_GET['oras_qbo_notice'])) {
            $notice_text = urldecode((string) wp_unslash($_GET['oras_qbo_notice']));
            $qbo_notice  = '<div class="updated notice is-dismissible"><p>' . esc_html(sanitize_text_field($notice_text)) . '</p></div>';
        }

        $qbo_error = '';
        if (isset($_GET['oras_qbo_error'])) {
            $error_text = urldecode((string) wp_unslash($_GET['oras_qbo_error']));
            $qbo_error  = '<div class="notice notice-error is-dismissible"><p>' . esc_html(sanitize_text_field($error_text)) . '</p></div>';
        }
    ?>
        <div class="wrap">
            <h1><?php echo esc_html__('ORAS Tickets QuickBooks', 'oras-tickets'); ?></h1>
            <style>
                .oras-qbo-connection-card {
                    display: block;
                    border-left: 4px solid #7ad03a;
                    background: #fff;
                    padding: 14px 16px;
                    margin: 12px 0;
                    border: 1px solid #e5e5e5;
                    border-radius: 4px;
                    box-shadow: 0 1px 0 rgba(0, 0, 0, 0.02)
                }

                .oras-qbo-connection-card.warning {
                    border-left-color: #ffb900
                }

                .oras-qbo-connection-card h3 {
                    margin: 0 0 6px;
                    font-size: 16px
                }

                .oras-qbo-connection-meta {
                    font-size: 13px;
                    color: #333
                }

                .oras-qbo-actions {
                    display: flex;
                    gap: 12px;
                    flex-wrap: wrap;
                    margin: 12px 0 24px
                }

                .oras-qbo-actions .button {
                    min-width: 200px
                }

                @media (min-width:780px) {
                    .oras-qbo-columns {
                        display: grid;
                        grid-template-columns: 1fr 360px;
                        gap: 24px;
                        align-items: start
                    }
                }

                .oras-qbo-sidebox {
                    background: #fafafa;
                    border: 1px solid #eaeaea;
                    padding: 12px;
                    border-radius: 4px
                }
            </style>
            <?php $this->render_quickbooks_connection_indicator($qbo_settings); ?>
            <?php echo $qbo_notice; // phpcs:ignore -- safe HTML output 
            ?>
            <?php echo $qbo_error; // phpcs:ignore -- safe HTML output 
            ?>

            <?php $active_tab = $this->get_active_quickbooks_tab(); ?>
            <h2 class="nav-tab-wrapper" style="margin-top:20px;">
                <a href="<?php echo esc_url($this->build_quickbooks_tab_url('settings')); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html__('Settings', 'oras-tickets'); ?>
                </a>
                <a href="<?php echo esc_url($this->build_quickbooks_tab_url('pending')); ?>" class="nav-tab <?php echo $active_tab === 'pending' ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html__('Pending Orders', 'oras-tickets'); ?>
                </a>
                <a href="<?php echo esc_url($this->build_quickbooks_tab_url('history')); ?>" class="nav-tab <?php echo $active_tab === 'history' ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html__('Sync History', 'oras-tickets'); ?>
                </a>
            </h2>

            <?php if ($active_tab === 'pending') : ?>
                <?php $this->render_quickbooks_pending_orders_tab(); ?>
            <?php elseif ($active_tab === 'history') : ?>
                <?php $this->render_quickbooks_sync_history_tab(); ?>
            <?php else : ?>
                <div class="oras-qbo-columns">
                    <div>
                        <form method="post" action="options.php">
                            <?php
                            settings_fields(self::PAGE_GENERAL);
                            do_settings_sections(self::PAGE_QUICKBOOKS);
                            submit_button(__('Save QuickBooks Settings', 'oras-tickets'));
                            ?>
                        </form>
                    </div>
                    <div class="oras-qbo-sidebox">
                        <?php $this->render_quickbooks_actions(); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php
    }

    private function get_active_quickbooks_tab(): string
    {
        $tab = isset($_GET['oras_qbo_tab']) ? sanitize_key((string) wp_unslash($_GET['oras_qbo_tab'])) : 'settings';
        return in_array($tab, array('settings', 'pending', 'history'), true) ? $tab : 'settings';
    }

    private function build_quickbooks_tab_url(string $tab): string
    {
        return add_query_arg(
            array(
                'page'         => 'oras-tickets-quickbooks',
                'oras_qbo_tab' => $tab,
            ),
            admin_url('admin.php')
        );
    }

    /**
     * Pending statuses that require operator visibility/action.
     *
     * @return string[]
     */
    private function get_quickbooks_pending_statuses(): array
    {
        return array(
            'pending_qbo_review',
            'approved_for_sync',
            'queued',
            'retrying',
            'failed',
            'migration_required',
            'changed_after_sync',
            'waiting_for_source_txn',
            'needs_review',
        );
    }

    private function render_quickbooks_pending_orders_tab(): void
    {
        if (! function_exists('wc_get_orders')) {
            echo '<p>' . esc_html__('WooCommerce order querying is not available.', 'oras-tickets') . '</p>';
            return;
        }

        $statuses = $this->get_quickbooks_pending_statuses();
        $status_filter = isset($_GET['oras_qbo_pending_status']) ? sanitize_key((string) wp_unslash($_GET['oras_qbo_pending_status'])) : 'all';
        if ($status_filter !== 'all' && ! in_array($status_filter, $statuses, true)) {
            $status_filter = 'all';
        }

        $meta_query = array(
            array(
                'key'     => '_oras_qbo_sync_status',
                'value'   => $status_filter === 'all' ? $statuses : array($status_filter),
                'compare' => 'IN',
            ),
        );

        $order_ids = wc_get_orders(
            array(
                'type'       => 'shop_order',
                'limit'      => 100,
                'return'     => 'ids',
                'orderby'    => 'date',
                'order'      => 'DESC',
                'meta_query' => $meta_query,
            )
        );

        $base_url = add_query_arg(
            array(
                'page'         => 'oras-tickets-quickbooks',
                'oras_qbo_tab' => 'pending',
            ),
            admin_url('admin.php')
        );

    ?>
        <div style="margin-top:16px;">
            <h2><?php echo esc_html__('Pending / Waiting Orders', 'oras-tickets'); ?></h2>
            <p class="description">
                <?php echo esc_html__('These orders are waiting for approval, source transaction matching, retry, or operator review. Use row actions to sync, approve+sync, resync, or reverse.', 'oras-tickets'); ?>
            </p>

            <form method="get" style="display:flex; gap:8px; align-items:center; margin:12px 0 16px;">
                <input type="hidden" name="page" value="oras-tickets-quickbooks" />
                <input type="hidden" name="oras_qbo_tab" value="pending" />
                <label for="oras-qbo-pending-status"><?php echo esc_html__('Filter', 'oras-tickets'); ?></label>
                <select id="oras-qbo-pending-status" name="oras_qbo_pending_status">
                    <option value="all" <?php selected($status_filter, 'all'); ?>><?php echo esc_html__('All pending statuses', 'oras-tickets'); ?></option>
                    <?php foreach ($statuses as $status) : ?>
                        <option value="<?php echo esc_attr($status); ?>" <?php selected($status_filter, $status); ?>>
                            <?php echo esc_html(ucwords(str_replace('_', ' ', $status))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php echo esc_html__('Apply', 'oras-tickets'); ?></button>
            </form>

            <div style="display:flex; gap:12px; flex-wrap:wrap; margin:0 0 20px;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <?php wp_nonce_field('oras_tickets_qbo_process_waiting_queue'); ?>
                    <input type="hidden" name="action" value="oras_tickets_qbo_process_waiting_queue" />
                    <input type="hidden" name="oras_qbo_tab" value="pending" />
                    <label for="oras-qbo-process-limit"><?php echo esc_html__('Run waiting queue', 'oras-tickets'); ?></label>
                    <input type="number" min="1" max="250" id="oras-qbo-process-limit" name="limit" value="50" />
                    <button type="submit" class="button button-secondary"><?php echo esc_html__('Process Waiting Orders', 'oras-tickets'); ?></button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <?php wp_nonce_field('oras_tickets_qbo_sync_order_now'); ?>
                    <input type="hidden" name="action" value="oras_tickets_qbo_sync_order_now" />
                    <input type="hidden" name="oras_qbo_tab" value="pending" />
                    <label for="oras-qbo-sync-order-id" class="screen-reader-text"><?php echo esc_html__('Sync Order ID', 'oras-tickets'); ?></label>
                    <input type="number" min="1" required id="oras-qbo-sync-order-id" name="order_id" placeholder="<?php echo esc_attr__('Order ID', 'oras-tickets'); ?>" />
                    <button type="submit" class="button"><?php echo esc_html__('Sync Order Now', 'oras-tickets'); ?></button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <?php wp_nonce_field('oras_tickets_qbo_approve_order'); ?>
                    <input type="hidden" name="action" value="oras_tickets_qbo_approve_order" />
                    <input type="hidden" name="oras_qbo_tab" value="pending" />
                    <label for="oras-qbo-approve-order-id-tab" class="screen-reader-text"><?php echo esc_html__('Approve Order ID', 'oras-tickets'); ?></label>
                    <input type="number" min="1" required id="oras-qbo-approve-order-id-tab" name="order_id" placeholder="<?php echo esc_attr__('Order ID', 'oras-tickets'); ?>" />
                    <input type="hidden" name="sync_now" value="1" />
                    <button type="submit" class="button"><?php echo esc_html__('Approve + Sync', 'oras-tickets'); ?></button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <?php wp_nonce_field('oras_tickets_qbo_resync_order'); ?>
                    <input type="hidden" name="action" value="oras_tickets_qbo_resync_order" />
                    <input type="hidden" name="oras_qbo_tab" value="pending" />
                    <label for="oras-qbo-resync-order-id-tab" class="screen-reader-text"><?php echo esc_html__('Resync Order ID', 'oras-tickets'); ?></label>
                    <input type="number" min="1" required id="oras-qbo-resync-order-id-tab" name="order_id" placeholder="<?php echo esc_attr__('Order ID', 'oras-tickets'); ?>" />
                    <button type="submit" class="button button-primary"><?php echo esc_html__('Resync Order (Reset + Sync)', 'oras-tickets'); ?></button>
                </form>
            </div>

            <?php if (empty($order_ids)) : ?>
                <p><?php echo esc_html__('No pending orders found for the current filter.', 'oras-tickets'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Order', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Created', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Total', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Sync Status', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Wait Attempts', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Next Check', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Last Attempt', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Last Error', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Actions', 'oras-tickets'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order_ids as $order_id) : ?>
                            <?php
                            $order = wc_get_order(absint($order_id));
                            if (! $order) {
                                continue;
                            }

                            $sync_status = (string) $order->get_meta('_oras_qbo_sync_status', true);
                            $wait_attempts = (string) $order->get_meta('_oras_qbo_wait_attempts', true);
                            $next_check = (string) $order->get_meta('_oras_qbo_wait_next_check_at', true);
                            $last_attempt = (string) $order->get_meta('_oras_qbo_last_attempt_at', true);
                            $last_error = (string) $order->get_meta('_oras_qbo_sync_error', true);
                            $order_link = get_edit_post_link((int) $order->get_id(), '');
                            ?>
                            <tr>
                                <td>
                                    <?php if ($order_link) : ?>
                                        <a href="<?php echo esc_url($order_link); ?>">#<?php echo esc_html((string) $order->get_order_number()); ?></a>
                                    <?php else : ?>
                                        #<?php echo esc_html((string) $order->get_order_number()); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($order->get_date_created() ? (string) $order->get_date_created()->date_i18n('Y-m-d H:i') : '—'); ?></td>
                                <td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                                <td><?php echo esc_html(ucwords(str_replace('_', ' ', $sync_status))); ?></td>
                                <td><?php echo esc_html($wait_attempts !== '' ? $wait_attempts : '0'); ?></td>
                                <td><?php echo esc_html($next_check !== '' ? $next_check . ' UTC' : '—'); ?></td>
                                <td><?php echo esc_html($last_attempt !== '' ? $last_attempt . ' UTC' : '—'); ?></td>
                                <td style="max-width:360px;"><?php echo esc_html($last_error !== '' ? $last_error : '—'); ?></td>
                                <td>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <?php wp_nonce_field('oras_tickets_qbo_sync_order_now'); ?>
                                            <input type="hidden" name="action" value="oras_tickets_qbo_sync_order_now" />
                                            <input type="hidden" name="oras_qbo_tab" value="pending" />
                                            <input type="hidden" name="order_id" value="<?php echo esc_attr((string) $order->get_id()); ?>" />
                                            <button type="submit" class="button button-small"><?php echo esc_html__('Sync', 'oras-tickets'); ?></button>
                                        </form>

                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <?php wp_nonce_field('oras_tickets_qbo_approve_order'); ?>
                                            <input type="hidden" name="action" value="oras_tickets_qbo_approve_order" />
                                            <input type="hidden" name="oras_qbo_tab" value="pending" />
                                            <input type="hidden" name="order_id" value="<?php echo esc_attr((string) $order->get_id()); ?>" />
                                            <input type="hidden" name="sync_now" value="1" />
                                            <button type="submit" class="button button-small"><?php echo esc_html__('Approve + Sync', 'oras-tickets'); ?></button>
                                        </form>

                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <?php wp_nonce_field('oras_tickets_qbo_resync_order'); ?>
                                            <input type="hidden" name="action" value="oras_tickets_qbo_resync_order" />
                                            <input type="hidden" name="oras_qbo_tab" value="pending" />
                                            <input type="hidden" name="order_id" value="<?php echo esc_attr((string) $order->get_id()); ?>" />
                                            <button type="submit" class="button button-small"><?php echo esc_html__('Resync', 'oras-tickets'); ?></button>
                                        </form>

                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <?php wp_nonce_field('oras_tickets_qbo_reverse_order'); ?>
                                            <input type="hidden" name="action" value="oras_tickets_qbo_reverse_order" />
                                            <input type="hidden" name="oras_qbo_tab" value="pending" />
                                            <input type="hidden" name="order_id" value="<?php echo esc_attr((string) $order->get_id()); ?>" />
                                            <button type="submit" class="button button-small"><?php echo esc_html__('Reverse', 'oras-tickets'); ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description" style="margin-top:8px;">
                    <?php echo esc_html__('Showing up to 100 most recent pending orders.', 'oras-tickets'); ?>
                </p>
            <?php endif; ?>
            <p><a class="button" href="<?php echo esc_url($base_url); ?>"><?php echo esc_html__('Refresh List', 'oras-tickets'); ?></a></p>
        </div>
    <?php
    }

    private function render_quickbooks_sync_history_tab(): void
    {
        if (! function_exists('wc_get_orders')) {
            echo '<p>' . esc_html__('WooCommerce order querying is not available.', 'oras-tickets') . '</p>';
            return;
        }

        $order_ids = wc_get_orders(
            array(
                'type'       => 'shop_order',
                'limit'      => 200,
                'return'     => 'ids',
                'orderby'    => 'date',
                'order'      => 'DESC',
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_oras_qbo_je_id',
                        'compare' => 'EXISTS',
                    ),
                    array(
                        'key'     => '_oras_qbo_reversal_je_id',
                        'compare' => 'EXISTS',
                    ),
                    array(
                        'key'     => '_oras_qbo_synced_at',
                        'compare' => 'EXISTS',
                    ),
                ),
            )
        );

        $base_url = add_query_arg(
            array(
                'page'         => 'oras-tickets-quickbooks',
                'oras_qbo_tab' => 'history',
            ),
            admin_url('admin.php')
        );

    ?>
        <div style="margin-top:16px;">
            <h2><?php echo esc_html__('Sync History', 'oras-tickets'); ?></h2>
            <p class="description">
                <?php echo esc_html__('Running log of orders that have ORAS QuickBooks sync history. Use Reverse to create reversal entries directly from this history table.', 'oras-tickets'); ?>
            </p>

            <div style="display:flex; gap:12px; flex-wrap:wrap; margin:0 0 20px;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <?php wp_nonce_field('oras_tickets_qbo_reverse_order'); ?>
                    <input type="hidden" name="action" value="oras_tickets_qbo_reverse_order" />
                    <input type="hidden" name="oras_qbo_tab" value="history" />
                    <label for="oras-qbo-history-reverse-order-id"><?php echo esc_html__('Reverse Order ID', 'oras-tickets'); ?></label>
                    <input type="number" min="1" required id="oras-qbo-history-reverse-order-id" name="order_id" placeholder="<?php echo esc_attr__('Order ID', 'oras-tickets'); ?>" />
                    <label>
                        <input type="checkbox" name="force_reversal" value="1" />
                        <?php echo esc_html__('Force', 'oras-tickets'); ?>
                    </label>
                    <button type="submit" class="button"><?php echo esc_html__('Reverse Order JE', 'oras-tickets'); ?></button>
                </form>
            </div>

            <?php if (empty($order_ids)) : ?>
                <p><?php echo esc_html__('No sync history found yet.', 'oras-tickets'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Order', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Created', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Synced At', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Status', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Doc Number', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Source Txn', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('JE ID', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Reversal JE', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Processor Fee', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Last Event', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Actions', 'oras-tickets'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order_ids as $order_id) : ?>
                            <?php
                            $order = wc_get_order(absint($order_id));
                            if (! $order) {
                                continue;
                            }

                            $sync_status = (string) $order->get_meta('_oras_qbo_sync_status', true);
                            $synced_at = (string) $order->get_meta('_oras_qbo_synced_at', true);
                            $doc_number = (string) $order->get_meta('_oras_qbo_doc_number', true);
                            $je_id = (string) $order->get_meta('_oras_qbo_je_id', true);
                            $reversal_je = (string) $order->get_meta('_oras_qbo_reversal_je_id', true);
                            $source_txn_type = (string) $order->get_meta('_oras_qbo_reclass_source_txn_type', true);
                            $source_txn_id = (string) $order->get_meta('_oras_qbo_reclass_source_txn_id', true);
                            $source_txn_date = (string) $order->get_meta('_oras_qbo_reclass_source_txn_date', true);
                            $source_txn_key = (string) $order->get_meta('_oras_qbo_reclass_source_txn_key', true);
                            $last_event = (string) $order->get_meta('_oras_qbo_last_audit_event', true);
                            $order_link = get_edit_post_link((int) $order->get_id(), '');

                            $source_txn_parts = array();
                            if ($source_txn_type !== '' || $source_txn_id !== '') {
                                $source_txn_parts[] = trim($source_txn_type . ($source_txn_id !== '' ? ':' . $source_txn_id : ''));
                            } elseif ($source_txn_key !== '') {
                                $source_txn_parts[] = $source_txn_key;
                            }
                            if ($source_txn_date !== '') {
                                $source_txn_parts[] = $source_txn_date;
                            }
                            $source_txn_display = ! empty($source_txn_parts) ? implode(' | ', $source_txn_parts) : '—';

                            $fee_note = $source_txn_key !== ''
                                ? __('Stripe fee expected in Stripe connector Expense/Bank Fee entry; JE intentionally excludes processor fees.', 'oras-tickets')
                                : __('No matched reclass source recorded yet; confirm Stripe fee entry exists in QBO feed.', 'oras-tickets');
                            ?>
                            <tr>
                                <td>
                                    <?php if ($order_link) : ?>
                                        <a href="<?php echo esc_url($order_link); ?>">#<?php echo esc_html((string) $order->get_order_number()); ?></a>
                                    <?php else : ?>
                                        #<?php echo esc_html((string) $order->get_order_number()); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($order->get_date_created() ? (string) $order->get_date_created()->date_i18n('Y-m-d H:i') : '—'); ?></td>
                                <td><?php echo esc_html($synced_at !== '' ? $synced_at . ' UTC' : '—'); ?></td>
                                <td><?php echo esc_html($sync_status !== '' ? ucwords(str_replace('_', ' ', $sync_status)) : '—'); ?></td>
                                <td><?php echo esc_html($doc_number !== '' ? $doc_number : '—'); ?></td>
                                <td style="max-width:260px;"><?php echo esc_html($source_txn_display); ?></td>
                                <td><?php echo esc_html($je_id !== '' ? $je_id : '—'); ?></td>
                                <td><?php echo esc_html($reversal_je !== '' ? $reversal_je : '—'); ?></td>
                                <td style="max-width:360px;"><?php echo esc_html($fee_note); ?></td>
                                <td><?php echo esc_html($last_event !== '' ? str_replace('_', ' ', $last_event) : '—'); ?></td>
                                <td>
                                    <?php if ($je_id !== '') : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-flex; gap:6px; align-items:center;">
                                            <?php wp_nonce_field('oras_tickets_qbo_reverse_order'); ?>
                                            <input type="hidden" name="action" value="oras_tickets_qbo_reverse_order" />
                                            <input type="hidden" name="oras_qbo_tab" value="history" />
                                            <input type="hidden" name="order_id" value="<?php echo esc_attr((string) $order->get_id()); ?>" />
                                            <button type="submit" class="button button-small" <?php disabled($reversal_je !== ''); ?>><?php echo esc_html__('Reverse', 'oras-tickets'); ?></button>
                                        </form>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description" style="margin-top:8px;">
                    <?php echo esc_html__('Showing up to 200 recent orders with QuickBooks sync history.', 'oras-tickets'); ?>
                </p>
            <?php endif; ?>
            <p><a class="button" href="<?php echo esc_url($base_url); ?>"><?php echo esc_html__('Refresh History', 'oras-tickets'); ?></a></p>
        </div>
    <?php
    }

    public static function register_settings(): void
    {
        register_setting(
            self::PAGE_GENERAL,
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
            self::PAGE_GENERAL
        );

        add_settings_field(
            'rsvp_default_enabled',
            __('Default Enabled', 'oras-tickets'),
            array(self::class, 'render_checkbox_field'),
            self::PAGE_GENERAL,
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
            self::PAGE_GENERAL,
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
            self::PAGE_GENERAL,
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
            self::PAGE_GENERAL
        );

        add_settings_field(
            'virtual_access_default_show_to',
            __('Default Show To', 'oras-tickets'),
            array(self::class, 'render_select_field'),
            self::PAGE_GENERAL,
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
            self::PAGE_GENERAL
        );

        add_settings_field(
            'tickets_auto_complete_ticket_only_orders',
            __('Auto-complete Ticket-only Orders', 'oras-tickets'),
            array(self::class, 'render_checkbox_field'),
            self::PAGE_GENERAL,
            'oras_tickets_defaults',
            array(
                'field' => 'tickets.auto_complete_ticket_only_orders',
                'label' => __('Automatically complete orders containing only tickets (no physical products)', 'oras-tickets'),
            )
        );

        add_settings_field(
            'tickets_cart_hold_minutes',
            __('Cart Hold Minutes', 'oras-tickets'),
            array(self::class, 'render_number_field'),
            self::PAGE_GENERAL,
            'oras_tickets_defaults',
            array(
                'field' => 'tickets.cart_hold_minutes',
                'label' => __('Minutes to hold ORAS ticket items in cart before automatic expiration', 'oras-tickets'),
                'help'  => __('Default is 15 minutes. Expired ticket holds are removed during cart and checkout validation.', 'oras-tickets'),
                'min'   => 1,
            )
        );

        add_settings_section(
            'oras_quickbooks_revenue_split',
            __('QuickBooks Revenue Split Sync', 'oras-tickets'),
            array(self::class, 'render_quickbooks_section'),
            self::PAGE_QUICKBOOKS
        );

        add_settings_field(
            'quickbooks_enabled',
            __('Enable Sync', 'oras-tickets'),
            array(self::class, 'render_checkbox_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.enabled',
                'label' => __('Enable QuickBooks JournalEntry revenue split for paid Woo orders', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_dry_run_mode',
            __('Dry Run Mode', 'oras-tickets'),
            array(self::class, 'render_checkbox_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.dry_run_mode',
                'label' => __('Preview only: calculate + validate payload but do not write JournalEntry to QuickBooks', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_require_manual_approval',
            __('Require Manual Approval', 'oras-tickets'),
            array(self::class, 'render_checkbox_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.require_manual_approval',
                'label' => __('Queue completed orders for explicit approval before syncing to QuickBooks', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_strict_mapping_mode',
            __('Strict Mapping Mode', 'oras-tickets'),
            array(self::class, 'render_checkbox_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.strict_mapping_mode',
                'label' => __('Fail closed when any line item cannot be mapped deterministically', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_allow_unmapped_fallback',
            __('Allow Unmapped Fallback', 'oras-tickets'),
            array(self::class, 'render_checkbox_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.allow_unmapped_fallback',
                'label' => __('Allow fallback account for unmapped lines (disable for strictest data safety)', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_sandbox',
            __('Sandbox Mode', 'oras-tickets'),
            array(self::class, 'render_checkbox_field'),
            self::PAGE_QUICKBOOKS,
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
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field'       => 'quickbooks.client_id',
                'input_id'    => 'oras-qbo-client-id',
                'placeholder' => __('QuickBooks app client ID', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_client_secret',
            __('Client Secret', 'oras-tickets'),
            array(self::class, 'render_password_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field'       => 'quickbooks.client_secret',
                'input_id'    => 'oras-qbo-client-secret',
                'placeholder' => __('QuickBooks app client secret', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_realm_id',
            __('Realm ID', 'oras-tickets'),
            array(self::class, 'render_text_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field'       => 'quickbooks.realm_id',
                'placeholder' => __('Populated after OAuth connection', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_sync_cutoff_date',
            __('Sync Cutoff Date', 'oras-tickets'),
            array(self::class, 'render_date_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field'       => 'quickbooks.sync_cutoff_date',
                'help'        => __('Only completed orders created on/after this date can sync to QuickBooks. Prevents accidental historical sync.', 'oras-tickets'),
                'placeholder' => 'YYYY-MM-DD',
            )
        );

        add_settings_field(
            'quickbooks_initial_sync_delay_minutes',
            __('Initial Sync Delay (Minutes)', 'oras-tickets'),
            array(self::class, 'render_number_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.initial_sync_delay_minutes',
                'min'   => 0,
            )
        );

        add_settings_field(
            'quickbooks_source_match_poll_interval_minutes',
            __('Source Match Poll Interval (Minutes)', 'oras-tickets'),
            array(self::class, 'render_number_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.source_match_poll_interval_minutes',
                'min'   => 5,
            )
        );

        add_settings_field(
            'quickbooks_source_match_max_wait_days',
            __('Source Match Max Wait (Days)', 'oras-tickets'),
            array(self::class, 'render_number_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.source_match_max_wait_days',
                'min'   => 1,
            )
        );

        add_settings_field(
            'quickbooks_posting_mode',
            __('Posting Mode', 'oras-tickets'),
            array(self::class, 'render_select_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field'   => 'quickbooks.posting_mode',
                'options' => array(
                    'clearing' => __('Clearing Split (default): DR clearing, CR split income accounts', 'oras-tickets'),
                    'reclass'  => __('Connector Reclass Split: DR connector source income, CR split income accounts', 'oras-tickets'),
                ),
            )
        );

        add_settings_field(
            'quickbooks_reclass_source_account_id',
            __('Reclass Source Account', 'oras-tickets'),
            array(self::class, 'render_account_select_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.reclass_source_account_id',
                'help'  => __('Used only in Connector Reclass Split mode. Select the single income account your Stripe connector posts gross sales into.', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_excluded_payment_methods',
            __('Excluded Payment Methods', 'oras-tickets'),
            array(self::class, 'render_text_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field'       => 'quickbooks.excluded_payment_methods',
                'placeholder' => 'stripe,stripe_cc',
                'help'        => __('Comma-separated Woo payment method IDs to skip ORAS QuickBooks sync for (prevents duplicate posting when another connector already posts those orders).', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_clearing_account_id',
            __('Clearing Account', 'oras-tickets'),
            array(self::class, 'render_account_select_field'),
            self::PAGE_QUICKBOOKS,
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
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.tickets_default_account_id',
            )
        );

        add_settings_field(
            'quickbooks_observer_account_id',
            __('Observer Pass Account', 'oras-tickets'),
            array(self::class, 'render_account_select_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.observer_account_id',
            )
        );

        add_settings_field(
            'quickbooks_merchandise_account_id',
            __('Merchandise Account', 'oras-tickets'),
            array(self::class, 'render_account_select_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.merchandise_account_id',
            )
        );

        add_settings_field(
            'quickbooks_printful_account_id',
            __('Printful Account', 'oras-tickets'),
            array(self::class, 'render_account_select_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.printful_account_id',
                'help'  => __('Optional. If blank, Printful sales fall back to Merchandise Account.', 'oras-tickets'),
            )
        );

        add_settings_field(
            'quickbooks_donations_account_id',
            __('Donations Account', 'oras-tickets'),
            array(self::class, 'render_account_select_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.donations_account_id',
            )
        );

        add_settings_field(
            'quickbooks_unmapped_account_id',
            __('Fallback Unmapped Account', 'oras-tickets'),
            array(self::class, 'render_account_select_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field' => 'quickbooks.unmapped_account_id',
            )
        );

        add_settings_field(
            'quickbooks_observer_category_slugs',
            __('Observer Category Slugs', 'oras-tickets'),
            array(self::class, 'render_text_field'),
            self::PAGE_QUICKBOOKS,
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
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field'       => 'quickbooks.merch_category_slugs',
                'placeholder' => 'merch,merchandise,shirt,shirts,apparel',
            )
        );

        add_settings_field(
            'quickbooks_printful_category_slugs',
            __('Printful Category Slugs', 'oras-tickets'),
            array(self::class, 'render_text_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field'       => 'quickbooks.printful_category_slugs',
                'placeholder' => 'printful,pod',
            )
        );

        add_settings_field(
            'quickbooks_donation_category_slugs',
            __('Donation Category Slugs', 'oras-tickets'),
            array(self::class, 'render_text_field'),
            self::PAGE_QUICKBOOKS,
            'oras_quickbooks_revenue_split',
            array(
                'field'       => 'quickbooks.donation_category_slugs',
                'placeholder' => 'donation,donations,give,giving',
            )
        );

        add_settings_field(
            'quickbooks_event_account_map',
            __('Per-Event Account Map', 'oras-tickets'),
            array(self::class, 'render_textarea_field'),
            self::PAGE_QUICKBOOKS,
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
        $defaults      = self::get_default_settings();
        $current       = self::get_settings();
        $current_qbo   = isset($current['quickbooks']) && is_array($current['quickbooks']) ? $current['quickbooks'] : $defaults['quickbooks'];
        $defaults_qbo  = $defaults['quickbooks'];
        $has_rsvp      = isset($input['rsvp']) && is_array($input['rsvp']);
        $has_virtual   = isset($input['virtual_access']) && is_array($input['virtual_access']);
        $has_tickets   = isset($input['tickets']) && is_array($input['tickets']);
        $has_qbo       = isset($input['quickbooks']) && is_array($input['quickbooks']);
        $input_rsvp    = $has_rsvp ? $input['rsvp'] : ($current['rsvp'] ?? $defaults['rsvp']);
        $input_virtual = $has_virtual ? $input['virtual_access'] : ($current['virtual_access'] ?? $defaults['virtual_access']);
        $input_tickets = $has_tickets ? $input['tickets'] : ($current['tickets'] ?? $defaults['tickets']);
        $input_qbo     = $has_qbo ? $input['quickbooks'] : $current_qbo;

        $client_secret = isset($input_qbo['client_secret']) ? trim((string) $input_qbo['client_secret']) : '';
        if ($client_secret === '') {
            $client_secret = (string) ($current_qbo['client_secret'] ?? '');
        }

        $sanitized = array(
            'version'        => 1,
            'rsvp'           => array(
                'default_enabled'          => ! empty($input_rsvp['default_enabled']),
                'default_capacity'         => absint($input_rsvp['default_capacity'] ?? 0),
                'default_waitlist_enabled' => ! empty($input_rsvp['default_waitlist_enabled']),
            ),
            'virtual_access' => array(
                'default_show_to' => self::sanitize_show_to($input_virtual['default_show_to'] ?? ''),
            ),
            'tickets'        => array(
                'auto_complete_ticket_only_orders' => ! empty($input_tickets['auto_complete_ticket_only_orders']),
                'cart_hold_minutes'                => max(1, min(240, absint($input_tickets['cart_hold_minutes'] ?? 15))),
            ),
            'quickbooks'     => array(
                'enabled'                    => $has_qbo ? ! empty($input_qbo['enabled']) : ! empty($current_qbo['enabled']),
                'dry_run_mode'               => $has_qbo ? ! empty($input_qbo['dry_run_mode']) : ! empty($current_qbo['dry_run_mode']),
                'require_manual_approval'    => $has_qbo ? ! empty($input_qbo['require_manual_approval']) : ! empty($current_qbo['require_manual_approval']),
                'strict_mapping_mode'        => $has_qbo ? ! empty($input_qbo['strict_mapping_mode']) : ! empty($current_qbo['strict_mapping_mode']),
                'allow_unmapped_fallback'    => $has_qbo ? ! empty($input_qbo['allow_unmapped_fallback']) : ! empty($current_qbo['allow_unmapped_fallback']),
                'sandbox'                    => $has_qbo ? ! empty($input_qbo['sandbox']) : ! empty($current_qbo['sandbox']),
                'client_id'                  => sanitize_text_field((string) ($input_qbo['client_id'] ?? '')),
                'client_secret'              => sanitize_text_field($client_secret),
                'realm_id'                   => sanitize_text_field((string) ($input_qbo['realm_id'] ?? '')),
                'access_token'               => isset($input_qbo['access_token']) ? (string) $input_qbo['access_token'] : (string) ($current_qbo['access_token'] ?? ''),
                'refresh_token'              => isset($input_qbo['refresh_token']) ? (string) $input_qbo['refresh_token'] : (string) ($current_qbo['refresh_token'] ?? ''),
                'token_expires_at'           => isset($input_qbo['token_expires_at']) ? (string) $input_qbo['token_expires_at'] : (string) ($current_qbo['token_expires_at'] ?? ''),
                'refresh_token_expires_at'   => isset($input_qbo['refresh_token_expires_at']) ? (string) $input_qbo['refresh_token_expires_at'] : (string) ($current_qbo['refresh_token_expires_at'] ?? ''),
                'connected_at'               => isset($input_qbo['connected_at']) ? (string) $input_qbo['connected_at'] : (string) ($current_qbo['connected_at'] ?? ''),
                'sync_cutoff_date'           => self::sanitize_iso_date((string) ($input_qbo['sync_cutoff_date'] ?? ($current_qbo['sync_cutoff_date'] ?? ''))),
                'initial_sync_delay_minutes' => absint($input_qbo['initial_sync_delay_minutes'] ?? ($current_qbo['initial_sync_delay_minutes'] ?? 0)),
                'source_match_poll_interval_minutes' => max(5, absint($input_qbo['source_match_poll_interval_minutes'] ?? ($current_qbo['source_match_poll_interval_minutes'] ?? 30))),
                'source_match_max_wait_days' => max(1, absint($input_qbo['source_match_max_wait_days'] ?? ($current_qbo['source_match_max_wait_days'] ?? 180))),
                'posting_mode'               => in_array(sanitize_key((string) ($input_qbo['posting_mode'] ?? ($current_qbo['posting_mode'] ?? 'clearing'))), array('clearing', 'reclass'), true)
                    ? sanitize_key((string) ($input_qbo['posting_mode'] ?? ($current_qbo['posting_mode'] ?? 'clearing')))
                    : 'clearing',
                'excluded_payment_methods'   => sanitize_text_field((string) ($input_qbo['excluded_payment_methods'] ?? ($current_qbo['excluded_payment_methods'] ?? ''))),
                'clearing_account_id'        => sanitize_text_field((string) ($input_qbo['clearing_account_id'] ?? '')),
                'reclass_source_account_id'  => sanitize_text_field((string) ($input_qbo['reclass_source_account_id'] ?? '')),
                'tickets_default_account_id' => sanitize_text_field((string) ($input_qbo['tickets_default_account_id'] ?? '')),
                'observer_account_id'        => sanitize_text_field((string) ($input_qbo['observer_account_id'] ?? '')),
                'merchandise_account_id'     => sanitize_text_field((string) ($input_qbo['merchandise_account_id'] ?? '')),
                'printful_account_id'        => sanitize_text_field((string) ($input_qbo['printful_account_id'] ?? '')),
                'donations_account_id'       => sanitize_text_field((string) ($input_qbo['donations_account_id'] ?? '')),
                'unmapped_account_id'        => sanitize_text_field((string) ($input_qbo['unmapped_account_id'] ?? '')),
                'discount_mode'              => 'proportional',
                'observer_category_slugs'    => sanitize_text_field((string) ($input_qbo['observer_category_slugs'] ?? $defaults_qbo['observer_category_slugs'])),
                'merch_category_slugs'       => sanitize_text_field((string) ($input_qbo['merch_category_slugs'] ?? $defaults_qbo['merch_category_slugs'])),
                'printful_category_slugs'    => sanitize_text_field((string) ($input_qbo['printful_category_slugs'] ?? $defaults_qbo['printful_category_slugs'])),
                'donation_category_slugs'    => sanitize_text_field((string) ($input_qbo['donation_category_slugs'] ?? $defaults_qbo['donation_category_slugs'])),
                'event_account_map'          => sanitize_textarea_field((string) ($input_qbo['event_account_map'] ?? ($current_qbo['event_account_map'] ?? ''))),
                'account_cache'              => self::sanitize_account_cache($input_qbo['account_cache'] ?? ($current_qbo['account_cache'] ?? array())),
                'last_error'                 => isset($input_qbo['last_error']) ? (string) $input_qbo['last_error'] : (string) ($current_qbo['last_error'] ?? ''),
            ),
        );

        if (class_exists('\ORAS\Tickets\Integrations\QuickBooks\Settings')) {
            $sanitized['quickbooks'] = \ORAS\Tickets\Integrations\QuickBooks\Settings::prepare_for_storage($sanitized['quickbooks']);
        }

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
                'cart_hold_minutes'                => 15,
            ),
            'quickbooks'     => array(
                'enabled'                    => false,
                'dry_run_mode'               => true,
                'require_manual_approval'    => true,
                'strict_mapping_mode'        => true,
                'allow_unmapped_fallback'    => false,
                'sandbox'                    => true,
                'client_id'                  => '',
                'client_secret'              => '',
                'realm_id'                   => '',
                'access_token'               => '',
                'refresh_token'              => '',
                'token_expires_at'           => '',
                'refresh_token_expires_at'   => '',
                'connected_at'               => '',
                'sync_cutoff_date'           => '',
                'initial_sync_delay_minutes' => 0,
                'source_match_poll_interval_minutes' => 30,
                'source_match_max_wait_days' => 180,
                'posting_mode'               => 'clearing',
                'excluded_payment_methods'   => '',
                'clearing_account_id'        => '',
                'reclass_source_account_id'  => '',
                'tickets_default_account_id' => '',
                'observer_account_id'        => '',
                'merchandise_account_id'     => '',
                'printful_account_id'        => '',
                'donations_account_id'       => '',
                'unmapped_account_id'        => '',
                'discount_mode'              => 'proportional',
                'observer_category_slugs'    => 'observer-pass,observer-passes',
                'merch_category_slugs'       => 'merch,merchandise,shirt,shirts,apparel',
                'printful_category_slugs'    => 'printful,pod',
                'donation_category_slugs'    => 'donation,donations,give,giving',
                'event_account_map'          => '',
                'account_cache'              => array(),
                'last_error'                 => '',
            ),
        );
    }

    public static function get_settings(): array
    {
        $settings = get_option(self::OPTION_KEY, self::get_default_settings());
        if (! is_array($settings)) {
            $settings = self::get_default_settings();
        }

        $settings = wp_parse_args($settings, self::get_default_settings());
        if (class_exists('\ORAS\Tickets\Integrations\QuickBooks\Settings')) {
            $quickbooks = isset($settings['quickbooks']) && is_array($settings['quickbooks']) ? $settings['quickbooks'] : array();
            $settings['quickbooks'] = \ORAS\Tickets\Integrations\QuickBooks\Settings::hydrate_from_storage($quickbooks);
        }

        return $settings;
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
        echo '<p class="description">' . esc_html__('Cart Hold Minutes controls how long ORAS ticket items can remain in cart before they are automatically removed.', 'oras-tickets') . '</p>';
    }

    public static function render_quickbooks_section(): void
    {
        echo '<p>' . esc_html__('Create one QuickBooks JournalEntry per paid Woo order, debiting a clearing account and crediting event/category income accounts.', 'oras-tickets') . '</p>';
        echo '<p><strong>' . esc_html__('Discount handling:', 'oras-tickets') . '</strong> ' . esc_html__('Proportional allocation (line net totals) is used by this release.', 'oras-tickets') . '</p>';
        echo '<p><strong>' . esc_html__('Safety defaults:', 'oras-tickets') . '</strong> ' . esc_html__('Dry Run + Manual Approval + Strict Mapping should remain enabled until reconciliation is validated in production.', 'oras-tickets') . '</p>';

        $redirect_uri = '';
        if (class_exists('\ORAS\Tickets\Integrations\QuickBooks\Settings')) {
            $redirect_uri = (string) \ORAS\Tickets\Integrations\QuickBooks\Settings::get_redirect_uri();
        }

        if ($redirect_uri !== '') {
            echo '<p><strong>' . esc_html__('OAuth Redirect URI:', 'oras-tickets') . '</strong><br /><code>' . esc_html($redirect_uri) . '</code></p>';
            echo '<p class="description">' . esc_html__('Add this exact URI to your Intuit app Keys tab under Redirect URIs. The value must match exactly (scheme, host, path, query string).', 'oras-tickets') . '</p>';
        }

        if (class_exists('\ORAS\Tickets\Integrations\QuickBooks\Settings') && ! \ORAS\Tickets\Integrations\QuickBooks\Settings::has_explicit_encryption_key()) {
            echo '<p class="description"><strong>' . esc_html__('Security:', 'oras-tickets') . '</strong> ' . esc_html__('Define ORAS_TICKETS_QBO_AES_KEY in wp-config.php before production go-live to meet Intuit token encryption key separation requirements.', 'oras-tickets') . '</p>';
        }
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

    public static function render_text_field(array $args): void
    {
        $settings    = self::get_settings();
        $value       = self::get_nested_value($settings, $args['field']);
        $name        = self::OPTION_KEY . '[' . str_replace('.', '][', $args['field']) . ']';
        $input_id    = isset($args['input_id']) ? sanitize_html_class((string) $args['input_id']) : '';
        $placeholder = isset($args['placeholder']) ? (string) $args['placeholder'] : '';
        $help        = isset($args['help']) ? (string) $args['help'] : '';
    ?>
        <input type="text" class="regular-text" <?php if ($input_id !== '') : ?>id="<?php echo esc_attr($input_id); ?>" <?php endif; ?>name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" />
        <?php if ($help !== '') : ?>
            <p class="description"><?php echo esc_html($help); ?></p>
        <?php endif; ?>
    <?php
    }

    public static function render_password_field(array $args): void
    {
        $settings    = self::get_settings();
        $value       = self::get_nested_value($settings, $args['field']);
        $name        = self::OPTION_KEY . '[' . str_replace('.', '][', $args['field']) . ']';
        $input_id    = isset($args['input_id']) ? sanitize_html_class((string) $args['input_id']) : '';
        $placeholder = isset($args['placeholder']) ? (string) $args['placeholder'] : '';
    ?>
        <input type="password" class="regular-text" <?php if ($input_id !== '') : ?>id="<?php echo esc_attr($input_id); ?>" <?php endif; ?>name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" autocomplete="off" />
    <?php
    }

    public static function render_date_field(array $args): void
    {
        $settings    = self::get_settings();
        $value       = self::get_nested_value($settings, $args['field']);
        $name        = self::OPTION_KEY . '[' . str_replace('.', '][', $args['field']) . ']';
        $placeholder = isset($args['placeholder']) ? (string) $args['placeholder'] : 'YYYY-MM-DD';
        $help        = isset($args['help']) ? (string) $args['help'] : '';
    ?>
        <input type="date" class="regular-text" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" pattern="\d{4}-\d{2}-\d{2}" />
        <?php if ($help !== '') : ?>
            <p class="description"><?php echo esc_html($help); ?></p>
        <?php endif; ?>
    <?php
    }

    public static function render_textarea_field(array $args): void
    {
        $settings    = self::get_settings();
        $value       = self::get_nested_value($settings, $args['field']);
        $name        = self::OPTION_KEY . '[' . str_replace('.', '][', $args['field']) . ']';
        $rows        = isset($args['rows']) ? absint($args['rows']) : 4;
        $placeholder = isset($args['placeholder']) ? (string) $args['placeholder'] : '';
        $help        = isset($args['help']) ? (string) $args['help'] : '';
    ?>
        <textarea class="large-text code" rows="<?php echo esc_attr((string) max(2, $rows)); ?>" name="<?php echo esc_attr($name); ?>" placeholder="<?php echo esc_attr($placeholder); ?>"><?php echo esc_textarea((string) $value); ?></textarea>
        <?php if ($help !== '') : ?>
            <p class="description"><?php echo esc_html($help); ?></p>
        <?php endif; ?>
    <?php
    }

    public static function render_account_select_field(array $args): void
    {
        $settings = self::get_settings();
        $value    = (string) self::get_nested_value($settings, $args['field']);
        $name     = self::OPTION_KEY . '[' . str_replace('.', '][', $args['field']) . ']';
        $help     = isset($args['help']) ? (string) $args['help'] : '';
        $accounts = self::get_quickbooks_account_options();
    ?>
        <select name="<?php echo esc_attr($name); ?>">
            <option value=""><?php echo esc_html__('Select account (or leave blank)', 'oras-tickets'); ?></option>
            <?php foreach ($accounts as $account_id => $account_label) : ?>
                <option value="<?php echo esc_attr($account_id); ?>" <?php selected($value, $account_id); ?>>
                    <?php echo esc_html($account_label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($help !== '') : ?>
            <p class="description"><?php echo esc_html($help); ?></p>
        <?php endif; ?>
    <?php
    }

    private function render_quickbooks_actions(): void
    {
    ?>
        <hr />
        <h2><?php echo esc_html__('QuickBooks Actions', 'oras-tickets'); ?></h2>
        <p><?php echo esc_html__('Use these actions to authorize QuickBooks and verify API connectivity.', 'oras-tickets'); ?></p>

        <div style="display:flex; gap:12px; flex-wrap:wrap; margin:12px 0 24px;">
            <form method="post" id="oras-qbo-connect-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('oras_tickets_qbo_oauth_start'); ?>
                <input type="hidden" name="action" value="oras_tickets_qbo_oauth_start" />
                <input type="hidden" name="oras_qbo_client_id" id="oras-qbo-client-id-hidden" value="" />
                <input type="hidden" name="oras_qbo_client_secret" id="oras-qbo-client-secret-hidden" value="" />
                <button type="submit" class="button button-primary"><?php echo esc_html__('Connect / Reconnect QuickBooks', 'oras-tickets'); ?></button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('oras_tickets_qbo_test_connection'); ?>
                <input type="hidden" name="action" value="oras_tickets_qbo_test_connection" />
                <button type="submit" class="button"><?php echo esc_html__('Test Connection + Refresh Accounts', 'oras-tickets'); ?></button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('oras_tickets_qbo_test_journal_entry'); ?>
                <input type="hidden" name="action" value="oras_tickets_qbo_test_journal_entry" />
                <button type="submit" class="button"><?php echo esc_html__('Test JournalEntry', 'oras-tickets'); ?></button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('oras_tickets_qbo_auto_map_event_accounts'); ?>
                <input type="hidden" name="action" value="oras_tickets_qbo_auto_map_event_accounts" />
                <input type="hidden" name="oras_qbo_tab" value="settings" />
                <button type="submit" class="button"><?php echo esc_html__('Auto-Map Event Accounts (Safe Add-Only)', 'oras-tickets'); ?></button>
            </form>
        </div>

        <p class="description" style="margin-top:-12px;">
            <?php echo esc_html__('Auto-Map scans published/future website events and active QuickBooks income accounts. It adds only missing event-slug mappings when names match and never changes existing mappings.', 'oras-tickets'); ?>
            <?php echo esc_html__('Automation: after a successful account refresh and when a published/future event is updated, ORAS re-runs safe add-only auto-map in the background.', 'oras-tickets'); ?>
        </p>

        <script>
            (function() {
                var connectForm = document.getElementById('oras-qbo-connect-form');
                if (!connectForm) {
                    return;
                }

                connectForm.addEventListener('submit', function() {
                    var clientIdInput = document.getElementById('oras-qbo-client-id');
                    var clientSecretInput = document.getElementById('oras-qbo-client-secret');
                    var clientIdHidden = document.getElementById('oras-qbo-client-id-hidden');
                    var clientSecretHidden = document.getElementById('oras-qbo-client-secret-hidden');

                    if (clientIdInput && clientIdHidden) {
                        clientIdHidden.value = clientIdInput.value || '';
                    }

                    if (clientSecretInput && clientSecretHidden) {
                        clientSecretHidden.value = clientSecretInput.value || '';
                    }
                });
            }());
        </script>
    <?php
    }

    /**
     * @param array<string,mixed> $qbo_settings
     */
    private function render_quickbooks_connection_indicator(array $qbo_settings): void
    {
        $realm_id         = trim((string) ($qbo_settings['realm_id'] ?? ''));
        $refresh_token    = trim((string) ($qbo_settings['refresh_token'] ?? ''));
        $connected_at_raw = trim((string) ($qbo_settings['connected_at'] ?? ''));
        $token_expires_at = trim((string) ($qbo_settings['refresh_token_expires_at'] ?? ''));
        $is_sandbox       = ! empty($qbo_settings['sandbox']);
        $last_error       = trim((string) ($qbo_settings['last_error'] ?? ''));

        $has_connection_data = $realm_id !== '' && $refresh_token !== '';
        $refresh_valid       = self::is_future_gmt_timestamp($token_expires_at);

        $is_connected = $has_connection_data && ($token_expires_at === '' || $refresh_valid);
        $status_label = $is_connected
            ? __('Connected', 'oras-tickets')
            : __('Not Connected', 'oras-tickets');
        $mode_label   = $is_sandbox
            ? __('Sandbox', 'oras-tickets')
            : __('Production', 'oras-tickets');

        $detail = $is_connected
            ? sprintf(
                /* translators: 1: mode (Sandbox/Production), 2: realm id */
                __('QuickBooks connection is active in %1$s mode. Realm ID: %2$s.', 'oras-tickets'),
                $mode_label,
                $realm_id
            )
            : __('QuickBooks is not currently connected. Use Connect / Reconnect QuickBooks below.', 'oras-tickets');

        $connected_at_display = self::format_gmt_datetime_for_display($connected_at_raw);
        $expires_display      = self::format_gmt_datetime_for_display($token_expires_at);
        if (! $is_connected && $token_expires_at !== '' && ! $refresh_valid) {
            $detail = __('QuickBooks connection expired. Reconnect to continue syncing.', 'oras-tickets');
        }
    ?>
        <?php
        $card_class = $is_connected ? 'oras-qbo-connection-card' : 'oras-qbo-connection-card warning';
        ?>
        <div class="<?php echo esc_attr($card_class); ?>">
            <h3><?php echo esc_html__('Connection Status:', 'oras-tickets'); ?> <?php echo esc_html($status_label); ?></h3>
            <div class="oras-qbo-connection-meta">
                <p style="margin:0;"><?php echo esc_html($detail); ?></p>
                <?php if ($connected_at_display !== '') : ?>
                    <p style="margin:6px 0 0;"><strong><?php echo esc_html__('Connected At:', 'oras-tickets'); ?></strong> <?php echo esc_html($connected_at_display); ?></p>
                <?php endif; ?>
                <?php if ($expires_display !== '') : ?>
                    <p style="margin:6px 0 0;"><strong><?php echo esc_html__('Refresh Token Expires:', 'oras-tickets'); ?></strong> <?php echo esc_html($expires_display); ?></p>
                <?php endif; ?>
                <?php if ($last_error !== '') : ?>
                    <p style="margin:6px 0 0;"><strong><?php echo esc_html__('Last Error:', 'oras-tickets'); ?></strong> <?php echo esc_html($last_error); ?></p>
                <?php endif; ?>
            </div>
        </div>
<?php
    }

    private static function is_future_gmt_timestamp(string $timestamp): bool
    {
        if ($timestamp === '') {
            return true;
        }

        $unix = strtotime($timestamp . ' UTC');
        if (false === $unix) {
            return false;
        }

        return $unix > time();
    }

    private static function format_gmt_datetime_for_display(string $timestamp): string
    {
        if ($timestamp === '') {
            return '';
        }

        $unix = strtotime($timestamp . ' UTC');
        if (false === $unix) {
            return '';
        }

        return wp_date('Y-m-d H:i:s T', $unix);
    }

    /**
     * @return array<string,string>
     */
    private static function get_quickbooks_account_options(): array
    {
        $settings = self::get_settings();
        $cache    = isset($settings['quickbooks']['account_cache']) && is_array($settings['quickbooks']['account_cache'])
            ? $settings['quickbooks']['account_cache']
            : array();

        $options = array();
        foreach ($cache as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id    = isset($row['id']) ? sanitize_text_field((string) $row['id']) : '';
            $label = isset($row['label']) ? sanitize_text_field((string) $row['label']) : $id;
            $type  = isset($row['type']) ? sanitize_text_field((string) $row['type']) : '';
            if ($id === '') {
                continue;
            }
            $options[$id] = $type !== '' ? sprintf('%1$s (%2$s)', $label, $type) : $label;
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
    private static function sanitize_account_cache($cache): array
    {
        if (! is_array($cache)) {
            return array();
        }

        $sanitized = array();
        foreach ($cache as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = isset($row['id']) ? sanitize_text_field((string) $row['id']) : '';
            if ($id === '') {
                continue;
            }
            $sanitized[] = array(
                'id'    => $id,
                'label' => isset($row['label']) ? sanitize_text_field((string) $row['label']) : $id,
                'type'  => isset($row['type']) ? sanitize_text_field((string) $row['type']) : '',
            );
        }

        return $sanitized;
    }

    private static function sanitize_iso_date(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        $timestamp = strtotime($value . ' UTC');
        if (false === $timestamp) {
            return '';
        }

        return gmdate('Y-m-d', $timestamp);
    }
}

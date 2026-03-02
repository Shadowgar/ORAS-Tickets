<?php

namespace ORAS\Tickets\Frontend;

use ORAS\Tickets\Integrations\QuickBooks\Api_Client;
use ORAS\Tickets\Integrations\QuickBooks\Settings;

if (! defined('ABSPATH')) {
    exit;
}

final class Board_Dashboard
{ // NOSONAR legacy WP class naming
    private const LOGIN_DAILY_OPTION = 'oras_tickets_board_login_daily_v1';

    public static function register(): void
    {
        add_shortcode('oras_board_dashboard', array(self::class, 'render_shortcode'));
        add_action('wp_login', array(self::class, 'record_login_event'), 10, 2);
    }

    /**
     * @param array<string,mixed> $atts
     */
    public static function render_shortcode(array $atts = array()): string
    {
        if (! is_user_logged_in()) {
            $login_url = wp_login_url((string) get_permalink());
            return '<p>' . esc_html__('Please sign in to view the Board Dashboard.', 'oras-tickets') . ' <a href="' . esc_url($login_url) . '">' . esc_html__('Sign in', 'oras-tickets') . '</a></p>';
        }

        if (! current_user_can('oras_tickets_view_board_dashboard')) {
            return '<p>' . esc_html__('You do not have permission to view this dashboard.', 'oras-tickets') . '</p>';
        }

        $date_range = self::get_date_range_from_query();
        $can_view_source_diagnostics = current_user_can('manage_options');
        $cashflow   = self::build_woo_cashflow_summary($date_range);
        $pmpro_cashflow = self::build_pmpro_cashflow_summary($date_range);
        $financials = self::resolve_financials($cashflow, $date_range);
        $pmpro_lifecycle = self::build_pmpro_lifecycle_summary();
        $website_activity = self::build_website_activity_summary();
        $operations_health = $can_view_source_diagnostics
            ? self::build_operations_health_summary()
            : array('available' => false, 'as_of' => '');
        $waitlist_summary = self::build_waitlist_conversion_summary();
        $engagement_summary = self::build_engagement_funnel_summary();
        $watch_alerts = $can_view_source_diagnostics
            ? self::build_watch_alerts($operations_health, $waitlist_summary, $engagement_summary)
            : array();
        $notable_changes = self::build_notable_changes($date_range, $cashflow, $pmpro_cashflow, $website_activity);
        $can_view_reconciliation = current_user_can('oras_tickets_view_treasurer_reconciliation');
        $reconciliation = $can_view_reconciliation
            ? self::build_reconciliation_detail($date_range, 8)
            : array();

        $gross_sales   = (float) $financials['gross_sales'];
        $refunded      = (float) $financials['refunded_amount'];
        $net_sales     = (float) $financials['net_sales'];
        $orders_count  = (int) ($cashflow['orders_count'] ?? 0);
        $items_sold    = (int) ($cashflow['items_sold'] ?? 0);
        $ticket_sales  = (float) ($cashflow['ticket_sales'] ?? 0.0);
        $merch_sales   = (float) ($cashflow['merch_sales'] ?? 0.0);
        $membership_sales = (float) ($cashflow['membership_sales'] ?? 0.0);
        $donation_sales = (float) ($cashflow['donation_sales'] ?? 0.0);
        $other_sales   = (float) ($cashflow['other_sales'] ?? 0.0);
        $pmpro_direct_sales = (float) ($pmpro_cashflow['membership_sales'] ?? 0.0);
        $estimated_total_inflow = $gross_sales + $pmpro_direct_sales;
        $financials_as_of = (string) ($financials['as_of'] ?? '');
        $financials_source = (string) ($financials['source'] ?? 'site_fallback');
        $financials_note = '';
        if ($can_view_source_diagnostics) {
            $financials_note = (string) ($financials['source_note'] ?? '');
        } elseif ($financials_source === 'quickbooks') {
            $financials_note = (string) __('Financial totals source: QuickBooks.', 'oras-tickets');
        } else {
            $financials_note = (string) __('Financial totals source: website data.', 'oras-tickets');
        }
        $woo_as_of = (string) ($cashflow['as_of'] ?? '');
        $pmpro_as_of = (string) ($pmpro_cashflow['as_of'] ?? '');
        $pmpro_lifecycle_as_of = (string) ($pmpro_lifecycle['as_of'] ?? '');
        $activity_as_of = (string) ($website_activity['as_of'] ?? '');
        $operations_as_of = (string) ($operations_health['as_of'] ?? '');
        $waitlist_as_of = (string) ($waitlist_summary['as_of'] ?? '');
        $engagement_as_of = (string) ($engagement_summary['as_of'] ?? '');
        $refund_rate   = $gross_sales > 0 ? (($refunded / $gross_sales) * 100) : 0.0;
        $average_order = $orders_count > 0 ? ($gross_sales / $orders_count) : 0.0;
        $ticket_mix    = $gross_sales > 0 ? (($ticket_sales / $gross_sales) * 100) : 0.0;
        $merch_mix     = $gross_sales > 0 ? (($merch_sales / $gross_sales) * 100) : 0.0;
        $membership_mix = $gross_sales > 0 ? (($membership_sales / $gross_sales) * 100) : 0.0;

        $revenue_stream_count = 0;
        foreach (array($ticket_sales, $merch_sales, $membership_sales, $donation_sales, $other_sales) as $stream_amount) {
            if ((float) $stream_amount > 0.0) {
                ++$revenue_stream_count;
            }
        }
        $revenue_diversity_score = ($revenue_stream_count / 5) * 100;
        $membership_dependency   = $estimated_total_inflow > 0 ? ((($membership_sales + $pmpro_direct_sales) / $estimated_total_inflow) * 100) : 0.0;
        $waitlist_efficiency     = (float) ($waitlist_summary['promotion_efficiency'] ?? 0.0);

        $subscribed_count        = (int) ($engagement_summary['subscribed_count'] ?? 0);
        $unconfirmed_count       = (int) ($engagement_summary['unconfirmed_count'] ?? 0);
        $unsubscribed_count      = (int) ($engagement_summary['unsubscribed_count'] ?? 0);
        $engagement_total        = $subscribed_count + $unconfirmed_count + $unsubscribed_count;
        $subscriber_confirmation = $engagement_total > 0 ? (($subscribed_count / $engagement_total) * 100) : 0.0;

        $forms_30d               = (int) ($engagement_summary['form_submissions_30d'] ?? 0);
        $opens_30d               = (int) ($engagement_summary['opens_30d'] ?? 0);
        $open_to_form_rate       = $forms_30d > 0 ? (($opens_30d / $forms_30d) * 100) : 0.0;

        ob_start();
        ?>
        <div class="oras-board-dashboard">
            <style>
                .oras-board-dashboard { margin: 24px 0; }
                .oras-board-dashboard .oras-board-grid {
                    display: grid;
                    grid-template-columns: repeat(1, minmax(0, 1fr));
                    gap: 12px;
                    grid-auto-rows: 1fr;
                }
                @media (min-width: 720px) {
                    .oras-board-dashboard .oras-board-grid {
                        grid-template-columns: repeat(2, minmax(0, 1fr));
                    }
                }
                @media (min-width: 1120px) {
                    .oras-board-dashboard .oras-board-grid {
                        grid-template-columns: repeat(3, minmax(0, 1fr));
                    }
                }
                .oras-board-dashboard .oras-board-card {
                    background: #fff;
                    border: 1px solid #dcdcde;
                    border-radius: 8px;
                    padding: 14px;
                    min-height: 140px;
                    height: 100%;
                    display: flex;
                    flex-direction: column;
                    justify-content: flex-start;
                }
                .oras-board-dashboard .oras-board-label {
                    font-size: 12px;
                    color: #50575e;
                    text-transform: uppercase;
                    letter-spacing: .02em;
                }
                .oras-board-dashboard .oras-board-value {
                    font-size: 24px;
                    font-weight: 600;
                    margin-top: 6px;
                }
                .oras-board-dashboard .oras-board-sub {
                    font-size: 12px;
                    color: #646970;
                    margin-top: auto;
                    min-height: 22px;
                    display: block;
                }
                .oras-board-dashboard .oras-board-recon {
                    margin-top: 18px;
                    background: #fff;
                    border: 1px solid #dcdcde;
                    border-radius: 8px;
                    padding: 14px;
                }
                .oras-board-dashboard .oras-board-recon-meta {
                    margin: 8px 0 12px;
                    font-size: 13px;
                    color: #50575e;
                }
                .oras-board-dashboard .oras-board-table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 13px;
                }
                .oras-board-dashboard .oras-board-table th,
                .oras-board-dashboard .oras-board-table td {
                    border-top: 1px solid #f0f0f1;
                    text-align: left;
                    padding: 8px 6px;
                }
                .oras-board-dashboard .oras-board-table th {
                    font-weight: 600;
                    color: #1d2327;
                }
                .oras-board-dashboard .oras-board-note {
                    margin-top: 10px;
                    font-size: 12px;
                    color: #646970;
                }
                .oras-board-dashboard .oras-board-role-pill {
                    display: inline-block;
                    margin-left: 8px;
                    font-size: 11px;
                    line-height: 1;
                    padding: 4px 6px;
                    border: 1px solid #c3c4c7;
                    border-radius: 999px;
                    color: #50575e;
                    vertical-align: middle;
                    text-transform: uppercase;
                    letter-spacing: .03em;
                }
                .oras-board-dashboard .oras-board-section {
                    margin-top: 18px;
                    background: #fff;
                    border: 1px solid #dcdcde;
                    border-radius: 8px;
                    padding: 14px;
                }
                .oras-board-dashboard .oras-board-warning {
                    margin: 12px 0;
                    background: #fcf9e8;
                    border: 1px solid #dba617;
                    border-radius: 8px;
                    padding: 10px 12px;
                    color: #3c434a;
                    font-size: 13px;
                }
                .oras-board-dashboard .oras-board-list {
                    margin: 0;
                    padding-left: 18px;
                }
                .oras-board-dashboard .oras-board-list li {
                    margin: 6px 0;
                }
                .oras-board-dashboard .oras-board-chip {
                    display: inline-block;
                    font-size: 10px;
                    line-height: 1;
                    padding: 4px 6px;
                    margin-right: 8px;
                    border-radius: 999px;
                    border: 1px solid #c3c4c7;
                    color: #50575e;
                    text-transform: uppercase;
                    letter-spacing: .03em;
                    vertical-align: middle;
                }
                .oras-board-dashboard .oras-board-chip.up {
                    color: #1e6f43;
                    border-color: #46b450;
                    background: #e7f7ed;
                }
                .oras-board-dashboard .oras-board-chip.down {
                    color: #8a2424;
                    border-color: #d63638;
                    background: #fbeaea;
                }
                .oras-board-dashboard .oras-board-chip.watch {
                    color: #8a6a00;
                    border-color: #dba617;
                    background: #fcf9e8;
                }
            </style>

            <h2><?php echo esc_html__('Board Dashboard', 'oras-tickets'); ?></h2>
            <p class="oras-board-warning">
                <?php echo esc_html__('Board note: figures on this dashboard are rough operational estimates. Final confirmed totals must come from the Treasurer.', 'oras-tickets'); ?>
            </p>
            <?php if ($financials_note !== '') : ?>
                <p><em><?php echo esc_html($financials_note); ?></em></p>
            <?php endif; ?>
            <?php if ($financials_as_of !== '') : ?>
                <p class="oras-board-note"><?php echo esc_html__('Financial totals as of: ', 'oras-tickets') . esc_html(self::format_as_of($financials_as_of)); ?></p>
            <?php endif; ?>

            <?php if ($can_view_source_diagnostics) : ?>
                <div class="oras-board-section">
                    <h3>
                        <?php echo esc_html__('Watch Alerts', 'oras-tickets'); ?>
                        <span class="oras-board-role-pill"><?php echo esc_html__('Admin View', 'oras-tickets'); ?></span>
                    </h3>
                    <?php if (! empty($watch_alerts)) : ?>
                        <ul class="oras-board-list">
                            <?php foreach ($watch_alerts as $alert_row) : ?>
                                <?php
                                $tone = sanitize_key((string) ($alert_row['tone'] ?? 'watch'));
                                if (! in_array($tone, array('up', 'down', 'watch'), true)) {
                                    $tone = 'watch';
                                }
                                $message = (string) ($alert_row['text'] ?? '');
                                ?>
                                <li>
                                    <span class="oras-board-chip <?php echo esc_attr($tone); ?>"><?php echo esc_html(strtoupper($tone)); ?></span>
                                    <?php echo esc_html($message); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else : ?>
                        <p class="oras-board-note">
                            <span class="oras-board-chip up"><?php echo esc_html__('STABLE', 'oras-tickets'); ?></span>
                            <?php echo esc_html__('No watch thresholds are currently triggered.', 'oras-tickets'); ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="oras-board-section">
                <h3>
                    <?php echo esc_html__('Top 5 notable changes this period', 'oras-tickets'); ?>
                    <span class="oras-board-role-pill"><?php echo esc_html__('Board View', 'oras-tickets'); ?></span>
                </h3>
                <?php if (! empty($notable_changes)) : ?>
                    <ul class="oras-board-list">
                        <?php foreach ($notable_changes as $change_row) : ?>
                            <?php
                            $tone = sanitize_key((string) ($change_row['tone'] ?? 'watch'));
                            if (! in_array($tone, array('up', 'down', 'watch'), true)) {
                                $tone = 'watch';
                            }
                            $message = (string) ($change_row['text'] ?? '');
                            ?>
                            <li>
                                <span class="oras-board-chip <?php echo esc_attr($tone); ?>"><?php echo esc_html(strtoupper($tone)); ?></span>
                                <?php echo esc_html($message); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else : ?>
                    <p class="oras-board-note"><?php echo esc_html__('Not enough comparative data yet to identify notable changes.', 'oras-tickets'); ?></p>
                <?php endif; ?>
            </div>

            <div class="oras-board-grid">
                <div class="oras-board-card">
                    <div class="oras-board-label"><?php echo esc_html__('Gross Sales', 'oras-tickets'); ?></div>
                    <div class="oras-board-value"><?php echo esc_html(self::format_money($gross_sales)); ?></div>
                    <div class="oras-board-sub">&nbsp;</div>
                </div>
                <div class="oras-board-card">
                    <div class="oras-board-label"><?php echo esc_html__('Net Sales', 'oras-tickets'); ?></div>
                    <div class="oras-board-value"><?php echo esc_html(self::format_money($net_sales)); ?></div>
                    <div class="oras-board-sub">&nbsp;</div>
                </div>
                <div class="oras-board-card">
                    <div class="oras-board-label"><?php echo esc_html__('Refunded', 'oras-tickets'); ?></div>
                    <div class="oras-board-value"><?php echo esc_html(self::format_money($refunded)); ?></div>
                    <div class="oras-board-sub"><?php echo esc_html(number_format_i18n($refund_rate, 2) . '% ' . __('refund rate', 'oras-tickets')); ?></div>
                </div>
                <div class="oras-board-card">
                    <div class="oras-board-label"><?php echo esc_html__('Orders', 'oras-tickets'); ?></div>
                    <div class="oras-board-value"><?php echo esc_html((string) $orders_count); ?></div>
                    <div class="oras-board-sub"><?php echo esc_html__('Items: ', 'oras-tickets') . esc_html((string) $items_sold); ?></div>
                </div>
                <div class="oras-board-card">
                    <div class="oras-board-label"><?php echo esc_html__('Average Order Value', 'oras-tickets'); ?></div>
                    <div class="oras-board-value"><?php echo esc_html(self::format_money($average_order)); ?></div>
                    <div class="oras-board-sub">&nbsp;</div>
                </div>
                <div class="oras-board-card">
                    <div class="oras-board-label"><?php echo esc_html__('Ticket Revenue Mix', 'oras-tickets'); ?></div>
                    <div class="oras-board-value"><?php echo esc_html(number_format_i18n($ticket_mix, 2) . '%'); ?></div>
                    <div class="oras-board-sub"><?php echo esc_html__('Tickets: ', 'oras-tickets') . esc_html(self::format_money($ticket_sales)); ?></div>
                </div>
                <div class="oras-board-card">
                    <div class="oras-board-label"><?php echo esc_html__('Merch Revenue Mix', 'oras-tickets'); ?></div>
                    <div class="oras-board-value"><?php echo esc_html(number_format_i18n($merch_mix, 2) . '%'); ?></div>
                    <div class="oras-board-sub"><?php echo esc_html__('Merch: ', 'oras-tickets') . esc_html(self::format_money($merch_sales)); ?></div>
                </div>
                <div class="oras-board-card">
                    <div class="oras-board-label"><?php echo esc_html__('Membership Revenue Mix', 'oras-tickets'); ?></div>
                    <div class="oras-board-value"><?php echo esc_html(number_format_i18n($membership_mix, 2) . '%'); ?></div>
                    <div class="oras-board-sub"><?php echo esc_html__('Membership: ', 'oras-tickets') . esc_html(self::format_money($membership_sales)); ?></div>
                </div>
                <div class="oras-board-card">
                    <div class="oras-board-label"><?php echo esc_html__('Estimated Total Inflow', 'oras-tickets'); ?></div>
                    <div class="oras-board-value"><?php echo esc_html(self::format_money($estimated_total_inflow)); ?></div>
                    <div class="oras-board-sub"><?php echo esc_html__('Woo/QBO gross + direct membership cashflow', 'oras-tickets'); ?></div>
                </div>
            </div>

            <div class="oras-board-section">
                <h3>
                    <?php echo esc_html__('Revenue Streams (Website)', 'oras-tickets'); ?>
                    <span class="oras-board-role-pill"><?php echo esc_html__('Board View', 'oras-tickets'); ?></span>
                </h3>
                <table class="oras-board-table" aria-label="<?php echo esc_attr__('Revenue stream totals', 'oras-tickets'); ?>">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Stream', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Amount', 'oras-tickets'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><?php echo esc_html__('Ticket Sales', 'oras-tickets'); ?></td><td><?php echo esc_html(self::format_money($ticket_sales)); ?></td></tr>
                        <tr><td><?php echo esc_html__('Merch Sales', 'oras-tickets'); ?></td><td><?php echo esc_html(self::format_money($merch_sales)); ?></td></tr>
                        <tr><td><?php echo esc_html__('Membership Sales', 'oras-tickets'); ?></td><td><?php echo esc_html(self::format_money($membership_sales)); ?></td></tr>
                        <tr><td><?php echo esc_html__('Direct Membership Cashflow', 'oras-tickets'); ?></td><td><?php echo esc_html(self::format_money($pmpro_direct_sales)); ?></td></tr>
                        <tr><td><?php echo esc_html__('Donations', 'oras-tickets'); ?></td><td><?php echo esc_html(self::format_money($donation_sales)); ?></td></tr>
                        <tr><td><?php echo esc_html__('Other Revenue', 'oras-tickets'); ?></td><td><?php echo esc_html(self::format_money($other_sales)); ?></td></tr>
                    </tbody>
                </table>
                <?php if (! empty($pmpro_cashflow['source_note'])) : ?>
                    <p class="oras-board-note"><?php echo esc_html((string) $pmpro_cashflow['source_note']); ?></p>
                <?php endif; ?>
                <p class="oras-board-note">
                    <?php
                    $source_as_of_parts = array();
                    if ($woo_as_of !== '') {
                        $source_as_of_parts[] = 'Woo: ' . self::format_as_of($woo_as_of);
                    }
                    if ($pmpro_as_of !== '') {
                        $source_as_of_parts[] = 'Membership: ' . self::format_as_of($pmpro_as_of);
                    }
                    echo esc_html('Source freshness — ' . implode(' · ', $source_as_of_parts));
                    ?>
                </p>
            </div>

            <div class="oras-board-section">
                <h3>
                    <?php echo esc_html__('Membership Lifecycle', 'oras-tickets'); ?>
                    <span class="oras-board-role-pill"><?php echo esc_html__('Board View', 'oras-tickets'); ?></span>
                </h3>
                <?php if (! empty($pmpro_lifecycle['available'])) : ?>
                    <table class="oras-board-table" aria-label="<?php echo esc_attr__('Membership lifecycle trends', 'oras-tickets'); ?>">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Period', 'oras-tickets'); ?></th>
                                <th><?php echo esc_html__('New Memberships', 'oras-tickets'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ((array) ($pmpro_lifecycle['periods'] ?? array()) as $period_key => $period_row) : ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($period_row['label'] ?? $period_key)); ?></td>
                                    <td><?php echo esc_html((string) ((int) ($period_row['signups'] ?? 0))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php $membership_levels = isset($pmpro_lifecycle['levels']) && is_array($pmpro_lifecycle['levels']) ? $pmpro_lifecycle['levels'] : array(); ?>
                    <h4><?php echo esc_html__('Active Members by Level', 'oras-tickets'); ?></h4>
                    <?php if (! empty($membership_levels)) : ?>
                        <table class="oras-board-table" aria-label="<?php echo esc_attr__('Active members by level', 'oras-tickets'); ?>">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Membership Level', 'oras-tickets'); ?></th>
                                    <th><?php echo esc_html__('Active Members', 'oras-tickets'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($membership_levels as $level_row) : ?>
                                    <tr>
                                        <td><?php echo esc_html((string) ($level_row['level_name'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string) ((int) ($level_row['member_count'] ?? 0))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p class="oras-board-note"><?php echo esc_html__('No active membership levels found.', 'oras-tickets'); ?></p>
                    <?php endif; ?>
                <?php else : ?>
                    <p><?php echo esc_html__('Membership lifecycle data is unavailable on this site.', 'oras-tickets'); ?></p>
                <?php endif; ?>
                <?php if ($pmpro_lifecycle_as_of !== '') : ?>
                    <p class="oras-board-note"><?php echo esc_html__('Membership lifecycle as of: ', 'oras-tickets') . esc_html(self::format_as_of($pmpro_lifecycle_as_of)); ?></p>
                <?php endif; ?>
            </div>

            <div class="oras-board-section">
                <h3>
                    <?php echo esc_html__('Website Activity', 'oras-tickets'); ?>
                    <span class="oras-board-role-pill"><?php echo esc_html__('Board View', 'oras-tickets'); ?></span>
                </h3>
                <?php $activity_periods = isset($website_activity['periods']) && is_array($website_activity['periods']) ? $website_activity['periods'] : array(); ?>
                <table class="oras-board-table" aria-label="<?php echo esc_attr__('Website activity trends', 'oras-tickets'); ?>">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Period', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Logins', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Signups', 'oras-tickets'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activity_periods as $period_key => $period_row) : ?>
                            <tr>
                                <td><?php echo esc_html((string) ($period_row['label'] ?? $period_key)); ?></td>
                                <td><?php echo esc_html((string) ((int) ($period_row['logins'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) ((int) ($period_row['signups'] ?? 0))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="oras-board-note">
                    <?php
                    echo esc_html(
                        sprintf(
                            'Total users: %1$d · Active members: %2$d',
                            (int) ($website_activity['total_users'] ?? 0),
                            (int) ($website_activity['active_members'] ?? 0)
                        )
                    );
                    ?>
                </p>
                <?php if (! empty($website_activity['source_note'])) : ?>
                    <p class="oras-board-note"><?php echo esc_html((string) $website_activity['source_note']); ?></p>
                <?php endif; ?>
                <?php if ($activity_as_of !== '') : ?>
                    <p class="oras-board-note"><?php echo esc_html__('Website activity as of: ', 'oras-tickets') . esc_html(self::format_as_of($activity_as_of)); ?></p>
                <?php endif; ?>
            </div>

            <?php if ($can_view_source_diagnostics) : ?>
                <div class="oras-board-section">
                    <h3>
                        <?php echo esc_html__('Operations Health', 'oras-tickets'); ?>
                        <span class="oras-board-role-pill"><?php echo esc_html__('Admin View', 'oras-tickets'); ?></span>
                    </h3>
                    <?php if (! empty($operations_health['available'])) : ?>
                        <table class="oras-board-table" aria-label="<?php echo esc_attr__('Automation and queue health', 'oras-tickets'); ?>">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Metric', 'oras-tickets'); ?></th>
                                    <th><?php echo esc_html__('Value', 'oras-tickets'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td><?php echo esc_html__('Failed jobs', 'oras-tickets'); ?></td><td><?php echo esc_html((string) ((int) ($operations_health['failed_total'] ?? 0))); ?></td></tr>
                                <tr><td><?php echo esc_html__('Pending jobs', 'oras-tickets'); ?></td><td><?php echo esc_html((string) ((int) ($operations_health['pending_total'] ?? 0))); ?></td></tr>
                                <tr><td><?php echo esc_html__('Completed jobs', 'oras-tickets'); ?></td><td><?php echo esc_html((string) ((int) ($operations_health['complete_total'] ?? 0))); ?></td></tr>
                                <tr><td><?php echo esc_html__('Failed jobs (last 7 days)', 'oras-tickets'); ?></td><td><?php echo esc_html((string) ((int) ($operations_health['failed_7d'] ?? 0))); ?></td></tr>
                                <tr><td><?php echo esc_html__('Pending jobs (last 7 days)', 'oras-tickets'); ?></td><td><?php echo esc_html((string) ((int) ($operations_health['pending_7d'] ?? 0))); ?></td></tr>
                            </tbody>
                        </table>
                        <?php $failed_hooks = isset($operations_health['top_failed_hooks']) && is_array($operations_health['top_failed_hooks']) ? $operations_health['top_failed_hooks'] : array(); ?>
                        <?php if (! empty($failed_hooks)) : ?>
                            <p class="oras-board-note"><?php echo esc_html__('Top failed automation hooks:', 'oras-tickets'); ?></p>
                            <ul class="oras-board-list">
                                <?php foreach ($failed_hooks as $hook_row) : ?>
                                    <li><?php echo esc_html((string) ($hook_row['hook'] ?? '')); ?><?php echo esc_html(' — ' . (string) ((int) ($hook_row['count'] ?? 0))); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php else : ?>
                        <p><?php echo esc_html__('Automation health data is unavailable on this site.', 'oras-tickets'); ?></p>
                    <?php endif; ?>
                    <?php if (! empty($operations_health['source_note'])) : ?>
                        <p class="oras-board-note"><?php echo esc_html((string) $operations_health['source_note']); ?></p>
                    <?php endif; ?>
                    <?php if ($operations_as_of !== '') : ?>
                        <p class="oras-board-note"><?php echo esc_html__('Operations health as of: ', 'oras-tickets') . esc_html(self::format_as_of($operations_as_of)); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="oras-board-section">
                <h3>
                    <?php echo esc_html__('Waitlist Conversion', 'oras-tickets'); ?>
                    <span class="oras-board-role-pill"><?php echo esc_html__('Board View', 'oras-tickets'); ?></span>
                </h3>
                <?php if (! empty($waitlist_summary['available'])) : ?>
                    <table class="oras-board-table" aria-label="<?php echo esc_attr__('Waitlist pipeline and conversion', 'oras-tickets'); ?>">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Metric', 'oras-tickets'); ?></th>
                                <th><?php echo esc_html__('Value', 'oras-tickets'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><?php echo esc_html__('Currently waiting', 'oras-tickets'); ?></td><td><?php echo esc_html((string) ((int) ($waitlist_summary['waiting_count'] ?? 0))); ?></td></tr>
                            <tr><td><?php echo esc_html__('Promoted (all-time)', 'oras-tickets'); ?></td><td><?php echo esc_html((string) ((int) ($waitlist_summary['promoted_count'] ?? 0))); ?></td></tr>
                            <tr><td><?php echo esc_html__('Left waitlist (all-time)', 'oras-tickets'); ?></td><td><?php echo esc_html((string) ((int) ($waitlist_summary['left_count'] ?? 0))); ?></td></tr>
                            <tr><td><?php echo esc_html__('Promotion efficiency', 'oras-tickets'); ?></td><td><?php echo esc_html(number_format_i18n((float) ($waitlist_summary['promotion_efficiency'] ?? 0.0), 2) . '%'); ?></td></tr>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p><?php echo esc_html__('Waitlist conversion data is unavailable on this site.', 'oras-tickets'); ?></p>
                <?php endif; ?>
                <?php if (! empty($waitlist_summary['source_note'])) : ?>
                    <p class="oras-board-note"><?php echo esc_html((string) $waitlist_summary['source_note']); ?></p>
                <?php endif; ?>
                <?php if ($waitlist_as_of !== '') : ?>
                    <p class="oras-board-note"><?php echo esc_html__('Waitlist conversion as of: ', 'oras-tickets') . esc_html(self::format_as_of($waitlist_as_of)); ?></p>
                <?php endif; ?>
            </div>

            <div class="oras-board-section">
                <h3>
                    <?php echo esc_html__('Engagement Funnel', 'oras-tickets'); ?>
                    <span class="oras-board-role-pill"><?php echo esc_html__('Board View', 'oras-tickets'); ?></span>
                </h3>
                <?php if (! empty($engagement_summary['available'])) : ?>
                    <table class="oras-board-table" aria-label="<?php echo esc_attr__('Engagement funnel summary', 'oras-tickets'); ?>">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Metric', 'oras-tickets'); ?></th>
                                <th><?php echo esc_html__('Value', 'oras-tickets'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><?php echo esc_html__('Confirmed subscribers', 'oras-tickets'); ?></td><td><?php echo esc_html((string) ((int) ($engagement_summary['subscribed_count'] ?? 0))); ?></td></tr>
                            <tr><td><?php echo esc_html__('Unconfirmed subscribers', 'oras-tickets'); ?></td><td><?php echo esc_html((string) ((int) ($engagement_summary['unconfirmed_count'] ?? 0))); ?></td></tr>
                            <tr><td><?php echo esc_html__('Unsubscribed contacts', 'oras-tickets'); ?></td><td><?php echo esc_html((string) ((int) ($engagement_summary['unsubscribed_count'] ?? 0))); ?></td></tr>
                            <tr><td><?php echo esc_html__('Form submissions (30d)', 'oras-tickets'); ?></td><td><?php echo esc_html((string) ((int) ($engagement_summary['form_submissions_30d'] ?? 0))); ?></td></tr>
                            <tr><td><?php echo esc_html__('Newsletter opens (30d)', 'oras-tickets'); ?></td><td><?php echo esc_html((string) ((int) ($engagement_summary['opens_30d'] ?? 0))); ?></td></tr>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p><?php echo esc_html__('Engagement funnel data is unavailable on this site.', 'oras-tickets'); ?></p>
                <?php endif; ?>
                <?php if (! empty($engagement_summary['source_note'])) : ?>
                    <p class="oras-board-note"><?php echo esc_html((string) $engagement_summary['source_note']); ?></p>
                <?php endif; ?>
                <?php if ($engagement_as_of !== '') : ?>
                    <p class="oras-board-note"><?php echo esc_html__('Engagement funnel as of: ', 'oras-tickets') . esc_html(self::format_as_of($engagement_as_of)); ?></p>
                <?php endif; ?>
            </div>

            <div class="oras-board-section">
                <h3>
                    <?php echo esc_html__('KPI Layer (Executive Signals)', 'oras-tickets'); ?>
                    <span class="oras-board-role-pill"><?php echo esc_html__('Board View', 'oras-tickets'); ?></span>
                </h3>
                <table class="oras-board-table" aria-label="<?php echo esc_attr__('Executive KPI signal layer', 'oras-tickets'); ?>">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Signal', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Value', 'oras-tickets'); ?></th>
                            <th><?php echo esc_html__('Status', 'oras-tickets'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $revenue_diversity_status = 'down';
                        if ($revenue_diversity_score >= 60) {
                            $revenue_diversity_status = 'up';
                        } elseif ($revenue_diversity_score >= 40) {
                            $revenue_diversity_status = 'watch';
                        }

                        $membership_dependency_status = 'down';
                        if ($membership_dependency <= 45) {
                            $membership_dependency_status = 'up';
                        } elseif ($membership_dependency <= 65) {
                            $membership_dependency_status = 'watch';
                        }

                        $waitlist_efficiency_status = 'down';
                        if ($waitlist_efficiency >= 50) {
                            $waitlist_efficiency_status = 'up';
                        } elseif ($waitlist_efficiency >= 30) {
                            $waitlist_efficiency_status = 'watch';
                        }

                        $subscriber_confirmation_status = 'down';
                        if ($subscriber_confirmation >= 60) {
                            $subscriber_confirmation_status = 'up';
                        } elseif ($subscriber_confirmation >= 40) {
                            $subscriber_confirmation_status = 'watch';
                        }

                        $open_to_form_status = 'down';
                        if ($open_to_form_rate >= 40) {
                            $open_to_form_status = 'up';
                        } elseif ($open_to_form_rate >= 20) {
                            $open_to_form_status = 'watch';
                        }

                        $kpi_rows = array(
                            array(
                                'label'  => __('Revenue diversity score', 'oras-tickets'),
                                'value'  => number_format_i18n($revenue_diversity_score, 2) . '%',
                                'status' => $revenue_diversity_status,
                            ),
                            array(
                                'label'  => __('Membership dependency', 'oras-tickets'),
                                'value'  => number_format_i18n($membership_dependency, 2) . '%',
                                'status' => $membership_dependency_status,
                            ),
                            array(
                                'label'  => __('Waitlist promotion efficiency', 'oras-tickets'),
                                'value'  => number_format_i18n($waitlist_efficiency, 2) . '%',
                                'status' => $waitlist_efficiency_status,
                            ),
                            array(
                                'label'  => __('Subscriber confirmation ratio', 'oras-tickets'),
                                'value'  => number_format_i18n($subscriber_confirmation, 2) . '%',
                                'status' => $subscriber_confirmation_status,
                            ),
                            array(
                                'label'  => __('Open-to-form momentum (30d)', 'oras-tickets'),
                                'value'  => number_format_i18n($open_to_form_rate, 2) . '%',
                                'status' => $open_to_form_status,
                            ),
                        );
                        foreach ($kpi_rows as $kpi_row) :
                            $status = sanitize_key((string) $kpi_row['status']);
                            if (! in_array($status, array('up', 'watch', 'down'), true)) {
                                $status = 'watch';
                            }
                        ?>
                            <tr>
                                <td><?php echo esc_html((string) ($kpi_row['label'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) $kpi_row['value']); ?></td>
                                <td><span class="oras-board-chip <?php echo esc_attr($status); ?>"><?php echo esc_html(strtoupper($status)); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="oras-board-note"><?php echo esc_html__('Signal layer uses derived website metrics for board-level direction and does not replace Treasurer closeout reporting.', 'oras-tickets'); ?></p>
            </div>

            <?php if (! $can_view_reconciliation) : ?>
                <p class="oras-board-note">
                    <?php echo esc_html__('Detailed reconciliation and variance review is handled by Treasurer/Admin.', 'oras-tickets'); ?>
                </p>
            <?php endif; ?>

            <?php if ($can_view_reconciliation) : ?>
                <div class="oras-board-recon">
                    <h3>
                        <?php echo esc_html__('QuickBooks Reconciliation', 'oras-tickets'); ?>
                        <span class="oras-board-role-pill"><?php echo esc_html__('Treasurer View', 'oras-tickets'); ?></span>
                    </h3>
                    <div class="oras-board-recon-meta">
                        <?php
                        echo esc_html(
                            sprintf(
                                'Completed orders: %1$d · Mismatches: %2$d · Net variance sum: %3$s',
                                (int) ($reconciliation['order_count_completed'] ?? 0),
                                (int) ($reconciliation['mismatch_count'] ?? 0),
                                self::format_money((float) ($reconciliation['variance_total'] ?? 0.0))
                            )
                        );
                        ?>
                    </div>
                    <?php if (! empty($reconciliation['source_note'])) : ?>
                        <p><em><?php echo esc_html((string) $reconciliation['source_note']); ?></em></p>
                    <?php endif; ?>

                    <?php $top_rows = isset($reconciliation['top_mismatches']) && is_array($reconciliation['top_mismatches']) ? $reconciliation['top_mismatches'] : array(); ?>
                    <?php if (! empty($top_rows)) : ?>
                        <table class="oras-board-table" aria-label="<?php echo esc_attr__('Top reconciliation mismatches', 'oras-tickets'); ?>">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Order', 'oras-tickets'); ?></th>
                                    <th><?php echo esc_html__('Status', 'oras-tickets'); ?></th>
                                    <th><?php echo esc_html__('Site', 'oras-tickets'); ?></th>
                                    <th><?php echo esc_html__('QBO Net', 'oras-tickets'); ?></th>
                                    <th><?php echo esc_html__('Variance', 'oras-tickets'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_rows as $row) : ?>
                                    <tr>
                                        <td><?php echo esc_html('#' . (string) ($row['order_number'] ?? $row['order_id'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string) ($row['sync_status'] ?? '')); ?></td>
                                        <td><?php echo esc_html(self::format_money((float) ($row['line_item_total'] ?? 0.0))); ?></td>
                                        <td><?php echo esc_html(self::format_money((float) ($row['qbo_net_amount'] ?? 0.0))); ?></td>
                                        <td><?php echo esc_html(self::format_money((float) ($row['variance'] ?? 0.0))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p><?php echo esc_html__('No mismatches found for the selected period.', 'oras-tickets'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @return array{after?:string,before?:string}
     */
    private static function get_date_range_from_query(): array
    {
        $after  = isset($_GET['oras_board_after']) ? sanitize_text_field(wp_unslash($_GET['oras_board_after'])) : '';
        $before = isset($_GET['oras_board_before']) ? sanitize_text_field(wp_unslash($_GET['oras_board_before'])) : '';

        $range = array();

        if ($after !== '') {
            $range['after'] = $after . ' 00:00:00';
        }

        if ($before !== '') {
            $range['before'] = $before . ' 23:59:59';
        }

        if (empty($range)) {
            $range['after'] = wp_date('Y-m-d 00:00:00', current_time('timestamp') - (365 * DAY_IN_SECONDS));
        }

        return $range;
    }

    private static function format_money(float $amount): string
    {
        if (function_exists('wc_price')) {
            return wp_strip_all_tags(wc_price($amount));
        }

        return number_format_i18n($amount, 2);
    }

    /**
     * @param array<string,mixed> $summary
     * @param array{after?:string,before?:string} $date_range
    * @return array{gross_sales:float,refunded_amount:float,net_sales:float,source:string,source_note:string,as_of:string}
     */
    private static function resolve_financials(array $summary, array $date_range): array
    {
        $site_gross = (float) ($summary['gross_sales'] ?? 0.0);
        $site_refund = (float) ($summary['refunded_amount'] ?? 0.0);
        $site_net = (float) ($summary['net_sales'] ?? 0.0);

        $qbo_snapshot = self::get_qbo_snapshot($date_range);
        if (! empty($qbo_snapshot['available'])) {
            $qbo_gross = (float) ($qbo_snapshot['gross_sales'] ?? 0.0);
            $qbo_refund = (float) ($qbo_snapshot['refunded_amount'] ?? 0.0);
            $qbo_net = (float) ($qbo_snapshot['net_sales'] ?? 0.0);
            $variance = round($site_net - $qbo_net, 2);

            return array(
                'gross_sales' => $qbo_gross,
                'refunded_amount' => $qbo_refund,
                'net_sales' => $qbo_net,
                'source' => 'quickbooks',
                'source_note' => sprintf(
                    'Financial totals source: QuickBooks (read-only poll). Site vs QBO net variance: %s.',
                    self::format_money($variance)
                ),
                'as_of' => (string) ($qbo_snapshot['as_of'] ?? ''),
            );
        }

        $reason = isset($qbo_snapshot['reason']) ? (string) $qbo_snapshot['reason'] : 'not available';

        return array(
            'gross_sales' => $site_gross,
            'refunded_amount' => $site_refund,
            'net_sales' => $site_net,
            'source' => 'site_fallback',
            'source_note' => sprintf('Financial totals source: website fallback (%s).', $reason),
            'as_of' => (string) ($summary['as_of'] ?? ''),
        );
    }

    /**
     * @param array{after?:string,before?:string} $date_range
     * @return array<string,mixed>
     */
    private static function build_woo_cashflow_summary(array $date_range): array
    {
        $summary = array(
            'gross_sales' => 0.0,
            'refunded_amount' => 0.0,
            'net_sales' => 0.0,
            'orders_count' => 0,
            'items_sold' => 0,
            'ticket_sales' => 0.0,
            'merch_sales' => 0.0,
            'membership_sales' => 0.0,
            'donation_sales' => 0.0,
            'other_sales' => 0.0,
            'as_of' => gmdate('c'),
        );

        if (! function_exists('wc_get_orders')) {
            return $summary;
        }

        $settings = class_exists(Settings::class) ? Settings::get_quickbooks_settings() : array();
        $merch_slugs = class_exists(Settings::class)
            ? Settings::parse_slug_list((string) ($settings['merch_category_slugs'] ?? 'merch,merchandise,shirt,shirts,apparel'))
            : array('merch', 'merchandise', 'shirt', 'shirts', 'apparel');
        $printful_slugs = class_exists(Settings::class)
            ? Settings::parse_slug_list((string) ($settings['printful_category_slugs'] ?? 'printful,pod'))
            : array('printful', 'pod');
        $donation_slugs = class_exists(Settings::class)
            ? Settings::parse_slug_list((string) ($settings['donation_category_slugs'] ?? 'donation,donations,give,giving'))
            : array('donation', 'donations', 'give', 'giving');

        $membership_slugs = array('membership', 'memberships', 'member', 'members', 'pmpro');
        $statuses = array('processing', 'completed');
        $page = 1;
        $per_page = 50;

        do {
            $args = array(
                'limit' => $per_page,
                'page' => $page,
                'status' => $statuses,
                'orderby' => 'date',
                'order' => 'DESC',
            );

            $date_created = self::build_date_created_arg($date_range);
            if ($date_created !== '') {
                $args['date_created'] = $date_created;
            }

            $orders = wc_get_orders($args);
            if (! is_array($orders) || empty($orders)) {
                break;
            }

            foreach ($orders as $order) {
                if (! $order instanceof \WC_Order) {
                    continue;
                }

                $summary['orders_count']++;

                foreach ($order->get_items('line_item') as $item) {
                    if (! $item instanceof \WC_Order_Item_Product) {
                        continue;
                    }

                    $line_total = round((float) $item->get_total(), 2);
                    $qty = max(0, (int) $item->get_quantity());
                    if (abs($line_total) < 0.0001) {
                        continue;
                    }

                    $summary['gross_sales'] += $line_total;
                    $summary['items_sold'] += $qty;

                    $stream = self::classify_revenue_stream($item, $merch_slugs, $printful_slugs, $donation_slugs, $membership_slugs);
                    if ($stream === 'ticket') {
                        $summary['ticket_sales'] += $line_total;
                        continue;
                    }
                    if ($stream === 'merch') {
                        $summary['merch_sales'] += $line_total;
                        continue;
                    }
                    if ($stream === 'membership') {
                        $summary['membership_sales'] += $line_total;
                        continue;
                    }
                    if ($stream === 'donation') {
                        $summary['donation_sales'] += $line_total;
                        continue;
                    }

                    $summary['other_sales'] += $line_total;
                }

                foreach ($order->get_refunds() as $refund) {
                    if (! $refund instanceof \WC_Order_Refund) {
                        continue;
                    }

                    $summary['refunded_amount'] += abs((float) $refund->get_total());
                }
            }

            $count = count($orders);
            $page++;
        } while ($count === $per_page);

        $summary['gross_sales'] = round((float) $summary['gross_sales'], 2);
        $summary['refunded_amount'] = round((float) $summary['refunded_amount'], 2);
        $summary['ticket_sales'] = round((float) $summary['ticket_sales'], 2);
        $summary['merch_sales'] = round((float) $summary['merch_sales'], 2);
        $summary['membership_sales'] = round((float) $summary['membership_sales'], 2);
        $summary['donation_sales'] = round((float) $summary['donation_sales'], 2);
        $summary['other_sales'] = round((float) $summary['other_sales'], 2);
        $summary['net_sales'] = round((float) $summary['gross_sales'] - (float) $summary['refunded_amount'], 2);

        return $summary;
    }

    /**
     * @param \WC_Order_Item_Product $item
     * @param string[] $merch_slugs
     * @param string[] $printful_slugs
     * @param string[] $donation_slugs
     * @param string[] $membership_slugs
     */
    private static function classify_revenue_stream($item, array $merch_slugs, array $printful_slugs, array $donation_slugs, array $membership_slugs): string
    {
        $event_id = (int) $item->get_meta('_oras_ticket_event_id', true);
        $ticket_name = trim((string) $item->get_meta('_oras_ticket_name', true));
        if ($event_id > 0 || $ticket_name !== '') {
            return 'ticket';
        }

        $product_id = method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0;
        $product_name = sanitize_title((string) $item->get_name());
        $category_slugs = array();

        if ($product_id > 0) {
            $terms = get_the_terms($product_id, 'product_cat');
            if (is_array($terms)) {
                foreach ($terms as $term) {
                    if (is_object($term)) {
                        $category_slugs[] = sanitize_title((string) $term->slug);
                    }
                }
            }
        }

        if (! empty(array_intersect($category_slugs, $membership_slugs)) || strpos($product_name, 'membership') !== false || strpos($product_name, 'member') !== false) {
            return 'membership';
        }

        if (! empty(array_intersect($category_slugs, $printful_slugs)) || strpos($product_name, 'printful') !== false) {
            return 'merch';
        }

        if (! empty(array_intersect($category_slugs, $merch_slugs)) || strpos($product_name, 'merch') !== false || strpos($product_name, 'apparel') !== false || strpos($product_name, 'shirt') !== false) {
            return 'merch';
        }

        if (! empty(array_intersect($category_slugs, $donation_slugs)) || strpos($product_name, 'donation') !== false || strpos($product_name, 'contribution') !== false || strpos($product_name, 'give') !== false) {
            return 'donation';
        }

        return 'other';
    }

    /**
     * @return array<string,mixed>
     */
    private static function build_pmpro_lifecycle_summary(): array
    {
        global $wpdb;

        $result = array(
            'available' => false,
            'periods' => array(),
            'levels' => array(),
            'as_of' => gmdate('c'),
        );

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            return $result;
        }

        $table = $wpdb->prefix . 'pmpro_memberships_users';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (! is_string($table_exists) || $table_exists === '') {
            return $result;
        }

        $periods = array(
            'weekly' => array('label' => 'Weekly (7d)', 'days' => 7),
            'monthly' => array('label' => 'Monthly (30d)', 'days' => 30),
            'yearly' => array('label' => 'Yearly (365d)', 'days' => 365),
        );
        $now = gmdate('Y-m-d H:i:s');

        foreach ($periods as $period_key => $period) {
            $days = (int) $period['days'];

            $start = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

            $signups_sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE startdate IS NOT NULL AND startdate <> '0000-00-00 00:00:00' AND startdate >= %s AND startdate <= %s",
                $start,
                $now
            );
            $signups = (int) $wpdb->get_var($signups_sql);

            $result['periods'][ $period_key ] = array(
                'label' => (string) $period['label'],
                'signups' => $signups,
            );
        }

        $levels_table = $wpdb->prefix . 'pmpro_membership_levels';
        $levels_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $levels_table));
        $membership_levels = array();
        if (is_string($levels_exists) && $levels_exists !== '') {
            $level_rows = $wpdb->get_results(
                "SELECT COALESCE(ml.name, CONCAT('Level #', mu.membership_id)) AS level_name, COUNT(DISTINCT mu.user_id) AS member_count
                FROM {$table} mu
                LEFT JOIN {$levels_table} ml ON ml.id = mu.membership_id
                WHERE mu.status = 'active'
                GROUP BY mu.membership_id, ml.name
                ORDER BY member_count DESC, level_name ASC",
                'ARRAY_A'
            );
            if (is_array($level_rows)) {
                foreach ($level_rows as $level_row) {
                    if (! is_array($level_row)) {
                        continue;
                    }
                    $membership_levels[] = array(
                        'level_name' => (string) ($level_row['level_name'] ?? ''),
                        'member_count' => (int) ($level_row['member_count'] ?? 0),
                    );
                }
            }
        }
        $result['levels'] = $membership_levels;

        $result['available'] = true;
        return $result;
    }

    /**
     * @param array{after?:string,before?:string} $date_range
     * @return array{membership_sales:float,source_note:string}
     */
    private static function build_pmpro_cashflow_summary(array $date_range): array
    {
        global $wpdb;

        $result = array(
            'membership_sales' => 0.0,
            'source_note' => 'PMPro direct cashflow unavailable.',
            'as_of' => gmdate('c'),
        );

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            return $result;
        }

        $table = $wpdb->prefix . 'pmpro_membership_orders';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (! is_string($table_exists) || $table_exists === '') {
            return $result;
        }

        $after = isset($date_range['after']) ? self::extract_date_only((string) $date_range['after']) : '';
        $before = isset($date_range['before']) ? self::extract_date_only((string) $date_range['before']) : '';

        if ($after === '') {
            $after = gmdate('Y-m-d', time() - (365 * DAY_IN_SECONDS));
        }
        if ($before === '') {
            $before = gmdate('Y-m-d');
        }

        $from_dt = $after . ' 00:00:00';
        $to_dt = $before . ' 23:59:59';

        $query = $wpdb->prepare(
            "SELECT COALESCE(SUM(total), 0) FROM {$table} WHERE status = %s AND timestamp >= %s AND timestamp <= %s",
            'success',
            $from_dt,
            $to_dt
        );

        $result['membership_sales'] = round((float) $wpdb->get_var($query), 2);
        $result['source_note'] = 'Direct membership cashflow includes successful membership orders in selected range and may overlap with Woo-based membership products depending on checkout configuration.';

        return $result;
    }

    /**
     * @param string $user_login
     * @param \WP_User $user
     */
    public static function record_login_event($user_login, $user): void
    {
        $counts = get_option(self::LOGIN_DAILY_OPTION, array());
        if (! is_array($counts)) {
            $counts = array();
        }

        $today = gmdate('Y-m-d');
        $counts[$today] = (int) ($counts[$today] ?? 0) + 1;

        $cutoff_ts = time() - (400 * DAY_IN_SECONDS);
        foreach ($counts as $day => $count) {
            $day_ts = strtotime((string) $day . ' 00:00:00 UTC');
            if ($day_ts !== false && $day_ts < $cutoff_ts) {
                unset($counts[$day]);
            }
        }

        update_option(self::LOGIN_DAILY_OPTION, $counts, false);

        if ($user instanceof \WP_User) {
            update_user_meta((int) $user->ID, '_oras_last_login_at', gmdate('Y-m-d H:i:s'));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function build_website_activity_summary(): array
    {
        global $wpdb;

        $period_defs = array(
            'weekly' => array('label' => 'Weekly (7d)', 'days' => 7),
            'monthly' => array('label' => 'Monthly (30d)', 'days' => 30),
            'yearly' => array('label' => 'Yearly (365d)', 'days' => 365),
        );

        $summary = array(
            'periods' => array(),
            'total_users' => 0,
            'active_members' => 0,
            'source_note' => '',
            'as_of' => gmdate('c'),
        );

        $login_daily = get_option(self::LOGIN_DAILY_OPTION, array());
        if (! is_array($login_daily)) {
            $login_daily = array();
        }

        $now = gmdate('Y-m-d H:i:s');
        foreach ($period_defs as $period_key => $period_def) {
            $days = (int) $period_def['days'];
            $label = (string) $period_def['label'];
            $signups = 0;
            $logins = 0;

            $start_ts = time() - ($days * DAY_IN_SECONDS);
            $start_dt = gmdate('Y-m-d H:i:s', $start_ts);

            foreach ($login_daily as $day => $count) {
                $day_ts = strtotime((string) $day . ' 00:00:00 UTC');
                if ($day_ts !== false && $day_ts >= $start_ts) {
                    $logins += (int) $count;
                }
            }

            if (isset($wpdb) && $wpdb instanceof \wpdb) {
                $users_table = $wpdb->users;
                $signup_sql = $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$users_table} WHERE user_registered >= %s AND user_registered <= %s",
                    $start_dt,
                    $now
                );
                $signups = (int) $wpdb->get_var($signup_sql);
            }

            $summary['periods'][$period_key] = array(
                'label' => $label,
                'logins' => $logins,
                'signups' => $signups,
            );
        }

        if (function_exists('count_users')) {
            $totals = count_users();
            if (is_array($totals) && isset($totals['total_users'])) {
                $summary['total_users'] = (int) $totals['total_users'];
            }
        }

        if (isset($wpdb) && $wpdb instanceof \wpdb) {
            $memberships_table = $wpdb->prefix . 'pmpro_memberships_users';
            $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $memberships_table));
            if (is_string($table_exists) && $table_exists !== '') {
                $active_sql = "SELECT COUNT(DISTINCT user_id) FROM {$memberships_table} WHERE status = 'active'";
                $summary['active_members'] = (int) $wpdb->get_var($active_sql);
            }
        }

        if (empty($login_daily)) {
            $summary['source_note'] = 'Login counts are tracked from the time this dashboard tracking was enabled.';
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private static function build_operations_health_summary(): array
    {
        global $wpdb;

        $summary = array(
            'available' => false,
            'failed_total' => 0,
            'pending_total' => 0,
            'complete_total' => 0,
            'failed_7d' => 0,
            'pending_7d' => 0,
            'top_failed_hooks' => array(),
            'source_note' => '',
            'as_of' => gmdate('c'),
        );

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            return $summary;
        }

        $actions_table = $wpdb->prefix . 'actionscheduler_actions';
        if (! self::table_exists($actions_table)) {
            return $summary;
        }

        $status_rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS c FROM {$actions_table} GROUP BY status",
            'ARRAY_A'
        );
        if (is_array($status_rows)) {
            foreach ($status_rows as $status_row) {
                if (! is_array($status_row)) {
                    continue;
                }

                $status = sanitize_key((string) ($status_row['status'] ?? ''));
                $count = (int) ($status_row['c'] ?? 0);
                if ($status === 'failed') {
                    $summary['failed_total'] = $count;
                    continue;
                }
                if ($status === 'pending') {
                    $summary['pending_total'] = $count;
                    continue;
                }
                if ($status === 'complete') {
                    $summary['complete_total'] = $count;
                }
            }
        }

        $recent_since = gmdate('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS));
        $recent_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) AS c FROM {$actions_table} WHERE scheduled_date_gmt >= %s GROUP BY status",
                $recent_since
            ),
            'ARRAY_A'
        );
        if (is_array($recent_rows)) {
            foreach ($recent_rows as $recent_row) {
                if (! is_array($recent_row)) {
                    continue;
                }

                $status = sanitize_key((string) ($recent_row['status'] ?? ''));
                $count = (int) ($recent_row['c'] ?? 0);
                if ($status === 'failed') {
                    $summary['failed_7d'] = $count;
                    continue;
                }
                if ($status === 'pending') {
                    $summary['pending_7d'] = $count;
                }
            }
        }

        $failed_hooks = $wpdb->get_results(
            "SELECT hook, COUNT(*) AS c FROM {$actions_table} WHERE status = 'failed' GROUP BY hook ORDER BY c DESC LIMIT 3",
            'ARRAY_A'
        );
        if (is_array($failed_hooks)) {
            foreach ($failed_hooks as $failed_hook) {
                if (! is_array($failed_hook)) {
                    continue;
                }

                $hook_name = (string) ($failed_hook['hook'] ?? '');
                $summary['top_failed_hooks'][] = array(
                    'hook' => $hook_name === '' ? __('Unknown hook', 'oras-tickets') : $hook_name,
                    'count' => (int) ($failed_hook['c'] ?? 0),
                );
            }
        }

        $summary['available'] = true;
        $summary['source_note'] = 'Operations health summarizes Action Scheduler status and recent queue pressure.';

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private static function build_waitlist_conversion_summary(): array
    {
        global $wpdb;

        $summary = array(
            'available' => false,
            'waiting_count' => 0,
            'promoted_count' => 0,
            'left_count' => 0,
            'promotion_efficiency' => 0.0,
            'source_note' => '',
            'as_of' => gmdate('c'),
        );

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            return $summary;
        }

        $waitlist_table = $wpdb->prefix . 'oras_ticket_waitlist';
        if (! self::table_exists($waitlist_table)) {
            return $summary;
        }

        $status_rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS c FROM {$waitlist_table} GROUP BY status",
            'ARRAY_A'
        );

        if (is_array($status_rows)) {
            foreach ($status_rows as $status_row) {
                if (! is_array($status_row)) {
                    continue;
                }

                $status = sanitize_key((string) ($status_row['status'] ?? ''));
                $count = (int) ($status_row['c'] ?? 0);
                if ($status === 'waiting') {
                    $summary['waiting_count'] = $count;
                    continue;
                }
                if ($status === 'promoted') {
                    $summary['promoted_count'] = $count;
                    continue;
                }
                if ($status === 'left' || $status === 'removed') {
                    $summary['left_count'] += $count;
                }
            }
        }

        $handled = (int) $summary['promoted_count'] + (int) $summary['left_count'];
        $summary['promotion_efficiency'] = $handled > 0
            ? (((int) $summary['promoted_count'] / $handled) * 100)
            : 0.0;

        $summary['available'] = true;
        $summary['source_note'] = 'Promotion efficiency uses promoted vs. exited waitlist entries over available history.';

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private static function build_engagement_funnel_summary(): array
    {
        global $wpdb;

        $summary = array(
            'available' => false,
            'subscribed_count' => 0,
            'unconfirmed_count' => 0,
            'unsubscribed_count' => 0,
            'form_submissions_30d' => 0,
            'opens_30d' => 0,
            'source_note' => '',
            'as_of' => gmdate('c'),
        );

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            return $summary;
        }

        $subscriber_table = $wpdb->prefix . 'mailpoet_subscribers';
        if (! self::table_exists($subscriber_table)) {
            return $summary;
        }

        $subscriber_rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS c FROM {$subscriber_table} GROUP BY status",
            'ARRAY_A'
        );
        if (is_array($subscriber_rows)) {
            foreach ($subscriber_rows as $subscriber_row) {
                if (! is_array($subscriber_row)) {
                    continue;
                }

                $status = sanitize_key((string) ($subscriber_row['status'] ?? ''));
                $count = (int) ($subscriber_row['c'] ?? 0);
                if ($status === 'subscribed') {
                    $summary['subscribed_count'] = $count;
                    continue;
                }
                if ($status === 'unconfirmed') {
                    $summary['unconfirmed_count'] = $count;
                    continue;
                }
                if ($status === 'unsubscribed') {
                    $summary['unsubscribed_count'] = $count;
                }
            }
        }

        $form_submission_table = $wpdb->prefix . 'mailpoet_form_submissions';
        if (self::table_exists($form_submission_table) && self::table_has_column($form_submission_table, 'created_at')) {
            $since_30d = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));
            $summary['form_submissions_30d'] = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$form_submission_table} WHERE created_at >= %s",
                    $since_30d
                )
            );
        }

        $open_table = $wpdb->prefix . 'mailpoet_statistics_opens';
        if (self::table_exists($open_table) && self::table_has_column($open_table, 'created_at')) {
            $since_30d = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));
            $summary['opens_30d'] = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$open_table} WHERE created_at >= %s",
                    $since_30d
                )
            );
        }

        $summary['available'] = true;
        $summary['source_note'] = 'Engagement funnel uses MailPoet subscriber and campaign interaction data.';

        return $summary;
    }

    private static function table_exists(string $table_name): bool
    {
        global $wpdb;
        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            return false;
        }

        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        return is_string($table_exists) && $table_exists !== '';
    }

    private static function table_has_column(string $table_name, string $column_name): bool
    {
        global $wpdb;
        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            return false;
        }

        if (! self::table_exists($table_name)) {
            return false;
        }

        $column_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM {$table_name} LIKE %s",
                $column_name
            )
        );

        return is_string($column_exists) && $column_exists !== '';
    }

    /**
     * @param array<string,mixed> $operations_health
     * @param array<string,mixed> $waitlist_summary
     * @param array<string,mixed> $engagement_summary
     * @return array<int,array{tone:string,text:string}>
     */
    private static function build_watch_alerts(array $operations_health, array $waitlist_summary, array $engagement_summary): array
    {
        $alerts = array();

        $failed_total = (int) ($operations_health['failed_total'] ?? 0);
        $pending_total = (int) ($operations_health['pending_total'] ?? 0);
        if ($failed_total >= 100) {
            $alerts[] = array(
                'tone' => 'down',
                'text' => sprintf('Automation failures are elevated (%d failed jobs).', $failed_total),
            );
        }
        if ($pending_total >= 50) {
            $alerts[] = array(
                'tone' => 'watch',
                'text' => sprintf('Pending automation queue is elevated (%d pending jobs).', $pending_total),
            );
        }

        $waiting_count = (int) ($waitlist_summary['waiting_count'] ?? 0);
        $promotion_efficiency = (float) ($waitlist_summary['promotion_efficiency'] ?? 0.0);
        $handled_waitlist = (int) ($waitlist_summary['promoted_count'] ?? 0) + (int) ($waitlist_summary['left_count'] ?? 0);
        if ($waiting_count >= 5) {
            $alerts[] = array(
                'tone' => 'watch',
                'text' => sprintf('Waitlist pressure is building (%d currently waiting).', $waiting_count),
            );
        }
        if ($handled_waitlist >= 5 && $promotion_efficiency < 70.0) {
            $alerts[] = array(
                'tone' => 'down',
                'text' => sprintf('Waitlist promotion efficiency is below target (%s%%).', number_format_i18n($promotion_efficiency, 2)),
            );
        }

        $subscribed_count = (int) ($engagement_summary['subscribed_count'] ?? 0);
        $unconfirmed_count = (int) ($engagement_summary['unconfirmed_count'] ?? 0);
        if ($unconfirmed_count >= 20 && $unconfirmed_count > $subscribed_count) {
            $alerts[] = array(
                'tone' => 'watch',
                'text' => sprintf('Subscriber confirmation backlog is high (%d unconfirmed).', $unconfirmed_count),
            );
        }

        return array_slice($alerts, 0, 4);
    }

    /**
     * @param array{after?:string,before?:string} $date_range
     * @param array<string,mixed> $cashflow
     * @param array<string,mixed> $pmpro_cashflow
     * @param array<string,mixed> $website_activity
    * @return array<int,array{tone:string,text:string}>
     */
    private static function build_notable_changes(array $date_range, array $cashflow, array $pmpro_cashflow, array $website_activity): array
    {
        $previous_range = self::get_previous_period_date_range($date_range);
        $previous_cashflow = self::build_woo_cashflow_summary($previous_range);
        $previous_pmpro = self::build_pmpro_cashflow_summary($previous_range);

        $curr_gross = (float) ($cashflow['gross_sales'] ?? 0.0);
        $prev_gross = (float) ($previous_cashflow['gross_sales'] ?? 0.0);

        $curr_refund_rate = self::safe_ratio_percent((float) ($cashflow['refunded_amount'] ?? 0.0), $curr_gross);
        $prev_refund_rate = self::safe_ratio_percent((float) ($previous_cashflow['refunded_amount'] ?? 0.0), $prev_gross);

        $curr_merch = (float) ($cashflow['merch_sales'] ?? 0.0);
        $prev_merch = (float) ($previous_cashflow['merch_sales'] ?? 0.0);

        $curr_membership_cashflow = (float) ($pmpro_cashflow['membership_sales'] ?? 0.0);
        $prev_membership_cashflow = (float) ($previous_pmpro['membership_sales'] ?? 0.0);

        $curr_logins = self::count_logins_in_range($date_range);
        $prev_logins = self::count_logins_in_range($previous_range);

        $curr_signups = self::count_user_signups_in_range($date_range);
        $prev_signups = self::count_user_signups_in_range($previous_range);

        $gross_delta = $curr_gross - $prev_gross;
        $refund_delta = $curr_refund_rate - $prev_refund_rate;
        $merch_delta = $curr_merch - $prev_merch;
        $membership_delta = $curr_membership_cashflow - $prev_membership_cashflow;
        $activity_delta = ($curr_logins - $prev_logins) + ($curr_signups - $prev_signups);

        $changes = array(
            array(
                'tone' => self::tone_from_delta($gross_delta, false),
                'text' => sprintf('Gross sales %s vs prior period (%s → %s).', self::format_signed_money_delta($gross_delta), self::format_money($prev_gross), self::format_money($curr_gross)),
            ),
            array(
                'tone' => self::tone_from_delta($refund_delta, true),
                'text' => sprintf('Refund rate %s points (%s → %s).', self::format_signed_number($refund_delta, 2), number_format_i18n($prev_refund_rate, 2) . '%', number_format_i18n($curr_refund_rate, 2) . '%'),
            ),
            array(
                'tone' => self::tone_from_delta($merch_delta, false),
                'text' => sprintf('Merch revenue %s (%s → %s).', self::format_signed_money_delta($merch_delta), self::format_money($prev_merch), self::format_money($curr_merch)),
            ),
            array(
                'tone' => self::tone_from_delta($membership_delta, false),
                'text' => sprintf('Direct membership cashflow %s (%s → %s).', self::format_signed_money_delta($membership_delta), self::format_money($prev_membership_cashflow), self::format_money($curr_membership_cashflow)),
            ),
            array(
                'tone' => self::tone_from_delta((float) $activity_delta, false),
                'text' => sprintf('Website activity: logins %s (%d → %d), signups %s (%d → %d).', self::format_signed_int($curr_logins - $prev_logins), $prev_logins, $curr_logins, self::format_signed_int($curr_signups - $prev_signups), $prev_signups, $curr_signups),
            ),
        );

        return array_slice($changes, 0, 5);
    }

    private static function tone_from_delta(float $delta, bool $inverse = false): string
    {
        $epsilon = 0.009;
        if (abs($delta) <= $epsilon) {
            return 'watch';
        }

        if ($inverse) {
            return $delta < 0 ? 'up' : 'down';
        }

        return $delta > 0 ? 'up' : 'down';
    }

    /**
     * @param array{after?:string,before?:string} $date_range
     * @return array{after?:string,before?:string}
     */
    private static function get_previous_period_date_range(array $date_range): array
    {
        $after_raw = isset($date_range['after']) ? (string) $date_range['after'] : '';
        $before_raw = isset($date_range['before']) ? (string) $date_range['before'] : '';

        $after_ts = strtotime($after_raw);
        $before_ts = strtotime($before_raw);

        if ($after_ts === false || $before_ts === false || $before_ts <= $after_ts) {
            $before_ts = current_time('timestamp');
            $after_ts = $before_ts - (30 * DAY_IN_SECONDS);
        }

        $span = max(DAY_IN_SECONDS, $before_ts - $after_ts);
        $prev_before = $after_ts - 1;
        $prev_after = $prev_before - $span;

        return array(
            'after' => gmdate('Y-m-d H:i:s', $prev_after),
            'before' => gmdate('Y-m-d H:i:s', $prev_before),
        );
    }

    /**
     * @param array{after?:string,before?:string} $date_range
     */
    private static function count_logins_in_range(array $date_range): int
    {
        $counts = get_option(self::LOGIN_DAILY_OPTION, array());
        if (! is_array($counts)) {
            return 0;
        }

        $after_raw = isset($date_range['after']) ? (string) $date_range['after'] : '';
        $before_raw = isset($date_range['before']) ? (string) $date_range['before'] : '';
        $after_ts = $after_raw !== '' ? strtotime($after_raw) : false;
        $before_ts = $before_raw !== '' ? strtotime($before_raw) : false;

        $sum = 0;
        foreach ($counts as $day => $count) {
            $day_ts = strtotime((string) $day . ' 00:00:00 UTC');
            if ($day_ts === false) {
                continue;
            }

            if ($after_ts !== false && $day_ts < $after_ts) {
                continue;
            }
            if ($before_ts !== false && $day_ts > $before_ts) {
                continue;
            }

            $sum += (int) $count;
        }

        return $sum;
    }

    /**
     * @param array{after?:string,before?:string} $date_range
     */
    private static function count_user_signups_in_range(array $date_range): int
    {
        global $wpdb;
        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            return 0;
        }

        $after_raw = isset($date_range['after']) ? (string) $date_range['after'] : '';
        $before_raw = isset($date_range['before']) ? (string) $date_range['before'] : '';
        if ($after_raw === '' || $before_raw === '') {
            return 0;
        }

        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= %s AND user_registered <= %s",
            $after_raw,
            $before_raw
        );

        return (int) $wpdb->get_var($query);
    }

    private static function safe_ratio_percent(float $numerator, float $denominator): float
    {
        if ($denominator <= 0.0) {
            return 0.0;
        }

        return ($numerator / $denominator) * 100;
    }

    private static function format_signed_money_delta(float $delta): string
    {
        $prefix = $delta >= 0 ? '+' : '-';
        return $prefix . self::format_money(abs($delta));
    }

    private static function format_signed_number(float $delta, int $decimals = 2): string
    {
        $prefix = $delta >= 0 ? '+' : '-';
        return $prefix . number_format_i18n(abs($delta), $decimals);
    }

    private static function format_signed_int(int $delta): string
    {
        return ($delta >= 0 ? '+' : '-') . (string) abs($delta);
    }

    /**
     * @param array{after?:string,before?:string} $date_range
     */
    private static function build_date_created_arg(array $date_range): string
    {
        $after = isset($date_range['after']) ? (string) $date_range['after'] : '';
        $before = isset($date_range['before']) ? (string) $date_range['before'] : '';

        if ($after !== '' && $before !== '') {
            return $after . '...' . $before;
        }

        if ($after !== '') {
            return '>=' . $after;
        }

        if ($before !== '') {
            return '<=' . $before;
        }

        return '';
    }

    /**
     * Read-only QuickBooks snapshot for ORAS JournalEntry totals.
     *
     * @param array{after?:string,before?:string} $date_range
    * @return array{available:bool,gross_sales?:float,refunded_amount?:float,net_sales?:float,reason?:string,as_of?:string}
     */
    private static function get_qbo_snapshot(array $date_range): array
    {
        if (! class_exists(Settings::class) || ! class_exists(Api_Client::class)) {
            return array('available' => false, 'reason' => 'qbo module unavailable');
        }

        if (! Settings::is_enabled()) {
            return array('available' => false, 'reason' => 'qbo disabled');
        }

        $from = self::extract_date_only((string) ($date_range['after'] ?? ''));
        $to = self::extract_date_only((string) ($date_range['before'] ?? ''));

        if ($from === '') {
            $from = gmdate('Y-m-d', time() - (365 * DAY_IN_SECONDS));
        }
        if ($to === '') {
            $to = gmdate('Y-m-d');
        }

        $cache_key = 'oras_board_qbo_snapshot_' . md5($from . '|' . $to);
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['available'])) {
            return $cached;
        }

        $api_client = new Api_Client();
        $page_size = 100;
        $start_position = 1;
        $max_rows = 2000;
        $collected = 0;
        $gross = 0.0;
        $refunded = 0.0;

        while ($collected < $max_rows) {
            $query = sprintf(
                "SELECT Id, DocNumber, TxnDate, Line FROM JournalEntry WHERE TxnDate >= '%s' AND TxnDate <= '%s' AND (DocNumber LIKE 'ORAS-WO-%%' OR DocNumber LIKE 'ORAS-RC-%%' OR DocNumber LIKE 'ORAS-RV-%%') ORDER BY TxnDate DESC STARTPOSITION %d MAXRESULTS %d",
                $from,
                $to,
                $start_position,
                $page_size
            );

            $response = $api_client->run_query($query);
            if (is_wp_error($response)) {
                $result = array(
                    'available' => false,
                    'reason' => 'qbo query error',
                    'as_of' => gmdate('c'),
                );
                set_transient($cache_key, $result, 2 * MINUTE_IN_SECONDS);
                return $result;
            }

            $entries = isset($response['QueryResponse']['JournalEntry']) && is_array($response['QueryResponse']['JournalEntry'])
                ? $response['QueryResponse']['JournalEntry']
                : array();

            if (empty($entries)) {
                break;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $doc_number = isset($entry['DocNumber']) ? (string) $entry['DocNumber'] : '';
                $split_total = self::extract_journal_entry_total($entry);

                if (strpos($doc_number, 'ORAS-RV-') === 0) {
                    $refunded += $split_total;
                    continue;
                }

                if (strpos($doc_number, 'ORAS-WO-') === 0 || strpos($doc_number, 'ORAS-RC-') === 0) {
                    $gross += $split_total;
                }
            }

            $row_count = count($entries);
            $collected += $row_count;
            if ($row_count < $page_size) {
                break;
            }

            $start_position += $page_size;
        }

        $result = array(
            'available' => true,
            'gross_sales' => round($gross, 2),
            'refunded_amount' => round($refunded, 2),
            'net_sales' => round($gross - $refunded, 2),
            'as_of' => gmdate('c'),
        );

        set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);
        return $result;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function extract_journal_entry_total(array $entry): float
    {
        $lines = isset($entry['Line']) && is_array($entry['Line']) ? $entry['Line'] : array();
        $debits = 0.0;
        $credits = 0.0;

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $amount = round((float) ($line['Amount'] ?? 0.0), 2);
            $detail = isset($line['JournalEntryLineDetail']) && is_array($line['JournalEntryLineDetail'])
                ? $line['JournalEntryLineDetail']
                : array();
            $posting_type = isset($detail['PostingType']) ? (string) $detail['PostingType'] : '';

            if ($posting_type === 'Debit') {
                $debits += $amount;
                continue;
            }

            if ($posting_type === 'Credit') {
                $credits += $amount;
            }
        }

        return round(max($debits, $credits), 2);
    }

    private static function extract_date_only(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches) === 1) {
            return (string) $matches[0];
        }

        return '';
    }

    private static function format_as_of(string $iso): string
    {
        $iso = trim($iso);
        if ($iso === '') {
            return '';
        }

        $timestamp = strtotime($iso);
        if ($timestamp === false) {
            return $iso;
        }

        return wp_date('Y-m-d H:i T', $timestamp);
    }

    /**
     * @param array{after?:string,before?:string} $date_range
     * @return array{order_count_completed:int,mismatch_count:int,variance_total:float,top_mismatches:array<int,array<string,mixed>>,source_note:string}
     */
    private static function build_reconciliation_detail(array $date_range, int $top_limit = 8): array
    {
        $result = array(
            'order_count_completed' => 0,
            'mismatch_count' => 0,
            'variance_total' => 0.0,
            'top_mismatches' => array(),
            'source_note' => 'Reconciliation compares website line-item totals to ORAS QuickBooks sync snapshots (read-only).',
        );

        if (! function_exists('wc_get_orders')) {
            $result['source_note'] = 'WooCommerce order API unavailable for reconciliation.';
            return $result;
        }

        $from = self::extract_date_only((string) ($date_range['after'] ?? ''));
        $to = self::extract_date_only((string) ($date_range['before'] ?? ''));
        if ($from === '') {
            $from = gmdate('Y-m-d', time() - (30 * DAY_IN_SECONDS));
        }
        if ($to === '') {
            $to = gmdate('Y-m-d');
        }

        $order_ids = wc_get_orders(
            array(
                'type' => 'shop_order',
                'status' => array('completed'),
                'limit' => -1,
                'return' => 'ids',
                'date_created' => $from . '...' . $to,
            )
        );

        if (! is_array($order_ids) || empty($order_ids)) {
            return $result;
        }

        $mismatches = array();
        $variance_total = 0.0;

        foreach ($order_ids as $order_id) {
            if ($order_id instanceof \WC_Order) {
                $order = $order_id;
            } elseif (is_scalar($order_id)) {
                $order = wc_get_order((int) $order_id);
            } else {
                continue;
            }

            if (! $order instanceof \WC_Order) {
                continue;
            }

            $result['order_count_completed']++;

            $line_item_total = self::calculate_order_line_item_total($order);
            $sync_status = (string) $order->get_meta('_oras_qbo_sync_status', true);
            $je_id = trim((string) $order->get_meta('_oras_qbo_je_id', true));
            $reversal_je_id = trim((string) $order->get_meta('_oras_qbo_reversal_je_id', true));

            $snapshot_info = self::get_order_snapshot_total($order, $line_item_total, $je_id !== '');
            $snapshot_total = (float) ($snapshot_info['amount'] ?? 0.0);

            $qbo_net_amount = ($je_id !== '' ? $snapshot_total : 0.0) - ($reversal_je_id !== '' ? $snapshot_total : 0.0);
            $variance = round($line_item_total - $qbo_net_amount, 2);

            if (abs($variance) <= 0.009) {
                continue;
            }

            $variance_total += $variance;
            $mismatches[] = array(
                'order_id' => (int) $order->get_id(),
                'order_number' => (string) $order->get_order_number(),
                'sync_status' => $sync_status,
                'line_item_total' => round($line_item_total, 2),
                'qbo_net_amount' => round($qbo_net_amount, 2),
                'variance' => $variance,
            );
        }

        usort(
            $mismatches,
            static function (array $left, array $right): int {
                $left_abs = abs((float) $left['variance']);
                $right_abs = abs((float) $right['variance']);
                if ($left_abs === $right_abs) {
                    return (int) $left['order_id'] <=> (int) $right['order_id'];
                }

                return $right_abs <=> $left_abs;
            }
        );

        $result['mismatch_count'] = count($mismatches);
        $result['variance_total'] = round($variance_total, 2);
        $result['top_mismatches'] = array_slice($mismatches, 0, max(1, $top_limit));

        return $result;
    }

    /**
     * @param \WC_Order $order
     */
    private static function calculate_order_line_item_total($order): float
    {
        $total = 0.0;
        foreach ($order->get_items('line_item') as $item) {
            if (! $item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $total += round((float) $item->get_total(), 2);
        }

        return round($total, 2);
    }

    /**
     * @param \WC_Order $order
     * @return array{amount:float,source:string}
     */
    private static function get_order_snapshot_total($order, float $line_item_total, bool $has_je): array
    {
        $snapshot_raw = (string) $order->get_meta('_oras_qbo_split_snapshot', true);
        $snapshot = json_decode($snapshot_raw, true);
        if (is_array($snapshot) && isset($snapshot['split_total'])) {
            return array(
                'amount' => round((float) $snapshot['split_total'], 2),
                'source' => 'snapshot',
            );
        }

        if ($has_je) {
            return array(
                'amount' => round($line_item_total, 2),
                'source' => 'fallback_line_items',
            );
        }

        return array(
            'amount' => 0.0,
            'source' => 'none',
        );
    }
}

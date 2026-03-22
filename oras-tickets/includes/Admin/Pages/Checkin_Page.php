<?php

namespace ORAS\Tickets\Admin\Pages;

use ORAS\Tickets\Security\TicketCheckinToken;

if (! defined('ABSPATH')) {
    exit;
}


final class CheckinPage
{ // NOSONAR legacy WP class naming

    private const NONCE_ACTION = 'oras_tickets_checkin_action';
    private const NONCE_NAME   = 'oras_tickets_checkin_nonce';

    public function render(): void
    {
        if (! current_user_can('oras_tickets_checkin')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'oras-tickets'), '', array('response' => 403));
        }

        $token  = '';
        $mode   = 'verify';
        $result = null;

        if (
            isset($_SERVER['REQUEST_METHOD'])
            && $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST[self::NONCE_NAME])
        ) {
            $nonce = sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME]));
            if (wp_verify_nonce($nonce, self::NONCE_ACTION)) {
                $raw_token = isset($_POST['oras_checkin_token']) ? wp_unslash($_POST['oras_checkin_token']) : '';
                $raw_mode  = isset($_POST['oras_checkin_mode']) ? wp_unslash($_POST['oras_checkin_mode']) : 'verify';

                $token = is_scalar($raw_token) ? trim((string) $raw_token) : '';
                $mode  = is_scalar($raw_mode) ? sanitize_key((string) $raw_mode) : 'verify';
                $mode  = in_array($mode, array('verify', 'mark', 'unmark'), true) ? $mode : 'verify';

                if ($token !== '') {
                    if ($mode === 'mark') {
                        $result = TicketCheckinToken::markCheckedIn($token, (int) get_current_user_id());
                    } elseif ($mode === 'unmark') {
                        $result = TicketCheckinToken::unmarkCheckedIn($token);
                    } else {
                        $result = TicketCheckinToken::verify($token);
                    }
                } else {
                    $result = array(
                        'ok'      => false,
                        'code'    => 'missing_token',
                        'message' => __('A token is required.', 'oras-tickets'),
                        'status'  => 400,
                    );
                }
            }
        }

        $ok      = is_array($result) && ! empty($result['ok']);
        $message = '';
        if (is_array($result)) {
            $message = isset($result['message']) ? (string) $result['message'] : '';
            if ($message === '') {
                $message = $ok
                    ? __('Token is valid.', 'oras-tickets')
                    : __('Token check failed.', 'oras-tickets');
            }
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Ticket Check-In', 'oras-tickets'); ?></h1>
            <p class="description"><?php echo esc_html__('Paste a check-in token from the printed ticket to verify or mark attendance.', 'oras-tickets'); ?></p>

            <?php if (is_array($result)) : ?>
                <div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="oras_checkin_token"><?php echo esc_html__('Check-in Token', 'oras-tickets'); ?></label></th>
                        <td>
                            <textarea id="oras_checkin_token" name="oras_checkin_token" rows="4" class="large-text code"><?php echo esc_textarea($token); ?></textarea>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-secondary" name="oras_checkin_mode" value="verify"><?php echo esc_html__('Verify Token', 'oras-tickets'); ?></button>
                    <button type="submit" class="button button-primary" name="oras_checkin_mode" value="mark"><?php echo esc_html__('Mark Checked In', 'oras-tickets'); ?></button>
                    <button type="submit" class="button" name="oras_checkin_mode" value="unmark"><?php echo esc_html__('Undo Check-In', 'oras-tickets'); ?></button>
                </p>
            </form>

            <?php if (is_array($result)) : ?>
                <h2><?php echo esc_html__('Result', 'oras-tickets'); ?></h2>
                <table class="widefat striped">
                    <tbody>
                        <?php foreach ($result as $key => $value) : ?>
                            <tr>
                                <th style="width:220px;"><?php echo esc_html((string) $key); ?></th>
                                <td>
                                    <?php
                                    if (is_array($value)) {
                                        echo '<pre style="margin:0; white-space:pre-wrap;">' . esc_html(wp_json_encode($value, JSON_PRETTY_PRINT)) . '</pre>';
                                    } elseif (is_bool($value)) {
                                        echo esc_html($value ? 'true' : 'false');
                                    } elseif ($value === null) {
                                        echo esc_html('null');
                                    } else {
                                        echo esc_html((string) $value);
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}

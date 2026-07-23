<?php

namespace ORAS\Tickets;

if (! defined('ABSPATH')) {
    exit;
}

final class Communication_Queue
{
    public const ACTION_HOOK = 'oras_tickets_process_communication';
    private const GROUP = 'oras-tickets';
    private const BATCH_SIZE = 25;

    public static function register(): void
    {
        add_action(self::ACTION_HOOK, array(self::class, 'process'), 10, 1);
    }

    /**
     * @param array<int,array<string,mixed>> $recipients
     */
    public static function enqueue(int $log_id, array $recipients, string $subject, string $body): bool
    {
        $emails = array();
        foreach ($recipients as $recipient) {
            $email = sanitize_email((string) ($recipient['email'] ?? ''));
            if ($email !== '' && is_email($email)) {
                $emails[strtolower($email)] = $email;
            }
        }

        if ($log_id <= 0 || empty($emails)) {
            return false;
        }

        global $wpdb;
        $payload = wp_json_encode(
            array(
                'recipients' => array_values($emails),
                'subject'    => sanitize_text_field($subject),
                'body'       => wp_kses_post($body),
            )
        );
        if (! is_string($payload)) {
            return false;
        }

        $updated = $wpdb->update(
            Communication_Log_Store::table_name(),
            array('delivery_payload' => $payload),
            array('id' => $log_id),
            array('%s'),
            array('%d')
        );
        if (false === $updated) {
            return false;
        }

        return self::schedule($log_id);
    }

    public static function process(int $log_id): void
    {
        $row = Communication_Log_Store::get($log_id);
        if (! is_array($row) || ! in_array((string) $row['send_status'], array('queued', 'sending'), true)) {
            return;
        }

        $payload = json_decode((string) ($row['delivery_payload'] ?? ''), true);
        $recipients = is_array($payload['recipients'] ?? null) ? $payload['recipients'] : array();
        $processed = absint($row['processed_recipient_count'] ?? 0);
        $failed = absint($row['failed_recipient_count'] ?? 0);
        $batch = array_slice($recipients, $processed, self::BATCH_SIZE);

        Communication_Log_Store::update_delivery($log_id, 'sending', $processed, $failed);
        foreach ($batch as $email) {
            if (! wp_mail((string) $email, (string) ($payload['subject'] ?? ''), (string) ($payload['body'] ?? ''), array('Content-Type: text/html; charset=UTF-8'))) {
                ++$failed;
            }
            ++$processed;
        }

        if ($processed < count($recipients)) {
            Communication_Log_Store::update_delivery($log_id, 'sending', $processed, $failed);
            self::schedule($log_id);
            return;
        }

        $status = 0 === $failed ? 'sent' : ($failed >= count($recipients) ? 'failed' : 'partial');
        Communication_Log_Store::update_delivery($log_id, $status, $processed, $failed);
    }

    private static function schedule(int $log_id): bool
    {
        if (function_exists('as_enqueue_async_action')) {
            return 0 < (int) as_enqueue_async_action(self::ACTION_HOOK, array($log_id), self::GROUP);
        }

        return (bool) wp_schedule_single_event(time() + 5, self::ACTION_HOOK, array($log_id));
    }
}

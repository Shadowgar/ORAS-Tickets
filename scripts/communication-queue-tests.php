<?php

use ORAS\Tickets\Communication_Log_Store;
use ORAS\Tickets\Communication_Queue;

if (! defined('ABSPATH')) {
    exit(1);
}

$sent = array();
add_filter(
    'pre_wp_mail',
    static function ($return, $atts) use (&$sent) {
        $sent[] = (string) ($atts['to'] ?? '');
        return true;
    },
    10,
    2
);

Communication_Log_Store::install_schema();
$log_id = Communication_Log_Store::insert(
    array(
        'event_id'            => 123,
        'sender_user_id'      => 1,
        'sender_display_name' => 'Queue Tester',
        'sender_email'        => 'queue@example.org',
        'recipient_segment'   => 'all_attendees',
        'recipient_count'     => 2,
        'email_subject'       => 'Queue test',
        'email_body_snapshot' => 'Body',
        'send_status'         => 'queued',
        'related_action_type' => 'mass_email',
    )
);
if ($log_id <= 0) {
    throw new RuntimeException('Unable to create queued communication log.');
}

$queued = Communication_Queue::enqueue(
    $log_id,
    array(
        array('email' => 'one@example.org'),
        array('email' => 'ONE@example.org'),
        array('email' => 'two@example.org'),
        array('email' => 'invalid'),
    ),
    'Queue test',
    '<p>Body</p>'
);
if (! $queued) {
    throw new RuntimeException('Unable to enqueue communication.');
}

Communication_Queue::process($log_id);
$row = Communication_Log_Store::get($log_id);
if (! is_array($row) || 'sent' !== $row['send_status']) {
    throw new RuntimeException('Queued communication did not complete.');
}
if (2 !== absint($row['processed_recipient_count']) || 2 !== count($sent)) {
    throw new RuntimeException('Recipient deduplication or processing count failed.');
}
if (1 !== absint($row['sender_user_id']) || 'queue@example.org' !== $row['sender_email']) {
    throw new RuntimeException('Sender snapshot changed during queued delivery.');
}

global $wpdb;
$wpdb->delete(Communication_Log_Store::table_name(), array('id' => $log_id), array('%d'));
echo "Communication queue tests passed.\n";

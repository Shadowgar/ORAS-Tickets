<?php

namespace ORAS\Tickets\Admin\Pages;

use ORAS\Tickets\Domain\Meta;

if (! defined('ABSPATH')) {
  exit;
}

final class Speaker_Obligations_Page
{
  private const META_KEY = '_oras_speakers_v1';
  private const META_EVENT_START = '_EventStartDate';
  private const META_SPEAKER_EMAIL = '_oras_speaker_email';
  private const META_SPEAKER_USER_ID = '_oras_speaker_wp_user_id';
  private const OPTION_NOTIFY_EMAILS = 'oras_tickets_speaker_notify_emails';
  private const NOTICE_QUERY_KEY = 'oras_notice';
  private const NOTICE_SUCCESS = 'fulfilled';
  private const NOTICE_ERROR = 'error';
  private const NOTICE_LEVEL_NOT_FOUND = 'level_not_found';
  private const NOTICE_LEVEL_REQUIRES_PAYMENT = 'level_requires_payment';

  public function register(): void
  {
    add_action('admin_post_oras_speaker_fulfill_membership', [$this, 'handle_fulfill_membership']);
  }

  public function render(): void
  {
    if (! current_user_can('manage_woocommerce')) {
      return;
    }

    $filters = $this->get_filters($_GET);
    $rows = $this->get_obligation_rows($filters);

?>
    <div class="wrap">
      <h1><?php echo esc_html__('Speaker Obligations', 'oras-tickets'); ?></h1>

      <?php $this->render_notice(); ?>

      <form method="get" class="oras-speaker-obligations-filters">
        <input type="hidden" name="page" value="oras-tickets-speaker-obligations" />
        <label>
          <?php echo esc_html__('Date from', 'oras-tickets'); ?>
          <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" />
        </label>
        <label>
          <?php echo esc_html__('Date to', 'oras-tickets'); ?>
          <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" />
        </label>
        <label>
          <?php echo esc_html__('Compensation', 'oras-tickets'); ?>
          <select name="compensation_type">
            <option value="all" <?php selected($filters['compensation_type'], 'all'); ?>><?php echo esc_html__('All', 'oras-tickets'); ?></option>
            <option value="fee" <?php selected($filters['compensation_type'], 'fee'); ?>><?php echo esc_html__('Fee', 'oras-tickets'); ?></option>
            <option value="membership" <?php selected($filters['compensation_type'], 'membership'); ?>><?php echo esc_html__('Membership', 'oras-tickets'); ?></option>
          </select>
        </label>
        <?php submit_button(__('Filter', 'oras-tickets'), 'secondary', '', false); ?>
      </form>

      <table class="widefat striped">
        <thead>
          <tr>
            <th><?php echo esc_html__('Event', 'oras-tickets'); ?></th>
            <th><?php echo esc_html__('Event start', 'oras-tickets'); ?></th>
            <th><?php echo esc_html__('Speaker', 'oras-tickets'); ?></th>
            <th><?php echo esc_html__('Role', 'oras-tickets'); ?></th>
            <th><?php echo esc_html__('Primary', 'oras-tickets'); ?></th>
            <th><?php echo esc_html__('Compensation', 'oras-tickets'); ?></th>
            <th><?php echo esc_html__('Fee / PMPro', 'oras-tickets'); ?></th>
            <th><?php echo esc_html__('Fulfilled', 'oras-tickets'); ?></th>
            <th><?php echo esc_html__('Fulfilled date', 'oras-tickets'); ?></th>
            <th><?php echo esc_html__('Actions', 'oras-tickets'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)) : ?>
            <tr>
              <td colspan="10"><?php echo esc_html__('No obligations found.', 'oras-tickets'); ?></td>
            </tr>
          <?php else : ?>
            <?php foreach ($rows as $row) : ?>
              <tr>
                <td>
                  <?php if (! empty($row['event_edit_link'])) : ?>
                    <a href="<?php echo esc_url($row['event_edit_link']); ?>"><?php echo esc_html($row['event_title']); ?></a>
                  <?php else : ?>
                    <?php echo esc_html($row['event_title']); ?>
                  <?php endif; ?>
                </td>
                <td><?php echo esc_html($row['event_start']); ?></td>
                <td>
                  <?php if (! empty($row['speaker_edit_link'])) : ?>
                    <a href="<?php echo esc_url($row['speaker_edit_link']); ?>"><?php echo esc_html($row['speaker_name']); ?></a>
                  <?php else : ?>
                    <?php echo esc_html($row['speaker_name']); ?>
                  <?php endif; ?>
                </td>
                <td><?php echo esc_html($row['role']); ?></td>
                <td><?php echo esc_html($row['is_primary'] ? __('Yes', 'oras-tickets') : __('No', 'oras-tickets')); ?></td>
                <td><?php echo esc_html($row['compensation_type']); ?></td>
                <td><?php echo esc_html($row['compensation_value']); ?></td>
                <td><?php echo esc_html($row['fulfilled'] ? __('Yes', 'oras-tickets') : __('No', 'oras-tickets')); ?></td>
                <td><?php echo esc_html($row['fulfilled_date']); ?></td>
                <td>
                  <?php if ($row['compensation_type'] === 'membership' && ! $row['fulfilled']) : ?>
                    <a class="button button-small" href="<?php echo esc_url($row['fulfill_url']); ?>">
                      <?php echo esc_html__('Fulfill Membership', 'oras-tickets'); ?>
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php
  }

  private function get_filters(array $request): array
  {
    $default_from = wp_date('Y-m-d', strtotime('-12 months'));
    $default_to = wp_date('Y-m-d', strtotime('+12 months'));

    $date_from = isset($request['date_from']) ? sanitize_text_field(wp_unslash($request['date_from'])) : '';
    $date_to = isset($request['date_to']) ? sanitize_text_field(wp_unslash($request['date_to'])) : '';
    $compensation_type = isset($request['compensation_type']) ? sanitize_key(wp_unslash($request['compensation_type'])) : 'all';

    if ($date_from === '') {
      $date_from = $default_from;
    }

    if ($date_to === '') {
      $date_to = $default_to;
    }

    if (! in_array($compensation_type, ['all', 'fee', 'membership'], true)) {
      $compensation_type = 'all';
    }

    return [
      'date_from' => $date_from,
      'date_to' => $date_to,
      'compensation_type' => $compensation_type,
    ];
  }

  private function get_obligation_rows(array $filters): array
  {
    $date_from = $filters['date_from'];
    $date_to = $filters['date_to'];

    $meta_query = [
      'relation' => 'AND',
      [
        'key' => self::META_KEY,
        'compare' => 'EXISTS',
      ],
    ];

    if ($date_from !== '') {
      $meta_query[] = [
        'key' => self::META_EVENT_START,
        'value' => $date_from . ' 00:00:00',
        'compare' => '>=',
        'type' => 'DATETIME',
      ];
    }

    if ($date_to !== '') {
      $meta_query[] = [
        'key' => self::META_EVENT_START,
        'value' => $date_to . ' 23:59:59',
        'compare' => '<=',
        'type' => 'DATETIME',
      ];
    }

    $events = get_posts([
      'post_type' => Meta::EVENT_POST_TYPE,
      'post_status' => ['publish', 'draft', 'future', 'private'],
      'posts_per_page' => 200,
      'no_found_rows' => true,
      'meta_query' => $meta_query,
      'orderby' => 'meta_value',
      'meta_key' => self::META_EVENT_START,
      'order' => 'ASC',
    ]);

    $rows = [];

    foreach ($events as $event) {
      if (! $event instanceof \WP_Post) {
        continue;
      }

      $event_id = (int) $event->ID;
      $assignments = get_post_meta($event_id, self::META_KEY, true);
      if (! is_array($assignments)) {
        continue;
      }

      $event_start = $this->get_event_start_display($event_id);
      $event_title = $event->post_title !== '' ? $event->post_title : __('(Untitled)', 'oras-tickets');
      $event_edit_link = get_edit_post_link($event_id);

      foreach ($assignments as $assignment_index => $assignment) {
        if (! is_array($assignment)) {
          continue;
        }

        $speaker_id = isset($assignment['speaker_id']) ? (int) $assignment['speaker_id'] : 0;
        if ($speaker_id <= 0) {
          continue;
        }

        $fulfilled = ! empty($assignment['fulfilled']);
        if ($fulfilled) {
          continue;
        }

        $compensation_type = isset($assignment['compensation_type']) ? (string) $assignment['compensation_type'] : '';
        $fee_amount = isset($assignment['fee_amount']) ? (float) $assignment['fee_amount'] : 0.0;
        $pmpro_level_id = isset($assignment['pmpro_level_id']) ? (int) $assignment['pmpro_level_id'] : 0;

        if ($compensation_type === 'fee' && $fee_amount <= 0) {
          continue;
        }

        if ($compensation_type === 'membership' && $pmpro_level_id <= 0) {
          continue;
        }

        if (! in_array($compensation_type, ['fee', 'membership'], true)) {
          continue;
        }

        if ($filters['compensation_type'] !== 'all' && $filters['compensation_type'] !== $compensation_type) {
          continue;
        }

        $speaker = get_post($speaker_id);
        $speaker_name = $speaker instanceof \WP_Post && $speaker->post_title !== ''
          ? $speaker->post_title
          : __('(Unknown)', 'oras-tickets');
        $speaker_edit_link = get_edit_post_link($speaker_id);
        $fulfill_url = $this->build_fulfill_url($event_id, $speaker_id, (int) $assignment_index);

        $rows[] = [
          'event_title' => $event_title,
          'event_edit_link' => $event_edit_link,
          'event_start' => $event_start,
          'speaker_name' => $speaker_name,
          'speaker_edit_link' => $speaker_edit_link,
          'event_id' => $event_id,
          'speaker_id' => $speaker_id,
          'assignment_index' => (int) $assignment_index,
          'role' => isset($assignment['role']) ? (string) $assignment['role'] : '',
          'is_primary' => ! empty($assignment['is_primary']),
          'compensation_type' => $compensation_type,
          'compensation_value' => $compensation_type === 'fee'
            ? $this->format_fee($fee_amount)
            : (string) $pmpro_level_id,
          'fulfilled' => $fulfilled,
          'fulfilled_date' => isset($assignment['fulfilled_date']) ? (string) $assignment['fulfilled_date'] : '',
          'fulfill_url' => $fulfill_url,
        ];
      }
    }

    return $rows;
  }

  private function get_event_start_display(int $event_id): string
  {
    if (function_exists('tribe_get_start_date')) {
      return (string) tribe_get_start_date($event_id, false, 'M j, Y g:i a');
    }

    $start_raw = (string) get_post_meta($event_id, self::META_EVENT_START, true);
    if ($start_raw === '') {
      return '';
    }

    $timestamp = strtotime($start_raw);
    if (! $timestamp) {
      return $start_raw;
    }

    return wp_date('M j, Y g:i a', $timestamp);
  }

  private function format_fee(float $amount): string
  {
    $formatted = number_format($amount, 2);

    if (function_exists('get_woocommerce_currency_symbol')) {
      $currency_symbol = (string) get_woocommerce_currency_symbol();
      if ($currency_symbol !== '') {
        return $currency_symbol . $formatted;
      }
    }

    return '$' . $formatted;
  }

  public function handle_fulfill_membership(): void
  {
    if (! current_user_can('manage_woocommerce')) {
      $this->redirect_with_notice(self::NOTICE_ERROR);
    }

    $event_id = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;
    $speaker_id = isset($_GET['speaker_id']) ? absint($_GET['speaker_id']) : 0;
    $assignment_index = isset($_GET['assignment_index']) ? absint($_GET['assignment_index']) : -1;
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

    if ($event_id <= 0 || $speaker_id <= 0 || $assignment_index < 0) {
      $this->redirect_with_notice(self::NOTICE_ERROR);
    }

    if (! wp_verify_nonce($nonce, $this->get_nonce_action($event_id, $speaker_id, $assignment_index))) {
      $this->redirect_with_notice(self::NOTICE_ERROR);
    }

    $event = get_post($event_id);
    if (! $event instanceof \WP_Post || $event->post_type !== Meta::EVENT_POST_TYPE) {
      $this->redirect_with_notice(self::NOTICE_ERROR);
    }

    $event_title = $event->post_title !== '' ? $event->post_title : __('(Untitled)', 'oras-tickets');
    $event_start = $this->get_event_start_display($event_id);

    $speaker = get_post($speaker_id);
    if (! $speaker instanceof \WP_Post || $speaker->post_type !== 'oras_speaker') {
      $this->redirect_with_notice(self::NOTICE_ERROR);
    }

    $speaker_name = $speaker->post_title !== '' ? $speaker->post_title : __('(Unknown)', 'oras-tickets');
    $speaker_email = (string) get_post_meta($speaker_id, self::META_SPEAKER_EMAIL, true);

    $assignments = get_post_meta($event_id, self::META_KEY, true);
    if (! is_array($assignments) || ! isset($assignments[$assignment_index]) || ! is_array($assignments[$assignment_index])) {
      $this->redirect_with_notice(self::NOTICE_ERROR);
    }

    $assignment = $assignments[$assignment_index];
    $assignment_speaker_id = isset($assignment['speaker_id']) ? (int) $assignment['speaker_id'] : 0;
    if ($assignment_speaker_id !== $speaker_id) {
      $this->redirect_with_notice(self::NOTICE_ERROR);
    }

    $compensation_type = isset($assignment['compensation_type']) ? (string) $assignment['compensation_type'] : '';
    $pmpro_level_id = isset($assignment['pmpro_level_id']) ? (int) $assignment['pmpro_level_id'] : 0;
    $fulfilled = ! empty($assignment['fulfilled']);

    if ($compensation_type !== 'membership' || $pmpro_level_id <= 0 || $fulfilled) {
      $this->redirect_with_notice(self::NOTICE_ERROR);
    }

    if (! function_exists('pmpro_getLevel')) {
      $this->redirect_with_notice(self::NOTICE_ERROR);
    }

    $level = pmpro_getLevel($pmpro_level_id);
    if (! $level) {
      $this->redirect_with_notice(self::NOTICE_LEVEL_NOT_FOUND);
    }

    $initial_payment = isset($level->initial_payment) ? (float) $level->initial_payment : 0.0;
    $billing_amount = isset($level->billing_amount) ? (float) $level->billing_amount : 0.0;
    $trial_amount = isset($level->trial_amount) ? (float) $level->trial_amount : 0.0;

    if ($initial_payment > 0 || $billing_amount > 0 || $trial_amount > 0) {
      $this->redirect_with_notice(self::NOTICE_LEVEL_REQUIRES_PAYMENT);
    }

    $user_id = $this->resolve_speaker_user_id($speaker_id);
    if ($user_id <= 0) {
      $this->redirect_with_notice(self::NOTICE_ERROR);
    }

    $already_has_level = false;
    if (function_exists('pmpro_getMembershipLevelForUser')) {
      $current_level = pmpro_getMembershipLevelForUser($user_id);
      $already_has_level = $current_level && (int) $current_level->id === $pmpro_level_id;
    }

    if ($already_has_level) {
      $assignments[$assignment_index]['fulfilled'] = true;
      $assignments[$assignment_index]['fulfilled_date'] = wp_date('Y-m-d');
      update_post_meta($event_id, self::META_KEY, $assignments);
      $level_name = '';
      if ($pmpro_level_id > 0 && function_exists('pmpro_getLevel')) {
        $lvl = pmpro_getLevel($pmpro_level_id);
        if ($lvl && ! empty($lvl->name)) {
          $level_name = (string) $lvl->name;
        }
      }
      $this->send_fulfillment_email($speaker_name, $speaker_email, $event_title, $event_start, $pmpro_level_id, $level_name);
      $this->redirect_with_notice(self::NOTICE_SUCCESS);
    }

    if (! function_exists('pmpro_changeMembershipLevel')) {
      $this->redirect_with_notice(self::NOTICE_ERROR);
    }

    $changed = pmpro_changeMembershipLevel($pmpro_level_id, $user_id);
    if (! $changed) {
      $this->redirect_with_notice(self::NOTICE_ERROR);
    }

    $assignments[$assignment_index]['fulfilled'] = true;
    $assignments[$assignment_index]['fulfilled_date'] = wp_date('Y-m-d');

    update_post_meta($event_id, self::META_KEY, $assignments);

    $level_name = '';
    if ($pmpro_level_id > 0 && function_exists('pmpro_getLevel')) {
      $lvl = pmpro_getLevel($pmpro_level_id);
      if ($lvl && ! empty($lvl->name)) {
        $level_name = (string) $lvl->name;
      }
    }
    $this->send_fulfillment_email($speaker_name, $speaker_email, $event_title, $event_start, $pmpro_level_id, $level_name);

    $this->redirect_with_notice(self::NOTICE_SUCCESS);
  }

  private function resolve_speaker_user_id(int $speaker_id): int
  {
    $linked_user_id = (int) get_post_meta($speaker_id, self::META_SPEAKER_USER_ID, true);
    if ($linked_user_id > 0) {
      $user = get_user_by('id', $linked_user_id);
      if ($user) {
        return $linked_user_id;
      }
    }

    $speaker_email = (string) get_post_meta($speaker_id, self::META_SPEAKER_EMAIL, true);
    if ($speaker_email === '') {
      return 0;
    }

    $user = get_user_by('email', $speaker_email);
    if ($user) {
      update_post_meta($speaker_id, self::META_SPEAKER_USER_ID, (int) $user->ID);
      return (int) $user->ID;
    }

    $user_login = sanitize_user(strtok($speaker_email, '@'), true);
    if ($user_login === '') {
      $user_login = 'oras-speaker';
    }

    $base_login = $user_login;
    $suffix = 1;
    while (username_exists($user_login)) {
      $user_login = $base_login . $suffix;
      $suffix++;
    }

    $user_id = wp_insert_user([
      'user_login' => $user_login,
      'user_email' => $speaker_email,
      'user_pass' => wp_generate_password(24, true, true),
      'role' => 'subscriber',
    ]);

    if (is_wp_error($user_id)) {
      return 0;
    }

    update_post_meta($speaker_id, self::META_SPEAKER_USER_ID, (int) $user_id);

    return (int) $user_id;
  }

  private function build_fulfill_url(int $event_id, int $speaker_id, int $assignment_index): string
  {
    $action = 'oras_speaker_fulfill_membership';
    $args = [
      'action' => $action,
      'event_id' => $event_id,
      'speaker_id' => $speaker_id,
      'assignment_index' => $assignment_index,
    ];

    $url = add_query_arg($args, admin_url('admin-post.php'));

    return wp_nonce_url($url, $this->get_nonce_action($event_id, $speaker_id, $assignment_index));
  }

  private function get_nonce_action(int $event_id, int $speaker_id, int $assignment_index): string
  {
    return 'oras_speaker_fulfill_membership_' . $event_id . '_' . $speaker_id . '_' . $assignment_index;
  }

  private function redirect_with_notice(string $notice): void
  {
    $target = add_query_arg([
      'page' => 'oras-tickets-speaker-obligations',
      self::NOTICE_QUERY_KEY => $notice,
    ], admin_url('admin.php'));

    wp_safe_redirect($target);
    exit;
  }

  private function render_notice(): void
  {
    if (! isset($_GET[self::NOTICE_QUERY_KEY])) {
      return;
    }

    $notice = sanitize_key(wp_unslash($_GET[self::NOTICE_QUERY_KEY]));
    if ($notice === self::NOTICE_SUCCESS) {
      $class = 'notice notice-success is-dismissible';
      $message = __('Membership fulfilled.', 'oras-tickets');
    } elseif ($notice === self::NOTICE_LEVEL_NOT_FOUND) {
      $class = 'notice notice-error';
      $message = __('PMPro level not found.', 'oras-tickets');
    } elseif ($notice === self::NOTICE_LEVEL_REQUIRES_PAYMENT) {
      $class = 'notice notice-error';
      $message = __('Selected PMPro level requires payment and cannot be auto-fulfilled. Choose a $0 “Speaker Complimentary” level.', 'oras-tickets');
    } elseif ($notice === self::NOTICE_ERROR) {
      $class = 'notice notice-error';
      $message = __('Unable to fulfill membership.', 'oras-tickets');
    } else {
      return;
    }

  ?>
    <div class="<?php echo esc_attr($class); ?>">
      <p><?php echo esc_html($message); ?></p>
    </div>
<?php
  }

  private function send_fulfillment_email(
    string $speaker_name,
    string $speaker_email,
    string $event_title,
    string $event_start,
    int $pmpro_level_id,
    string $pmpro_level_name
  ): void {
    $raw_recipients = (string) get_option(self::OPTION_NOTIFY_EMAILS, '');
    if ($raw_recipients === '') {
      return;
    }

    $emails = array_filter(array_map('trim', explode(',', $raw_recipients)));
    $recipients = [];
    foreach ($emails as $email) {
      if (is_email($email)) {
        $recipients[] = $email;
      }
    }

    if (empty($recipients)) {
      return;
    }

    $level_label = $pmpro_level_name !== ''
      ? $pmpro_level_name . ' (ID ' . $pmpro_level_id . ')'
      : 'Level ID ' . $pmpro_level_id;
    $fulfilled_by = wp_get_current_user();
    $fulfilled_by_name = $fulfilled_by instanceof \WP_User ? $fulfilled_by->display_name : '';

    $subject = sprintf(
      __('ORAS Speaker Membership Fulfilled: %s', 'oras-tickets'),
      $speaker_name
    );

    $body = "Speaker: {$speaker_name} <{$speaker_email}>\n";
    $body .= "Event: {$event_title}\n";
    $body .= "Event date: {$event_start}\n";
    $body .= "Level: {$level_label}\n";
    $body .= "Fulfilled by: {$fulfilled_by_name}\n";
    $body .= 'Fulfilled date: ' . wp_date('Y-m-d');

    $sent = wp_mail(
      $recipients,
      $subject,
      $body,
      ['Content-Type: text/plain; charset=UTF-8']
    );
  }
}

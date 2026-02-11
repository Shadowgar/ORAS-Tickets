<?php

namespace ORAS\Tickets\Admin;

use ORAS\Tickets\Domain\Meta;

if (! defined('ABSPATH')) {
  exit;
}

final class Speaker_CPT
{
  private const POST_TYPE = 'oras_speaker';
  private const NONCE_ACTION = 'oras_speaker_details';
  private const NONCE_NAME = 'oras_speaker_details_nonce';

  private const META_EMAIL = '_oras_speaker_email';
  private const META_AFFILIATION = '_oras_speaker_affiliation';
  private const META_WEBSITE_URL = '_oras_speaker_website_url';
  private const META_WP_USER_ID = '_oras_speaker_wp_user_id';
  private const META_STATUS = '_oras_speaker_status';
  private const META_INTERNAL_NOTES = '_oras_speaker_internal_notes';
  private const META_SPEAKER_ASSIGNMENTS = '_oras_speakers_v1';
  private const META_EVENT_START = '_EventStartDate';

  private const STATUS_ACTIVE = 'active';
  private const STATUS_INACTIVE = 'inactive';

  public function register(): void
  {
    add_action('init', [$this, 'register_post_type']);
    add_action('add_meta_boxes', [$this, 'register_metabox']);
    add_action('save_post_' . self::POST_TYPE, [$this, 'save_post'], 10, 2);
  }

  public function register_post_type(): void
  {
    $labels = [
      'name' => __('Speakers', 'oras-tickets'),
      'singular_name' => __('Speaker', 'oras-tickets'),
      'add_new_item' => __('Add New Speaker', 'oras-tickets'),
      'edit_item' => __('Edit Speaker', 'oras-tickets'),
      'new_item' => __('New Speaker', 'oras-tickets'),
      'view_item' => __('View Speaker', 'oras-tickets'),
      'search_items' => __('Search Speakers', 'oras-tickets'),
      'not_found' => __('No speakers found', 'oras-tickets'),
      'not_found_in_trash' => __('No speakers found in Trash', 'oras-tickets'),
      'menu_name' => __('Speakers', 'oras-tickets'),
    ];

    $args = [
      'labels' => $labels,
      'public' => false,
      'show_ui' => true,
      'publicly_queryable' => false,
      'exclude_from_search' => true,
      'show_in_menu' => 'oras-tickets',
      'supports' => ['title', 'editor', 'thumbnail'],
      'capability_type' => 'post',
      'has_archive' => false,
      'rewrite' => false,
      'show_in_rest' => false,
    ];

    register_post_type(self::POST_TYPE, $args);
  }

  public function register_metabox(): void
  {
    add_meta_box(
      'oras_speaker_details',
      __('Speaker Details', 'oras-tickets'),
      [$this, 'render_metabox'],
      self::POST_TYPE,
      'normal',
      'default'
    );

    add_meta_box(
      'oras_speaker_history',
      __('Speaker History', 'oras-tickets'),
      [$this, 'render_history_metabox'],
      self::POST_TYPE,
      'normal',
      'default'
    );
  }

  public function render_metabox(\WP_Post $post): void
  {
    if (! current_user_can('edit_post', $post->ID)) {
      return;
    }

    $email = (string) get_post_meta($post->ID, self::META_EMAIL, true);
    $affiliation = (string) get_post_meta($post->ID, self::META_AFFILIATION, true);
    $website_url = (string) get_post_meta($post->ID, self::META_WEBSITE_URL, true);
    $status = (string) get_post_meta($post->ID, self::META_STATUS, true);
    $internal_notes = (string) get_post_meta($post->ID, self::META_INTERNAL_NOTES, true);
    $linked_user_id = (int) get_post_meta($post->ID, self::META_WP_USER_ID, true);

    if (! in_array($status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE], true)) {
      $status = self::STATUS_ACTIVE;
    }

    wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

    $linked_label = $linked_user_id > 0
      ? sprintf(__('Linked WP User: ID %d', 'oras-tickets'), $linked_user_id)
      : __('Linked WP User: none', 'oras-tickets');

?>
    <table class="form-table" role="presentation">
      <tbody>
        <tr>
          <th scope="row">
            <label for="oras_speaker_email"><?php echo esc_html__('Email', 'oras-tickets'); ?></label>
          </th>
          <td>
            <input type="email" class="regular-text" id="oras_speaker_email" name="oras_speaker_email" value="<?php echo esc_attr($email); ?>" />
            <p class="description"><?php echo esc_html__('Required for membership fulfillment (not enforced).', 'oras-tickets'); ?></p>
          </td>
        </tr>
        <tr>
          <th scope="row">
            <label for="oras_speaker_affiliation"><?php echo esc_html__('Affiliation', 'oras-tickets'); ?></label>
          </th>
          <td>
            <input type="text" class="regular-text" id="oras_speaker_affiliation" name="oras_speaker_affiliation" value="<?php echo esc_attr($affiliation); ?>" />
          </td>
        </tr>
        <tr>
          <th scope="row">
            <label for="oras_speaker_website_url"><?php echo esc_html__('Website URL', 'oras-tickets'); ?></label>
          </th>
          <td>
            <input type="url" class="regular-text" id="oras_speaker_website_url" name="oras_speaker_website_url" value="<?php echo esc_attr($website_url); ?>" />
          </td>
        </tr>
        <tr>
          <th scope="row">
            <label for="oras_speaker_status"><?php echo esc_html__('Status', 'oras-tickets'); ?></label>
          </th>
          <td>
            <select id="oras_speaker_status" name="oras_speaker_status">
              <option value="active" <?php selected($status, self::STATUS_ACTIVE); ?>><?php echo esc_html__('Active', 'oras-tickets'); ?></option>
              <option value="inactive" <?php selected($status, self::STATUS_INACTIVE); ?>><?php echo esc_html__('Inactive', 'oras-tickets'); ?></option>
            </select>
          </td>
        </tr>
        <tr>
          <th scope="row"><?php echo esc_html__('Linked WP User', 'oras-tickets'); ?></th>
          <td>
            <p><?php echo esc_html($linked_label); ?></p>
          </td>
        </tr>
        <tr>
          <th scope="row">
            <label for="oras_speaker_internal_notes"><?php echo esc_html__('Internal Notes', 'oras-tickets'); ?></label>
          </th>
          <td>
            <textarea class="large-text" id="oras_speaker_internal_notes" name="oras_speaker_internal_notes" rows="4"><?php echo esc_textarea($internal_notes); ?></textarea>
          </td>
        </tr>
      </tbody>
    </table>
  <?php
  }

  public function render_history_metabox(\WP_Post $post): void
  {
    if (! current_user_can('edit_post', $post->ID)) {
      return;
    }

    $show_all = isset($_GET['oras_speaker_history']) && $_GET['oras_speaker_history'] === 'all';
    $history = $this->get_speaker_history((int) $post->ID, $show_all);
    $rows = $history['rows'];
    $summary = $history['summary'];

    $edit_link = get_edit_post_link($post->ID, 'raw');
    if (! $edit_link) {
      $edit_link = admin_url('post.php?post=' . (int) $post->ID . '&action=edit');
    }

    $toggle_url = $show_all
      ? remove_query_arg('oras_speaker_history', $edit_link)
      : add_query_arg('oras_speaker_history', 'all', $edit_link);
    $toggle_label = $show_all
      ? __('Show last 24 months', 'oras-tickets')
      : __('Show all', 'oras-tickets');

  ?>
    <p>
      <?php echo esc_html__('Total assignments', 'oras-tickets'); ?>: <?php echo esc_html((string) $summary['total']); ?> |
      <?php echo esc_html__('Unfulfilled', 'oras-tickets'); ?>: <?php echo esc_html((string) $summary['unfulfilled']); ?> |
      <?php echo esc_html__('Fulfilled', 'oras-tickets'); ?>: <?php echo esc_html((string) $summary['fulfilled']); ?> |
      <?php echo esc_html__('Unfulfilled fee total', 'oras-tickets'); ?>: <?php echo esc_html($summary['unfulfilled_fee_total']); ?> |
      <?php echo esc_html__('Fulfilled memberships', 'oras-tickets'); ?>: <?php echo esc_html((string) $summary['fulfilled_memberships']); ?>
    </p>
    <p>
      <a href="<?php echo esc_url($toggle_url); ?>"><?php echo esc_html($toggle_label); ?></a>
    </p>

    <table class="widefat striped">
      <thead>
        <tr>
          <th><?php echo esc_html__('Event', 'oras-tickets'); ?></th>
          <th><?php echo esc_html__('Event start', 'oras-tickets'); ?></th>
          <th><?php echo esc_html__('Role', 'oras-tickets'); ?></th>
          <th><?php echo esc_html__('Primary', 'oras-tickets'); ?></th>
          <th><?php echo esc_html__('Compensation', 'oras-tickets'); ?></th>
          <th><?php echo esc_html__('Fulfilled', 'oras-tickets'); ?></th>
          <th><?php echo esc_html__('Fulfilled date', 'oras-tickets'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)) : ?>
          <tr>
            <td colspan="7"><?php echo esc_html__('No history found.', 'oras-tickets'); ?></td>
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
              <td><?php echo esc_html($row['role']); ?></td>
              <td><?php echo esc_html($row['is_primary'] ? __('Yes', 'oras-tickets') : __('No', 'oras-tickets')); ?></td>
              <td><?php echo esc_html($row['compensation']); ?></td>
              <td><?php echo esc_html($row['fulfilled'] ? __('Yes', 'oras-tickets') : __('No', 'oras-tickets')); ?></td>
              <td><?php echo esc_html($row['fulfilled_date']); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
<?php
  }

  public function save_post(int $post_id, \WP_Post $post): void
  {
    if (! isset($_POST[self::NONCE_NAME])) {
      return;
    }

    $nonce = sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME]));
    if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
      return;
    }

    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
      return;
    }

    if ($post->post_type !== self::POST_TYPE) {
      return;
    }

    if (! current_user_can('edit_post', $post_id)) {
      return;
    }

    $email = isset($_POST['oras_speaker_email'])
      ? sanitize_email(wp_unslash($_POST['oras_speaker_email']))
      : '';
    $affiliation = isset($_POST['oras_speaker_affiliation'])
      ? sanitize_text_field(wp_unslash($_POST['oras_speaker_affiliation']))
      : '';
    $website_url = isset($_POST['oras_speaker_website_url'])
      ? esc_url_raw(wp_unslash($_POST['oras_speaker_website_url']))
      : '';
    $status = isset($_POST['oras_speaker_status'])
      ? sanitize_key(wp_unslash($_POST['oras_speaker_status']))
      : '';
    $internal_notes = isset($_POST['oras_speaker_internal_notes'])
      ? sanitize_textarea_field(wp_unslash($_POST['oras_speaker_internal_notes']))
      : '';

    if (! in_array($status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE], true)) {
      $status = '';
    }

    $this->update_or_delete_meta($post_id, self::META_EMAIL, $email);
    $this->update_or_delete_meta($post_id, self::META_AFFILIATION, $affiliation);
    $this->update_or_delete_meta($post_id, self::META_WEBSITE_URL, $website_url);
    $this->update_or_delete_meta($post_id, self::META_STATUS, $status);
    $this->update_or_delete_meta($post_id, self::META_INTERNAL_NOTES, $internal_notes);
  }

  private function update_or_delete_meta(int $post_id, string $meta_key, string $value): void
  {
    if ($value === '') {
      delete_post_meta($post_id, $meta_key);
      return;
    }

    update_post_meta($post_id, $meta_key, $value);
  }

  private function get_speaker_history(int $speaker_id, bool $show_all): array
  {
    $meta_query = [
      'relation' => 'AND',
      [
        'key' => self::META_SPEAKER_ASSIGNMENTS,
        'compare' => 'EXISTS',
      ],
    ];

    if (! $show_all) {
      $date_from = wp_date('Y-m-d', strtotime('-24 months'));
      $date_to = wp_date('Y-m-d');

      $meta_query[] = [
        'key' => self::META_EVENT_START,
        'value' => $date_from . ' 00:00:00',
        'compare' => '>=',
        'type' => 'DATETIME',
      ];

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
      'order' => 'DESC',
    ]);

    $rows = [];
    $total = 0;
    $fulfilled = 0;
    $unfulfilled = 0;
    $unfulfilled_fee_total = 0.0;
    $fulfilled_memberships = 0;

    foreach ($events as $event) {
      if (! $event instanceof \WP_Post) {
        continue;
      }

      $event_id = (int) $event->ID;
      $assignments = get_post_meta($event_id, self::META_SPEAKER_ASSIGNMENTS, true);
      if (! is_array($assignments)) {
        continue;
      }

      foreach ($assignments as $assignment) {
        if (! is_array($assignment)) {
          continue;
        }

        $assignment_speaker_id = isset($assignment['speaker_id']) ? (int) $assignment['speaker_id'] : 0;
        if ($assignment_speaker_id !== $speaker_id) {
          continue;
        }

        $total++;

        $is_fulfilled = ! empty($assignment['fulfilled']);
        if ($is_fulfilled) {
          $fulfilled++;
        } else {
          $unfulfilled++;
        }

        $compensation_type = isset($assignment['compensation_type']) ? (string) $assignment['compensation_type'] : 'none';
        $fee_amount = isset($assignment['fee_amount']) ? (float) $assignment['fee_amount'] : 0.0;
        $pmpro_level_id = isset($assignment['pmpro_level_id']) ? (int) $assignment['pmpro_level_id'] : 0;

        if ($compensation_type === 'fee' && ! $is_fulfilled) {
          $unfulfilled_fee_total += max(0.0, $fee_amount);
        }

        if ($compensation_type === 'membership' && $is_fulfilled) {
          $fulfilled_memberships++;
        }

        $rows[] = [
          'event_title' => $event->post_title !== '' ? $event->post_title : __('(Untitled)', 'oras-tickets'),
          'event_edit_link' => get_edit_post_link($event_id),
          'event_start' => $this->get_event_start_display($event_id),
          'role' => isset($assignment['role']) ? (string) $assignment['role'] : '',
          'is_primary' => ! empty($assignment['is_primary']),
          'compensation' => $this->format_compensation($compensation_type, $fee_amount, $pmpro_level_id),
          'fulfilled' => $is_fulfilled,
          'fulfilled_date' => isset($assignment['fulfilled_date']) ? (string) $assignment['fulfilled_date'] : '',
        ];
      }
    }

    return [
      'rows' => $rows,
      'summary' => [
        'total' => $total,
        'unfulfilled' => $unfulfilled,
        'fulfilled' => $fulfilled,
        'unfulfilled_fee_total' => $this->format_fee($unfulfilled_fee_total),
        'fulfilled_memberships' => $fulfilled_memberships,
      ],
    ];
  }

  private function format_compensation(string $compensation_type, float $fee_amount, int $pmpro_level_id): string
  {
    if ($compensation_type === 'fee') {
      return $this->format_fee($fee_amount);
    }

    if ($compensation_type === 'membership') {
      if ($pmpro_level_id <= 0) {
        return '-';
      }

      $level_name = $this->resolve_level_name($pmpro_level_id);
      if ($level_name !== '') {
        return $level_name . ' (#' . $pmpro_level_id . ')';
      }

      return (string) $pmpro_level_id;
    }

    return '-';
  }

  private function resolve_level_name(int $pmpro_level_id): string
  {
    if ($pmpro_level_id <= 0 || ! function_exists('pmpro_getLevel')) {
      return '';
    }

    $level = pmpro_getLevel($pmpro_level_id);
    if (! $level || ! isset($level->name)) {
      return '';
    }

    return (string) $level->name;
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
}

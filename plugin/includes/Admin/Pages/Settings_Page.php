<?php

namespace ORAS\Tickets\Admin\Pages;

if (! defined('ABSPATH')) {
  exit;
}

final class Settings_Page
{

  private const OPTION_KEY = 'oras_tickets_settings';

  public function render(): void
  {
    if (! current_user_can('manage_woocommerce')) {
      return;
    }

    $updated = false;
    if (isset($_POST['oras_tickets_settings_nonce']) && wp_verify_nonce(wp_unslash($_POST['oras_tickets_settings_nonce']), 'oras_tickets_settings')) {
      $restore = isset($_POST['oras_tickets_restore_on_cancel_refund']) ? 1 : 0;
      update_option(self::OPTION_KEY, ['restore_on_cancel_refund' => $restore]);
      $updated = true;
    }

    $settings = get_option(self::OPTION_KEY, ['restore_on_cancel_refund' => 1]);
    $restore_enabled = isset($settings['restore_on_cancel_refund']) ? (bool) $settings['restore_on_cancel_refund'] : true;

?>
    <div class="wrap">
      <h1><?php echo esc_html__('ORAS Tickets Settings', 'oras-tickets'); ?></h1>

      <?php if ($updated) : ?>
        <div class="notice notice-success is-dismissible">
          <p><?php echo esc_html__('Settings saved.', 'oras-tickets'); ?></p>
        </div>
      <?php endif; ?>

      <form method="post">
        <?php wp_nonce_field('oras_tickets_settings', 'oras_tickets_settings_nonce'); ?>
        <table class="form-table" role="presentation">
          <tbody>
            <tr>
              <th scope="row"><?php echo esc_html__('Restore stock on cancellation/refund', 'oras-tickets'); ?></th>
              <td>
                <label>
                  <input type="checkbox" name="oras_tickets_restore_on_cancel_refund" value="1" <?php checked($restore_enabled); ?> />
                  <?php echo esc_html__('Enable automatic stock restoration for cancelled or refunded orders.', 'oras-tickets'); ?>
                </label>
              </td>
            </tr>
          </tbody>
        </table>

        <?php submit_button(__('Save Settings', 'oras-tickets')); ?>
      </form>
    </div>
<?php
  }
}

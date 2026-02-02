<?php

namespace ORAS\Tickets\Admin\Pages;

if (! defined('ABSPATH')) {
  exit;
}

final class Reports_Page
{

  public function render(): void
  {
    if (! current_user_can('manage_woocommerce')) {
      return;
    }

?>
    <div class="wrap">
      <h1><?php echo esc_html__('Ticket Reports', 'oras-tickets'); ?></h1>
      <p><?php echo esc_html__('Ticket Reports', 'oras-tickets'); ?></p>
      <p>
        <button type="button" class="button"><?php echo esc_html__('Export CSV', 'oras-tickets'); ?></button>
      </p>
    </div>
<?php
  }
}

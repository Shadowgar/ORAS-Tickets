<?php
namespace ORAS\Tickets\Admin;

use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Ticket_Collection;
use ORAS\Tickets\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Tickets_Metabox {

    private static ?Tickets_Metabox $instance = null;

    public static function instance(): Tickets_Metabox {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function init(): void {
        add_action( 'add_meta_boxes', [ $this, 'register_metabox' ] );
        add_action( 'save_post', [ $this, 'save_post' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets( $hook_suffix ): void {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || ! isset( $screen->post_type ) || $screen->post_type !== Meta::EVENT_POST_TYPE ) {
            return;
        }

        // Only load on the post editor screens. Accept either screen base === 'post'
        // (covers editors) or hook suffix explicitly for post edit/new pages.
        $is_editor = ( isset( $screen->base ) && $screen->base === 'post' ) || in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true );
        if ( ! $is_editor ) {
            return;
        }

        wp_enqueue_script(
            'oras-tickets-metabox',
            ORAS_TICKETS_URL . 'assets/admin/tickets-metabox.js',
            [],
            ORAS_TICKETS_VERSION,
            true
        );
    }

    public function register_metabox(): void {
        add_meta_box(
            'oras_tickets_metabox',
            'ORAS Tickets',
            [ $this, 'render_metabox' ],
            Meta::EVENT_POST_TYPE,
            'normal',
            'default'
        );
    }

    public function render_metabox( \WP_Post $post ): void {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }

        $envelope = Ticket_Collection::load_for_event( $post->ID );
        $tickets  = $envelope['tickets'] ?? [];

        // Nonce
        wp_nonce_field( 'oras_tickets_metabox', 'oras_tickets_metabox_nonce' );

        ?>
        <div id="oras-tickets-metabox">
            <table class="widefat" id="oras-tickets-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Price</th>
                        <th>Capacity</th>
                        <th>Sale start</th>
                        <th>Sale end</th>
                        <th>Description</th>
                        <th>Hide sold out</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $tickets as $index => $data ) :
                        $name = isset( $data['name'] ) ? $data['name'] : '';
                        $price = isset( $data['price'] ) ? $data['price'] : '0.00';
                        $capacity = isset( $data['capacity'] ) ? $data['capacity'] : 0;
                        $sale_start = isset( $data['sale_start'] ) ? $data['sale_start'] : '';
                        $sale_end = isset( $data['sale_end'] ) ? $data['sale_end'] : '';
                        $description = isset( $data['description'] ) ? $data['description'] : '';
                        $hide_sold_out = ! empty( $data['hide_sold_out'] );
                        $idx = esc_attr( (string) $index );
                        ?>
                        <tr class="oras-ticket-row" data-index="<?php echo $idx; ?>">
                            <td>
                                <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][name]" value="<?php echo esc_attr( $name ); ?>" />
                            </td>
                            <td>
                                <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price]" value="<?php echo esc_attr( $price ); ?>" />
                            </td>
                            <td>
                                <input type="number" min="0" name="oras_tickets_tickets[<?php echo $idx; ?>][capacity]" value="<?php echo esc_attr( $capacity ); ?>" />
                            </td>
                            <td>
                                <?php
                                $sale_start_val = $sale_start !== '' ? str_replace( ' ', 'T', $sale_start ) : '';
                                ?>
                                <input type="datetime-local" name="oras_tickets_tickets[<?php echo $idx; ?>][sale_start]" value="<?php echo esc_attr( $sale_start_val ); ?>" />
                            </td>
                            <td>
                                <?php
                                $sale_end_val = $sale_end !== '' ? str_replace( ' ', 'T', $sale_end ) : '';
                                ?>
                                <input type="datetime-local" name="oras_tickets_tickets[<?php echo $idx; ?>][sale_end]" value="<?php echo esc_attr( $sale_end_val ); ?>" />
                            </td>
                            <td>
                                <textarea name="oras_tickets_tickets[<?php echo $idx; ?>][description]" rows="2"><?php echo esc_textarea( $description ); ?></textarea>
                            </td>
                            <td>
                                <input type="checkbox" name="oras_tickets_tickets[<?php echo $idx; ?>][hide_sold_out]" value="1" <?php checked( $hide_sold_out ); ?> />
                            </td>
                            <td>
                                <button type="button" class="oras-remove-ticket button">Remove</button>
                                <input type="hidden" name="oras_tickets_index[]" value="<?php echo $idx; ?>" />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <button type="button" id="oras-add-ticket" class="button">Add Ticket</button>
            </p>

            <!-- Template row (uses <template> so it won't be submitted) -->
                    <template id="oras-ticket-template">
                <tr class="oras-ticket-row" data-index="__INDEX__">
                    <td><input type="text" name="oras_tickets_tickets[__INDEX__][name]" value="" /></td>
                    <td><input type="text" name="oras_tickets_tickets[__INDEX__][price]" value="0.00" /></td>
                    <td><input type="number" min="0" name="oras_tickets_tickets[__INDEX__][capacity]" value="0" /></td>
                    <td><input type="datetime-local" name="oras_tickets_tickets[__INDEX__][sale_start]" value="" /></td>
                    <td><input type="datetime-local" name="oras_tickets_tickets[__INDEX__][sale_end]" value="" /></td>
                    <td><textarea name="oras_tickets_tickets[__INDEX__][description]" rows="2"></textarea></td>
                    <td><input type="checkbox" name="oras_tickets_tickets[__INDEX__][hide_sold_out]" value="1" /></td>
                    <td><button type="button" class="oras-remove-ticket button">Remove</button>
                    <input type="hidden" name="oras_tickets_index[]" value="__INDEX__" /></td>
                </tr>
            </template>

        </div>

        

        <?php
    }

    public function save_post( int $post_id, \WP_Post $post ): void {
        // Only save for event post type
        if ( $post->post_type !== Meta::EVENT_POST_TYPE ) {
            return;
        }

        // Autosave / revision guard
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! isset( $_POST['oras_tickets_metabox_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['oras_tickets_metabox_nonce'] ), 'oras_tickets_metabox' ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( ! isset( $_POST['oras_tickets_tickets'] ) || ! is_array( $_POST['oras_tickets_tickets'] ) ) {
            // Clear tickets if none provided
            Ticket_Collection::save_for_event( $post_id, [ 'schema' => 1, 'tickets' => [] ] );
            return;
        }

        $raw = wp_unslash( $_POST['oras_tickets_tickets'] );
        $clean_tickets = [];

        // Determine posted index order if provided.
        $posted_indices = isset( $_POST['oras_tickets_index'] ) && is_array( $_POST['oras_tickets_index'] )
            ? wp_unslash( $_POST['oras_tickets_index'] )
            : null;

        if ( is_array( $posted_indices ) ) {
            // Rebuild tickets in the posted order using numeric incremental keys.
            foreach ( $posted_indices as $idx ) {
                $idx_int = absint( $idx );
                if ( $idx_int === 0 && (string) $idx !== '0' ) {
                    // non-numeric index, skip
                    continue;
                }
                $idx = (string) $idx_int;
                if ( $idx === '' || ! isset( $raw[ $idx ] ) || ! is_array( $raw[ $idx ] ) ) {
                    continue;
                }
                $fields = $raw[ $idx ];
                $name = isset( $fields['name'] ) ? sanitize_text_field( $fields['name'] ) : '';
                // Price: normalize, ensure non-negative, two decimals
                $price_raw = isset( $fields['price'] ) ? str_replace( ',', '.', $fields['price'] ) : '0';
                $price_float = floatval( $price_raw );
                if ( $price_float < 0 ) {
                    $price_float = 0.0;
                }
                $price = number_format( $price_float, 2, '.', '' );
                // Capacity: absolute int
                $capacity = isset( $fields['capacity'] ) ? absint( $fields['capacity'] ) : 0;
                $sale_start = isset( $fields['sale_start'] ) ? sanitize_text_field( $fields['sale_start'] ) : '';
                $sale_end = isset( $fields['sale_end'] ) ? sanitize_text_field( $fields['sale_end'] ) : '';
                // Accept datetime-local format (YYYY-MM-DDTHH:MM) and convert to storage format (YYYY-MM-DD HH:MM).
                if ( $sale_start !== '' ) {
                    $sale_start = str_replace( 'T', ' ', $sale_start );
                    $sale_start = trim( $sale_start );
                    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $sale_start ) ) {
                        $sale_start = '';
                    }
                }
                if ( $sale_end !== '' ) {
                    $sale_end = str_replace( 'T', ' ', $sale_end );
                    $sale_end = trim( $sale_end );
                    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $sale_end ) ) {
                        $sale_end = '';
                    }
                }
                // If both present and out of order, swap to ensure start <= end
                if ( $sale_start !== '' && $sale_end !== '' ) {
                    $dt1 = \DateTime::createFromFormat( 'Y-m-d H:i', $sale_start, wp_timezone() );
                    $dt2 = \DateTime::createFromFormat( 'Y-m-d H:i', $sale_end, wp_timezone() );
                    if ( $dt1 instanceof \DateTimeInterface && $dt2 instanceof \DateTimeInterface ) {
                        if ( $dt2->getTimestamp() < $dt1->getTimestamp() ) {
                            // swap
                            $tmp = $sale_start;
                            $sale_start = $sale_end;
                            $sale_end = $tmp;
                        }
                    }
                }
                $description = isset( $fields['description'] ) ? sanitize_textarea_field( $fields['description'] ) : '';
                $hide_sold_out = isset( $fields['hide_sold_out'] ) && ( $fields['hide_sold_out'] === '1' || $fields['hide_sold_out'] === 1 );

                // Skip empty-default rows: name empty, description empty, sale dates empty, hide_sold_out false, capacity <=0, price <=0
                if ( $name === '' && $description === '' && $sale_start === '' && $sale_end === '' && ! $hide_sold_out && $capacity <= 0 && $price_float <= 0.0 ) {
                    continue;
                }

                $clean_tickets[] = [
                    'name' => $name,
                    'price' => $price,
                    'capacity' => $capacity,
                    'sale_start' => $sale_start,
                    'sale_end' => $sale_end,
                    'description' => $description,
                    'hide_sold_out' => $hide_sold_out,
                ];
            }
        } else {
            // Fallback: preserve posted order of the tickets values.
            $values = array_values( $raw );
            foreach ( $values as $fields ) {
                if ( ! is_array( $fields ) ) {
                    continue;
                }

                $name = isset( $fields['name'] ) ? sanitize_text_field( $fields['name'] ) : '';
                // Price: normalize, ensure non-negative, two decimals
                $price_raw = isset( $fields['price'] ) ? str_replace( ',', '.', $fields['price'] ) : '0';
                $price_float = floatval( $price_raw );
                if ( $price_float < 0 ) {
                    $price_float = 0.0;
                }
                $price = number_format( $price_float, 2, '.', '' );
                // Capacity: absolute int
                $capacity = isset( $fields['capacity'] ) ? absint( $fields['capacity'] ) : 0;
                $sale_start = isset( $fields['sale_start'] ) ? sanitize_text_field( $fields['sale_start'] ) : '';
                $sale_end = isset( $fields['sale_end'] ) ? sanitize_text_field( $fields['sale_end'] ) : '';
                // Accept datetime-local format (YYYY-MM-DDTHH:MM) and convert to storage format (YYYY-MM-DD HH:MM).
                if ( $sale_start !== '' ) {
                    $sale_start = str_replace( 'T', ' ', $sale_start );
                    $sale_start = trim( $sale_start );
                    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $sale_start ) ) {
                        $sale_start = '';
                    }
                }
                if ( $sale_end !== '' ) {
                    $sale_end = str_replace( 'T', ' ', $sale_end );
                    $sale_end = trim( $sale_end );
                    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $sale_end ) ) {
                        $sale_end = '';
                    }
                }
                // If both present and out of order, swap to ensure start <= end
                if ( $sale_start !== '' && $sale_end !== '' ) {
                    $dt1 = \DateTime::createFromFormat( 'Y-m-d H:i', $sale_start, wp_timezone() );
                    $dt2 = \DateTime::createFromFormat( 'Y-m-d H:i', $sale_end, wp_timezone() );
                    if ( $dt1 instanceof \DateTimeInterface && $dt2 instanceof \DateTimeInterface ) {
                        if ( $dt2->getTimestamp() < $dt1->getTimestamp() ) {
                            // swap
                            $tmp = $sale_start;
                            $sale_start = $sale_end;
                            $sale_end = $tmp;
                        }
                    }
                }
                $description = isset( $fields['description'] ) ? sanitize_textarea_field( $fields['description'] ) : '';
                $hide_sold_out = isset( $fields['hide_sold_out'] ) && ( $fields['hide_sold_out'] === '1' || $fields['hide_sold_out'] === 1 );

                // Skip empty-default rows
                if ( $name === '' && $description === '' && $sale_start === '' && $sale_end === '' && ! $hide_sold_out && $capacity <= 0 && $price_float <= 0.0 ) {
                    continue;
                }

                $clean_tickets[] = [
                    'name' => $name,
                    'price' => $price,
                    'capacity' => $capacity,
                    'sale_start' => $sale_start,
                    'sale_end' => $sale_end,
                    'description' => $description,
                    'hide_sold_out' => $hide_sold_out,
                ];
            }
        }

        $envelope = [
            'schema' => 1,
            'tickets' => $clean_tickets,
        ];

        Ticket_Collection::save_for_event( $post_id, $envelope );
        Logger::instance()->log( "Saved tickets from metabox for event {$post_id} (count=" . count( $clean_tickets ) . ")" );
    }
}

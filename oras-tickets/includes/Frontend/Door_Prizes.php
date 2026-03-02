<?php

namespace ORAS\Tickets\Frontend;

use ORAS\Tickets\Admin\Metaboxes\Event_Door_Prizes_Metabox;
use ORAS\Tickets\Domain\Meta;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Door_Prizes { // NOSONAR legacy WP class naming

    public static function register(): void {
        add_filter( 'the_content', array( self::class, 'append_to_content' ), 30 );
        add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ), 25 );
    }

    public static function enqueue_assets(): void {
        wp_enqueue_style(
            'oras-door-prizes-frontend',
            ORAS_TICKETS_URL . 'assets/css/door-prizes-frontend.css',
            array(),
            ORAS_TICKETS_VERSION
        );
    }

    public static function append_to_content( string $content ): string {
        if ( ! is_singular( Meta::EVENT_POST_TYPE ) ) {
            return $content;
        }

        if ( ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        $event_id = get_the_ID();
        if ( ! is_int( $event_id ) || $event_id <= 0 ) {
            return $content;
        }

        $block = self::render_event_block( $event_id );
        if ( '' === $block ) {
            return $content;
        }

        return $content . $block;
    }

    public static function render_event_block( int $event_id ): string {
        $envelope = Event_Door_Prizes_Metabox::load_envelope( $event_id );
        $items    = isset( $envelope['items'] ) && is_array( $envelope['items'] ) ? $envelope['items'] : array();
        if ( empty( $items ) ) {
            return '';
        }

        $visible_items = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $visibility = isset( $item['visibility'] ) ? (string) $item['visibility'] : 'public';
            if ( ! self::can_view_visibility( $visibility ) ) {
                continue;
            }

            $visible_items[] = $item;
        }

        if ( empty( $visible_items ) ) {
            return '';
        }

        ob_start();
        ?>
        <section class="oras-door-prizes" aria-label="<?php echo esc_attr__( 'Door prizes', 'oras-tickets' ); ?>">
            <header class="oras-door-prizes__header">
                <h2><?php echo esc_html__( 'Door Prizes', 'oras-tickets' ); ?></h2>
                <p><?php echo esc_html__( 'Prizes available during this event.', 'oras-tickets' ); ?></p>
            </header>
            <ul class="oras-door-prizes__list">
                <?php foreach ( $visible_items as $item ) : ?>
                    <?php
                    $title        = isset( $item['title'] ) ? (string) $item['title'] : '';
                    $donor        = isset( $item['donor'] ) ? (string) $item['donor'] : '';
                    $value        = isset( $item['value'] ) ? (string) $item['value'] : '';
                    $external_link = isset( $item['external_link'] ) ? esc_url( (string) $item['external_link'] ) : '';
                    $display_mode = isset( $item['display_mode'] ) ? (string) $item['display_mode'] : 'inline';
                    $image_url    = self::resolve_thumbnail_url( $item );

                    if ( '' === $title ) {
                        continue;
                    }

                    if ( ! in_array( $display_mode, array( 'inline', 'hover', 'modal' ), true ) ) {
                        $display_mode = 'inline';
                    }

                    $meta_parts = array();
                    if ( '' !== $donor ) {
                        $meta_parts[] = sprintf( __( 'Donor: %s', 'oras-tickets' ), $donor );
                    }
                    if ( '' !== $value ) {
                        $meta_parts[] = sprintf( __( 'Value: %s', 'oras-tickets' ), $value );
                    }
                    $meta_text = implode( ' • ', $meta_parts );
                    ?>
                    <li class="oras-door-prizes__item oras-door-prizes__item--<?php echo esc_attr( $display_mode ); ?>">
                        <article class="oras-door-prizes__card">
                            <?php if ( '' !== $image_url ) : ?>
                                <div class="oras-door-prizes__media">
                                    <?php if ( '' !== $external_link ) : ?>
                                        <a href="<?php echo esc_url( $external_link ); ?>" target="_blank" rel="noopener noreferrer">
                                            <img src="<?php echo esc_url( $image_url ); ?>" alt="" loading="lazy" />
                                        </a>
                                    <?php else : ?>
                                        <img src="<?php echo esc_url( $image_url ); ?>" alt="" loading="lazy" />
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="oras-door-prizes__body">
                                <h3 class="oras-door-prizes__title">
                                    <?php if ( '' !== $external_link ) : ?>
                                        <a href="<?php echo esc_url( $external_link ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $title ); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html( $title ); ?>
                                    <?php endif; ?>
                                </h3>
                                <?php if ( '' !== $meta_text ) : ?>
                                    <p class="oras-door-prizes__meta"><?php echo esc_html( $meta_text ); ?></p>
                                <?php endif; ?>
                                <?php if ( '' !== $external_link ) : ?>
                                    <p class="oras-door-prizes__action">
                                        <a href="<?php echo esc_url( $external_link ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'View prize details', 'oras-tickets' ); ?></a>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </article>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function can_view_visibility( string $visibility ): bool {
        if ( 'public' === $visibility ) {
            return true;
        }

        if ( 'members' === $visibility ) {
            if ( ! is_user_logged_in() ) {
                return false;
            }

            if ( function_exists( 'pmpro_hasMembershipLevel' ) ) {
                return (bool) pmpro_hasMembershipLevel( null, get_current_user_id() );
            }

            return true;
        }

        if ( 'internal' === $visibility ) {
            return current_user_can( 'edit_posts' );
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function resolve_thumbnail_url( array $item ): string {
        $image_url = isset( $item['image_url'] ) ? esc_url( (string) $item['image_url'] ) : '';
        if ( '' !== $image_url ) {
            return $image_url;
        }

        $external_link = isset( $item['external_link'] ) ? esc_url_raw( (string) $item['external_link'] ) : '';
        if ( '' === $external_link ) {
            return '';
        }

        if ( preg_match( '/\.(png|jpe?g|gif|webp|svg)(\?.*)?$/i', $external_link ) ) {
            return esc_url( $external_link );
        }

        $response = wp_safe_remote_get(
            $external_link,
            array(
                'timeout'     => 2,
                'redirection' => 2,
            )
        );
        if ( is_wp_error( $response ) ) {
            return '';
        }

        $body = wp_remote_retrieve_body( $response );
        if ( ! is_string( $body ) || '' === $body ) {
            return '';
        }

        $patterns = array(
            '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i',
            '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\']/i',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $body, $matches ) ) {
                if ( ! empty( $matches[1] ) ) {
                    $resolved = esc_url_raw( trim( (string) $matches[1] ) );
                    if ( '' !== $resolved ) {
                        return esc_url( $resolved );
                    }
                }
            }
        }

        return '';
    }
}

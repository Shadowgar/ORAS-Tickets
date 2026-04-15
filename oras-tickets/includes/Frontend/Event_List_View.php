<?php

/**
 * Frontend list-view event CTA enhancements.
 */

namespace ORAS\Tickets\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Event_List_View
{ // NOSONAR legacy WP class naming

    public static function register(): void
    {
        add_action(
            'wp_enqueue_scripts',
            array( self::class, 'enqueueAssets' ),
            20
        );
        add_filter(
            'tribe_template_pre_html:events/v2/list/event/title',
            array( self::class, 'filterListEventTitleHtml' ),
            20,
            5
        );
        add_filter(
            'tribe_template_pre_html:events/v2/latest-past/event/title',
            array( self::class, 'filterListEventTitleHtml' ),
            20,
            5
        );
    }

    public static function enqueueAssets(): void
    {
        if (
            is_admin()
            || ! function_exists( 'tribe_is_list_view' )
            || ! tribe_is_list_view()
        ) {
            return;
        }

        wp_enqueue_style(
            'oras-event-list-view',
            ORAS_TICKETS_URL . 'assets/css/event-list-view.css',
            array(),
            ORAS_TICKETS_VERSION
        );

        wp_enqueue_script(
            'oras-event-list-view',
            ORAS_TICKETS_URL . 'assets/js/event-list-view.js',
            array(),
            ORAS_TICKETS_VERSION,
            true
        );

        wp_localize_script(
            'oras-event-list-view',
            'orasEventListView',
            array(
                'ctaText' => __( '(View Event Details)', 'oras-tickets' ),
            )
        );
    }

    /**
     * Inject an explicit click-through CTA into TEC list-view event titles.
     *
     * @param mixed                     $html     Current rendered HTML.
     * @param mixed                     $file     Template file path.
     * @param mixed                     $name     Template slug.
     * @param mixed                     $template Template instance.
     * @param array<string,mixed>|mixed $context  Template context.
     *
     * @return mixed
     */
    public static function filterListEventTitleHtml( $html, $file, $name, $template, $context )
    {
        unset( $file, $name );

        if ( ! is_string( $html ) || '' === $html ) {
            return $html;
        }

        if ( false !== strpos( $html, 'oras-event-list-view-link' ) ) {
            return $html;
        }

        $event = null;
        if ( is_array( $context ) && isset( $context['event'] ) && $context['event'] instanceof \WP_Post ) {
            $event = $context['event'];
        } elseif ( is_object( $template ) && method_exists( $template, 'get' ) ) {
            $candidate = $template->get( 'event' );
            if ( $candidate instanceof \WP_Post ) {
                $event = $candidate;
            }
        }

        if ( ! $event instanceof \WP_Post ) {
            return $html;
        }

        $permalink = get_permalink( $event );
        if ( ! is_string( $permalink ) || '' === $permalink ) {
            return $html;
        }

        $cta = sprintf(
            ' <a href="%1$s" class="tribe-common-anchor-thin oras-event-list-view-link">%2$s</a>',
            esc_url( $permalink ),
            esc_html__( '(View Event Details)', 'oras-tickets')
        );

        $updated_html = preg_replace( '/<\/h4>\s*$/', $cta . '</h4>', $html, 1 );

        return is_string( $updated_html ) ? $updated_html : $html . $cta;
    }
}
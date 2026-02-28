<?php

namespace ORAS\Tickets\Templates;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Template_Loader { // NOSONAR legacy WP class naming

    public static function register(): void {
        add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
        add_filter( 'template_include', array( self::class, 'template_include' ), 99 );
    }

    public static function enqueue_assets(): void {
        if ( ! is_singular( 'oras_speaker' ) ) {
            return;
        }

        wp_enqueue_style(
            'oras-speaker-single',
            ORAS_TICKETS_URL . 'assets/css/speaker.css',
            array(),
            ORAS_TICKETS_VERSION
        );
    }

    public static function template_include( string $template ): string {
        if ( ! is_singular( 'oras_speaker' ) ) {
            return $template;
        }

        $speaker_template = ORAS_TICKETS_DIR . 'templates/single-oras_speaker.php';
        if ( file_exists( $speaker_template ) ) {
            return $speaker_template;
        }

        return $template;
    }
}

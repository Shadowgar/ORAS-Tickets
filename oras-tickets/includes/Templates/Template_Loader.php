<?php

namespace ORAS\Tickets\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Template_Loader {

	public static function register(): void {
		add_filter( 'template_include', array( self::class, 'template_include' ), 99 );
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

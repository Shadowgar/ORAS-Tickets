<?php
/**
 * Verify that the plugin header and runtime asset version remain synchronized.
 */

$plugin_file = dirname( __DIR__ ) . '/oras-tickets/oras-tickets.php';
$source      = is_file( $plugin_file ) ? (string) file_get_contents( $plugin_file ) : '';

if ( ! preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)\s*$/m', $source, $header_match ) ) {
	fwrite( STDERR, "FAIL: Plugin header version was not found.\n" );
	exit( 1 );
}

if ( ! preg_match( "/define\(\s*'ORAS_TICKETS_VERSION'\s*,\s*'([0-9.]+)'\s*\)/", $source, $constant_match ) ) {
	fwrite( STDERR, "FAIL: ORAS_TICKETS_VERSION was not found.\n" );
	exit( 1 );
}

if ( $header_match[1] !== $constant_match[1] ) {
	fwrite(
		STDERR,
		sprintf(
			"FAIL: Plugin header version %s does not match ORAS_TICKETS_VERSION %s.\n",
			$header_match[1],
			$constant_match[1]
		)
	);
	exit( 1 );
}

echo "PASS: ORAS-Tickets version metadata matches ({$header_match[1]}).\n";

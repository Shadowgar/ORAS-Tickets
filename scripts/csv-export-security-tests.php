<?php
/**
 * CSV export hardening checks.
 *
 * Runs inside wp-env via:
 *   wp eval-file /tmp/oras-csv-export-security-tests.php
 */

use ORAS\Tickets\Admin\Csv_Export_Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

final class Oras_Csv_Security_Test_Exception extends RuntimeException {}

/**
 * @throws Oras_Csv_Security_Test_Exception
 */
function oras_csv_security_fail( string $message ): void {
	throw new Oras_Csv_Security_Test_Exception( $message );
}

/**
 * @param mixed $actual
 * @param mixed $expected
 *
 * @throws Oras_Csv_Security_Test_Exception
 */
function oras_csv_security_assert_same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		oras_csv_security_fail(
			sprintf(
				'%s (expected=%s actual=%s)',
				$message,
				wp_json_encode( $expected ),
				wp_json_encode( $actual )
			)
		);
	}

	echo 'PASS: ' . $message . "\n";
}

/**
 * @throws Oras_Csv_Security_Test_Exception
 */
function oras_csv_security_assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		oras_csv_security_fail( $message );
	}

	echo 'PASS: ' . $message . "\n";
}

/**
 * @throws Oras_Csv_Security_Test_Exception
 */
function oras_csv_security_run_checks(): void {
	oras_csv_security_assert_true( class_exists( Csv_Export_Sanitizer::class ), 'CSV sanitizer class exists' );

	oras_csv_security_assert_same(
		Csv_Export_Sanitizer::safe_cell( '=1+1' ),
		"'=1+1",
		'Leading equals is neutralized'
	);

	oras_csv_security_assert_same(
		Csv_Export_Sanitizer::safe_cell( ' +SUM(A1:A2)' ),
		"' +SUM(A1:A2)",
		'Leading whitespace + plus is neutralized'
	);

	oras_csv_security_assert_same(
		Csv_Export_Sanitizer::safe_cell( 'safe value' ),
		'safe value',
		'Normal value remains unchanged'
	);

	$row = array(
		'=cmd',
		'safe',
		'@risk',
		123,
	);
	$safe_row = Csv_Export_Sanitizer::safe_row( $row );

	oras_csv_security_assert_same( $safe_row[0], "'=cmd", 'Row sanitization neutralizes first formula cell' );
	oras_csv_security_assert_same( $safe_row[1], 'safe', 'Row sanitization keeps safe cell unchanged' );
	oras_csv_security_assert_same( $safe_row[2], "'@risk", 'Row sanitization neutralizes @ formula cell' );
	oras_csv_security_assert_same( $safe_row[3], 123, 'Row sanitization preserves non-string values' );
}

try {
	oras_csv_security_run_checks();
	echo "CSV export security checks passed.\n";
} catch ( Throwable $e ) {
	fwrite( STDERR, 'CSV export security checks failed: ' . $e->getMessage() . "\n" );
	exit( 1 );
}


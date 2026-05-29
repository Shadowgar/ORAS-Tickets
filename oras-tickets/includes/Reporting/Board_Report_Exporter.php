<?php

namespace ORAS\Tickets\Reporting;

use ORAS\Tickets\Security\CsvSafety;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Board_Report_Exporter {

	public const COLUMNS = array(
		'name'            => 'Name',
		'email'           => 'Email',
		'phone'           => 'Phone',
		'address_summary' => 'Address',
		'item_label'      => 'Item / Ticket / Pass',
		'quantity'        => 'Quantity',
		'order_status'    => 'Order Status',
		'order_date'      => 'Order Date',
		'source'          => 'Source',
		'note'            => 'Note',
	);

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	public function output_csv( array $rows, string $filename ): void {
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		if ( ! $output ) {
			return;
		}

		fputcsv( $output, CsvSafety::row( array_values( self::COLUMNS ) ) );
		foreach ( $rows as $row ) {
			fputcsv( $output, CsvSafety::row( $this->row_to_csv_values( $row ) ) );
		}

		fclose( $output );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	public function output_spreadsheet( array $rows, string $filename ): void {
		header( 'Content-Type: application/vnd.ms-excel; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		echo '<table border="1"><thead><tr>';
		foreach ( self::COLUMNS as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( self::COLUMNS as $key => $label ) {
				$value = isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? (string) $row[ $key ] : '';
				echo '<td>' . esc_html( $value ) . '</td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	public function output_pdf( array $rows, string $filename ): void {
		$pdf = $this->build_simple_pdf( $rows );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		echo $pdf;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<int,string>
	 */
	public function row_to_csv_values( array $row ): array {
		$values = array();
		foreach ( self::COLUMNS as $key => $label ) {
			$value = isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? (string) $row[ $key ] : '';
			$values[] = $value;
		}

		return $values;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function build_simple_pdf( array $rows ): string {
		$lines = array();
		$lines[] = 'ORAS Board Report';
		$lines[] = gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		$lines[] = 'Total Rows: ' . count( $rows );
		$lines[] = '';

		foreach ( $rows as $index => $row ) {
			$lines[] = 'Row ' . (string) ( $index + 1 );
			foreach ( self::COLUMNS as $key => $label ) {
				$raw_value = isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? (string) $row[ $key ] : '';
				$value = $this->normalize_pdf_cell( $raw_value );
				$field_text = $label . ': ' . $value;
				$wrapped = $this->wrap_pdf_text( $field_text, 90 );
				foreach ( $wrapped as $wrapped_line ) {
					$lines[] = $wrapped_line;
				}
			}
			$lines[] = str_repeat( '-', 90 );
		}

		$line_height = 14;
		$start_y = 760;
		$bottom_y = 40;
		$max_lines_per_page = max( 1, (int) floor( ( $start_y - $bottom_y ) / $line_height ) );
		$pages = array_chunk( $lines, $max_lines_per_page );

		$objects = array();
		$object_index = 1;
		$catalog_id = $object_index++;
		$pages_id = $object_index++;
		$page_ids = array();
		$content_ids = array();
		$font_id = $object_index++;

		foreach ( $pages as $_page ) {
			$page_ids[] = $object_index++;
			$content_ids[] = $object_index++;
		}

		$objects[ $catalog_id ] = '<< /Type /Catalog /Pages ' . $pages_id . ' 0 R >>';
		$kids = array_map(
			static function ( int $id ): string {
				return $id . ' 0 R';
			},
			$page_ids
		);
		$objects[ $pages_id ] = '<< /Type /Pages /Kids [ ' . implode( ' ', $kids ) . ' ] /Count ' . count( $page_ids ) . ' >>';
		$objects[ $font_id ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

		foreach ( $pages as $idx => $page_lines ) {
			$page_id = $page_ids[ $idx ];
			$content_id = $content_ids[ $idx ];

			$content = "BT\n/F1 10 Tf\n";
			$y = $start_y;
			foreach ( $page_lines as $line ) {
				$content .= sprintf( "1 0 0 1 40 %d Tm (%s) Tj\n", $y, $this->pdf_escape( $line ) );
				$y -= $line_height;
			}
			$content .= "ET\n";

			$objects[ $page_id ] = '<< /Type /Page /Parent ' . $pages_id . ' 0 R /MediaBox [0 0 612 792] /Contents ' . $content_id . ' 0 R /Resources << /Font << /F1 ' . $font_id . ' 0 R >> >> >>';
			$objects[ $content_id ] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "endstream";
		}

		$pdf = "%PDF-1.4\n";
		$offsets = array( 0 );
		ksort( $objects );
		foreach ( $objects as $id => $body ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xref_start = strlen( $pdf );
		$size = max( array_keys( $objects ) ) + 1;
		$pdf .= "xref\n0 " . $size . "\n";
		$pdf .= "0000000000 65535 f \n";
		for ( $i = 1; $i < $size; $i++ ) {
			$offset = $offsets[ $i ] ?? 0;
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}
		$pdf .= "trailer\n<< /Size " . $size . " /Root " . $catalog_id . " 0 R >>\nstartxref\n" . $xref_start . "\n%%EOF";

		return $pdf;
	}

	private function pdf_escape( string $value ): string {
		return str_replace(
			array( '\\', '(', ')' ),
			array( '\\\\', '\\(', '\\)' ),
			$value
		);
	}

	private function normalize_pdf_cell( string $value ): string {
		$ascii = preg_replace( '/[^\x20-\x7E]/', ' ', $value );
		if ( ! is_string( $ascii ) ) {
			return '';
		}

		$flat = preg_replace( '/\s+/', ' ', trim( $ascii ) );
		if ( ! is_string( $flat ) ) {
			return '';
		}

		return substr( $flat, 0, 240 );
	}

	/**
	 * @return array<int,string>
	 */
	private function wrap_pdf_text( string $text, int $max_length ): array {
		$trimmed = trim( $text );
		if ( '' === $trimmed ) {
			return array( '' );
		}

		$wrapped = wordwrap( $trimmed, $max_length, "\n", true );
		if ( ! is_string( $wrapped ) ) {
			return array( $trimmed );
		}

		return explode( "\n", $wrapped );
	}
}

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
		'question_answers' => 'Event Questions',
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
				$value = $this->get_column_value( $row, $key );
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
			$values[] = $this->get_column_value( $row, $key );
		}

		return $values;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function get_column_value( array $row, string $key ): string {
		if ( 'question_answers' === $key ) {
			return $this->format_question_answers( $row[ $key ] ?? array() );
		}

		return isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? (string) $row[ $key ] : '';
	}

	/**
	 * @param mixed $answers
	 */
	private function format_question_answers( $answers ): string {
		if ( ! is_array( $answers ) ) {
			return '';
		}

		$parts = array();
		foreach ( $answers as $answer ) {
			if ( ! is_array( $answer ) ) {
				continue;
			}

			$label = isset( $answer['label'] ) && is_scalar( $answer['label'] ) ? sanitize_text_field( (string) $answer['label'] ) : '';
			$value = isset( $answer['display_value'] ) && is_scalar( $answer['display_value'] ) ? sanitize_text_field( (string) $answer['display_value'] ) : '';
			if ( '' !== $label && '' !== $value ) {
				$parts[] = $label . ': ' . $value;
			}
		}

		return implode( '; ', $parts );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function build_simple_pdf( array $rows ): string {
		$page_width = 842;
		$page_height = 595;
		$margin_x = 26;
		$margin_y = 24;
		$header_y = $page_height - $margin_y;
		$table_top_y = $page_height - 92;
		$table_bottom_y = 34;
		$header_row_h = 20;
		$data_line_h = 10;
		$row_padding = 4;
		$col_widths = array( 72, 108, 62, 105, 82, 36, 58, 70, 50, 48, 110 );
		$body_height_available = ( $table_top_y - $table_bottom_y ) - $header_row_h;

		$prepared_rows = array();
		foreach ( $rows as $row ) {
			$row_cells = array();
			$row_max_lines = 1;
			$col_index = 0;
			foreach ( self::COLUMNS as $key => $label ) {
				$raw_value = $this->get_column_value( $row, $key );
				$normalized = $this->normalize_pdf_cell( $raw_value );
				$wrapped_lines = $this->wrap_for_column( $normalized, $col_widths[ $col_index ] );
				$row_cells[] = $wrapped_lines;
				$row_max_lines = max( $row_max_lines, count( $wrapped_lines ) );
				$col_index++;
			}

			$prepared_rows[] = array(
				'cells'      => $row_cells,
				'row_height' => ( $row_max_lines * $data_line_h ) + $row_padding,
			);
		}

		$pages = array();
		$current_page = array();
		$current_height = 0;
		foreach ( $prepared_rows as $prepared_row ) {
			$row_height = (int) $prepared_row['row_height'];
			if ( $current_height > 0 && ( $current_height + $row_height ) > $body_height_available ) {
				$pages[] = $current_page;
				$current_page = array();
				$current_height = 0;
			}
			$current_page[] = $prepared_row;
			$current_height += $row_height;
		}
		if ( ! empty( $current_page ) ) {
			$pages[] = $current_page;
		}
		if ( empty( $pages ) ) {
			$pages = array( array() );
		}

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
		$font_bold_id = $object_index++;
		$objects[ $font_id ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
		$objects[ $font_bold_id ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

		foreach ( $pages as $idx => $page_rows ) {
			$page_id = $page_ids[ $idx ];
			$content_id = $content_ids[ $idx ];

			$content = "0.1 w\n";
			$content .= "0 0 0 RG\n";
			$content .= "BT\n/F2 15 Tf\n";
			$content .= sprintf( "1 0 0 1 %d %d Tm (%s) Tj\n", $margin_x, $header_y, $this->pdf_escape( 'ORAS Board Report' ) );
			$content .= "/F1 10 Tf\n";
			$content .= sprintf( "1 0 0 1 %d %d Tm (%s) Tj\n", $margin_x, $header_y - 16, $this->pdf_escape( gmdate( 'Y-m-d H:i:s' ) . ' UTC' ) );
			$content .= sprintf( "1 0 0 1 %d %d Tm (%s) Tj\n", $margin_x, $header_y - 30, $this->pdf_escape( 'Total Rows: ' . count( $rows ) ) );
			$content .= sprintf( "1 0 0 1 %d %d Tm (%s) Tj\n", $page_width - 110, $header_y - 16, $this->pdf_escape( 'Page ' . (string) ( $idx + 1 ) . ' of ' . count( $pages ) ) );
			$content .= "ET\n";

			$x_positions = array( $margin_x );
			foreach ( $col_widths as $col_width ) {
				$x_positions[] = (int) end( $x_positions ) + $col_width;
			}

			$table_top = $table_top_y;
			$table_height = $header_row_h;
			foreach ( $page_rows as $page_row ) {
				$table_height += (int) $page_row['row_height'];
			}
			$table_bottom = $table_top - $table_height;

			foreach ( $x_positions as $x ) {
				$content .= sprintf( "%d %d m %d %d l S\n", $x, $table_top, $x, $table_bottom );
			}

			$y_lines = array( $table_top, $table_top - $header_row_h );
			$current_row_bottom = $table_top - $header_row_h;
			foreach ( $page_rows as $page_row ) {
				$current_row_bottom -= (int) $page_row['row_height'];
				$y_lines[] = $current_row_bottom;
			}
			foreach ( $y_lines as $y_line ) {
				$content .= sprintf( "%d %d m %d %d l S\n", $margin_x, $y_line, $margin_x + array_sum( $col_widths ), $y_line );
			}

			$content .= "BT\n/F2 8 Tf\n";
			$col_idx = 0;
			$header_text_y = $table_top - 14;
			foreach ( self::COLUMNS as $label ) {
				$x = $x_positions[ $col_idx ] + 2;
				$content .= sprintf( "1 0 0 1 %d %d Tm (%s) Tj\n", $x, $header_text_y, $this->pdf_escape( $this->truncate_for_column( $label, $col_widths[ $col_idx ] ) ) );
				$col_idx++;
			}
			$content .= "ET\n";

			$content .= "BT\n/F1 8 Tf\n";
			$current_row_top = $table_top - $header_row_h;
			foreach ( $page_rows as $page_row ) {
				$row_height = (int) $page_row['row_height'];
				$line_start_y = $current_row_top - 11;
				foreach ( $page_row['cells'] as $col_idx => $cell_lines ) {
					$x = $x_positions[ $col_idx ] + 2;
					foreach ( $cell_lines as $line_index => $line_value ) {
						$line_y = $line_start_y - ( $line_index * $data_line_h );
						$content .= sprintf( "1 0 0 1 %d %d Tm (%s) Tj\n", $x, $line_y, $this->pdf_escape( $line_value ) );
					}
				}
				$current_row_top -= $row_height;
			}
			$content .= "ET\n";

			$objects[ $page_id ] = '<< /Type /Page /Parent ' . $pages_id . ' 0 R /MediaBox [0 0 ' . $page_width . ' ' . $page_height . '] /Contents ' . $content_id . ' 0 R /Resources << /Font << /F1 ' . $font_id . ' 0 R /F2 ' . $font_bold_id . ' 0 R >> >> >>';
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

	private function truncate_for_column( string $value, int $column_width ): string {
		$max_chars = max( 4, (int) floor( ( $column_width - 6 ) / 4.2 ) );
		if ( strlen( $value ) <= $max_chars ) {
			return $value;
		}
		return substr( $value, 0, $max_chars );
	}

	/**
	 * @return array<int,string>
	 */
	private function wrap_for_column( string $value, int $column_width ): array {
		$max_chars = max( 4, (int) floor( ( $column_width - 6 ) / 4.2 ) );
		$safe_value = trim( $value );
		if ( '' === $safe_value ) {
			return array( '' );
		}

		$wrapped = wordwrap( $safe_value, $max_chars, "\n", true );
		if ( ! is_string( $wrapped ) ) {
			return array( $safe_value );
		}

		return explode( "\n", $wrapped );
	}
}

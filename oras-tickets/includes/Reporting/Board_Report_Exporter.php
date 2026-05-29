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
		$page_width = 842;
		$page_height = 595;
		$margin_x = 26;
		$margin_y = 24;
		$header_y = $page_height - $margin_y;
		$table_top_y = $page_height - 92;
		$table_bottom_y = 34;
		$header_row_h = 20;
		$data_row_h = 18;
		$col_widths = array( 78, 120, 70, 120, 95, 40, 68, 78, 58, 55 );
		$max_rows_per_page = max( 1, (int) floor( ( ( $table_top_y - $table_bottom_y ) - $header_row_h ) / $data_row_h ) );

		$data_rows = array();
		foreach ( $rows as $row ) {
			$cells = array();
			$col_index = 0;
			foreach ( self::COLUMNS as $key => $label ) {
				$raw_value = isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? (string) $row[ $key ] : '';
				$cells[] = $this->truncate_for_column( $this->normalize_pdf_cell( $raw_value ), $col_widths[ $col_index ] );
				$col_index++;
			}
			$data_rows[] = $cells;
		}

		$pages = array_chunk( $data_rows, $max_rows_per_page );
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
			$table_height = $header_row_h + ( count( $page_rows ) * $data_row_h );
			$table_bottom = $table_top - $table_height;

			foreach ( $x_positions as $x ) {
				$content .= sprintf( "%d %d m %d %d l S\n", $x, $table_top, $x, $table_bottom );
			}

			$y_lines = array( $table_top, $table_top - $header_row_h );
			for ( $r = 0; $r < count( $page_rows ); $r++ ) {
				$y_lines[] = $table_top - $header_row_h - ( ( $r + 1 ) * $data_row_h );
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
			foreach ( $page_rows as $row_idx => $cells ) {
				$row_y = $table_top - $header_row_h - ( $row_idx * $data_row_h ) - 12;
				foreach ( $cells as $col_idx => $cell ) {
					$x = $x_positions[ $col_idx ] + 2;
					$content .= sprintf( "1 0 0 1 %d %d Tm (%s) Tj\n", $x, $row_y, $this->pdf_escape( $cell ) );
				}
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
		if ( $max_chars <= 3 ) {
			return substr( $value, 0, $max_chars );
		}

		return substr( $value, 0, $max_chars - 3 ) . '...';
	}
}

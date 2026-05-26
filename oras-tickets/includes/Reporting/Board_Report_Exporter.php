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
}

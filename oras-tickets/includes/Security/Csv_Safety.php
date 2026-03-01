<?php

namespace ORAS\Tickets\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CsvSafety {

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function cell( $value ) {
        if ( ! is_string( $value ) ) {
            return $value;
        }

        $trimmed = ltrim( $value );
        if ( $trimmed !== '' && preg_match( '/^[=+\-@]/', $trimmed ) ) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $row
     * @return array<int|string, mixed>
     */
    public static function row( array $row ): array {
        foreach ( $row as $key => $value ) {
            $row[ $key ] = self::cell( $value );
        }

        return $row;
    }
}

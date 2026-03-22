<?php

namespace ORAS\Tickets\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class TicketCheckinToken {

    private const META_KEY_CHECKIN_UNITS = '_oras_checkin_units_v1';

    /**
     * @return array{token:string,verify_url:string}
     */
    public static function issue( int $order_id, int $event_id, int $item_id, int $unit_number ): array {
        $payload = array(
            'oid' => $order_id,
            'eid' => $event_id,
            'iid' => $item_id,
            'u'   => $unit_number,
            'v'   => 1,
        );

        $payload_json = wp_json_encode( $payload );
        if ( ! is_string( $payload_json ) || '' === $payload_json ) {
            return array(
                'token'      => '',
                'verify_url' => '',
            );
        }

        $payload_b64 = self::base64urlEncode( $payload_json );
        $signature   = self::signPayload( $payload_b64 );
        $token       = $payload_b64 . '.' . $signature;

        return array(
            'token'      => $token,
            'verify_url' => rest_url( 'oras-tickets/v1/checkin/verify?token=' . rawurlencode( $token ) ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function verify( string $token ): array {
        $decoded = self::decodeToken( $token );
        if ( ! $decoded['ok'] ) {
            return $decoded;
        }

        if ( ! function_exists( 'wc_get_order' ) ) {
            return array(
                'ok'      => false,
                'code'    => 'woo_missing',
                'message' => 'WooCommerce order API unavailable.',
                'status'  => 503,
            );
        }

        $order = wc_get_order( (int) $decoded['order_id'] );
        if ( ! ( $order instanceof \WC_Order ) ) {
            return array(
                'ok'      => false,
                'code'    => 'order_not_found',
                'message' => 'Order not found.',
                'status'  => 404,
            );
        }

        $item = self::findLineItem( $order, (int) $decoded['item_id'] );
        if ( ! $item ) {
            return array(
                'ok'      => false,
                'code'    => 'item_not_found',
                'message' => 'Ticket line item not found.',
                'status'  => 404,
            );
        }

        $event_id = self::resolveItemEventId( $item );
        if ( $event_id <= 0 || $event_id !== (int) $decoded['event_id'] ) {
            return array(
                'ok'      => false,
                'code'    => 'event_mismatch',
                'message' => 'Ticket event mismatch.',
                'status'  => 400,
            );
        }

        $quantity = max( 1, (int) $item->get_quantity() );
        $unit     = (int) $decoded['unit_number'];
        if ( $unit > $quantity ) {
            return array(
                'ok'      => false,
                'code'    => 'unit_out_of_range',
                'message' => 'Ticket unit number is invalid for this line item.',
                'status'  => 400,
            );
        }

        $checked_units  = self::getCheckedUnits( $item );
        $unit_key       = (string) $unit;
        $is_checked     = isset( $checked_units[ $unit_key ] ) && is_array( $checked_units[ $unit_key ] );
        $checked_record = $is_checked ? $checked_units[ $unit_key ] : array();

        return array(
            'ok'           => true,
            'code'         => 'valid',
            'status'       => 200,
            'order_id'     => (int) $decoded['order_id'],
            'event_id'     => $event_id,
            'item_id'      => (int) $decoded['item_id'],
            'unit_number'  => $unit,
            'ticket_name'  => (string) $item->get_name(),
            'is_checked_in'=> $is_checked,
            'checked_at'   => $is_checked ? (string) ( $checked_record['checked_at'] ?? '' ) : '',
            'checked_by'   => $is_checked ? (int) ( $checked_record['checked_by'] ?? 0 ) : 0,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function markCheckedIn( string $token, int $checker_user_id ): array {
        $verified = self::verify( $token );
        if ( ! $verified['ok'] ) {
            return $verified;
        }

        $error = null;

        if ( ! function_exists( 'wc_get_order' ) ) {
            $error = array(
                'ok'      => false,
                'code'    => 'woo_missing',
                'message' => 'WooCommerce order API unavailable.',
                'status'  => 503,
            );
        }

        $order = isset( $error ) ? null : wc_get_order( (int) $verified['order_id'] );
        if ( ! isset( $error ) && ! ( $order instanceof \WC_Order ) ) {
            $error = array(
                'ok'      => false,
                'code'    => 'order_not_found',
                'message' => 'Order not found.',
                'status'  => 404,
            );
        }

        $item = ( ! isset( $error ) && $order instanceof \WC_Order ) ? self::findLineItem( $order, (int) $verified['item_id'] ) : null;
        if ( ! isset( $error ) && ! $item ) {
            $error = array(
                'ok'      => false,
                'code'    => 'item_not_found',
                'message' => 'Ticket line item not found.',
                'status'  => 404,
            );
        }

        if ( isset( $error ) ) {
            return $error;
        }

        $checked_units = self::getCheckedUnits( $item );
        $unit_key      = (string) (int) $verified['unit_number'];
        if ( ! isset( $checked_units[ $unit_key ] ) || ! is_array( $checked_units[ $unit_key ] ) ) {
            $checked_units[ $unit_key ] = array(
                'checked_at' => gmdate( 'c' ),
                'checked_by' => max( 0, $checker_user_id ),
            );

            $item->update_meta_data( self::META_KEY_CHECKIN_UNITS, $checked_units );
            $item->save();
        }

        return self::verify( $token );
    }

    /**
     * @return array<string,mixed>
     */
    public static function unmarkCheckedIn( string $token ): array {
        $verified = self::verify( $token );
        if ( ! $verified['ok'] ) {
            return $verified;
        }

        $error = null;

        if ( ! function_exists( 'wc_get_order' ) ) {
            $error = array(
                'ok'      => false,
                'code'    => 'woo_missing',
                'message' => 'WooCommerce order API unavailable.',
                'status'  => 503,
            );
        }

        $order = isset( $error ) ? null : wc_get_order( (int) $verified['order_id'] );
        if ( ! isset( $error ) && ! ( $order instanceof \WC_Order ) ) {
            $error = array(
                'ok'      => false,
                'code'    => 'order_not_found',
                'message' => 'Order not found.',
                'status'  => 404,
            );
        }

        $item = ( ! isset( $error ) && $order instanceof \WC_Order ) ? self::findLineItem( $order, (int) $verified['item_id'] ) : null;
        if ( ! isset( $error ) && ! $item ) {
            $error = array(
                'ok'      => false,
                'code'    => 'item_not_found',
                'message' => 'Ticket line item not found.',
                'status'  => 404,
            );
        }

        if ( isset( $error ) ) {
            return $error;
        }

        $checked_units = self::getCheckedUnits( $item );
        $unit_key      = (string) (int) $verified['unit_number'];
        if ( isset( $checked_units[ $unit_key ] ) ) {
            unset( $checked_units[ $unit_key ] );

            $item->update_meta_data( self::META_KEY_CHECKIN_UNITS, $checked_units );
            $item->save();
        }

        return self::verify( $token );
    }

    /**
     * @return array<string,mixed>
     */
    private static function decodeToken( string $token ): array {
        $result = array(
            'ok'      => false,
            'code'    => 'invalid_token',
            'message' => 'Token is missing or malformed.',
            'status'  => 400,
        );

        $token = trim( $token );
        if ( '' !== $token && strpos( $token, '.' ) !== false ) {
            $parts = explode( '.', $token, 2 );
            if ( count( $parts ) !== 2 ) {
                $result = array(
                    'ok'      => false,
                    'code'    => 'invalid_token',
                    'message' => 'Token is malformed.',
                    'status'  => 400,
                );
            } else {
                $payload_b64 = (string) $parts[0];
                $signature   = (string) $parts[1];
                $expected    = self::signPayload( $payload_b64 );

                if ( ! hash_equals( $expected, $signature ) ) {
                    $result = array(
                        'ok'      => false,
                        'code'    => 'invalid_signature',
                        'message' => 'Token signature is invalid.',
                        'status'  => 403,
                    );
                } else {
                    $payload_json = self::base64urlDecode( $payload_b64 );
                    if ( '' === $payload_json ) {
                        $result = array(
                            'ok'      => false,
                            'code'    => 'invalid_payload',
                            'message' => 'Token payload could not be decoded.',
                            'status'  => 400,
                        );
                    } else {
                        $fields = self::extractTokenFields( json_decode( $payload_json, true ) );
                        if ( null === $fields ) {
                            $result = array(
                                'ok'      => false,
                                'code'    => 'invalid_payload',
                                'message' => 'Token payload fields are invalid.',
                                'status'  => 400,
                            );
                        } else {
                            $result = array(
                                'ok'          => true,
                                'order_id'    => $fields['order_id'],
                                'event_id'    => $fields['event_id'],
                                'item_id'     => $fields['item_id'],
                                'unit_number' => $fields['unit_number'],
                            );
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param mixed $payload
     * @return array{order_id:int,event_id:int,item_id:int,unit_number:int}|null
     */
    private static function extractTokenFields( $payload ): ?array {
        if ( ! is_array( $payload ) ) {
            return null;
        }

        $order_id    = isset( $payload['oid'] ) ? (int) $payload['oid'] : 0;
        $event_id    = isset( $payload['eid'] ) ? (int) $payload['eid'] : 0;
        $item_id     = isset( $payload['iid'] ) ? (int) $payload['iid'] : 0;
        $unit_number = isset( $payload['u'] ) ? (int) $payload['u'] : 0;
        $version     = isset( $payload['v'] ) ? (int) $payload['v'] : 0;

        if ( $order_id <= 0 || $event_id <= 0 || $item_id <= 0 || $unit_number <= 0 || $version !== 1 ) {
            return null;
        }

        return array(
            'order_id'    => $order_id,
            'event_id'    => $event_id,
            'item_id'     => $item_id,
            'unit_number' => $unit_number,
        );
    }

    private static function signPayload( string $payload_b64 ): string {
        return hash_hmac( 'sha256', $payload_b64, wp_salt( 'auth' ) );
    }

    private static function base64urlEncode( string $input ): string {
        return rtrim( strtr( base64_encode( $input ), '+/', '-_' ), '=' );
    }

    private static function base64urlDecode( string $input ): string {
        $remainder = strlen( $input ) % 4;
        if ( $remainder > 0 ) {
            $input .= str_repeat( '=', 4 - $remainder );
        }

        $decoded = base64_decode( strtr( $input, '-_', '+/' ), true );

        return is_string( $decoded ) ? $decoded : '';
    }

    private static function resolveItemEventId( \WC_Order_Item $item ): int {
        $event_id = (int) $item->get_meta( '_oras_ticket_event_id', true );
        if ( $event_id > 0 ) {
            return $event_id;
        }

        $product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
        if ( $product_id <= 0 ) {
            return 0;
        }

        return (int) get_post_meta( $product_id, '_oras_ticket_event_id', true );
    }

    private static function findLineItem( \WC_Order $order, int $item_id ): ?\WC_Order_Item {
        if ( $item_id <= 0 ) {
            return null;
        }

        $items = $order->get_items( 'line_item' );
        foreach ( $items as $item ) {
            if ( ! $item instanceof \WC_Order_Item ) {
                continue;
            }

            if ( (int) $item->get_id() === $item_id ) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function getCheckedUnits( \WC_Order_Item $item ): array {
        $raw = $item->get_meta( self::META_KEY_CHECKIN_UNITS, true );
        if ( ! is_array( $raw ) ) {
            return array();
        }

        $normalized = array();
        foreach ( $raw as $key => $value ) {
            if ( ! is_array( $value ) ) {
                continue;
            }

            $unit_key = (string) absint( (string) $key );
            if ( '' === $unit_key || '0' === $unit_key ) {
                continue;
            }

            $normalized[ $unit_key ] = array(
                'checked_at' => isset( $value['checked_at'] ) ? (string) $value['checked_at'] : '',
                'checked_by' => isset( $value['checked_by'] ) ? (int) $value['checked_by'] : 0,
            );
        }

        return $normalized;
    }
}

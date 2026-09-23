<?php

namespace ORAS\Tickets\Registration_Desk;

use ORAS\Tickets\Support\DbLock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bridges a desk-recorded AlfaPOS payment to one normal PMPro checkout. */
final class Membership_Credit_Service {
	private Offline_Membership_Store $store;

	public function __construct( ?Offline_Membership_Store $store = null ) {
		$this->store = $store ?? new Offline_Membership_Store();
	}

	public static function register(): void {
		add_filter( 'pmpro_check_discount_code', array( self::class, 'validate_discount' ), 20, 4 );
		add_action( 'pmpro_after_checkout', array( self::class, 'after_checkout' ), 20, 2 );
	}

	/** @param object $level @return array<string,mixed> */
	public static function discount_level_data( object $level ): array {
		return array(
			'initial_payment'   => 0.0,
			'billing_amount'    => $level->billing_amount ?? 0,
			'cycle_number'      => (int) ( $level->cycle_number ?? 0 ),
			'cycle_period'      => (string) ( $level->cycle_period ?? '' ),
			'billing_limit'     => (int) ( $level->billing_limit ?? 0 ),
			'trial_amount'      => $level->trial_amount ?? 0,
			'trial_limit'       => (int) ( $level->trial_limit ?? 0 ),
			'expiration_number' => (int) ( $level->expiration_number ?? 0 ),
			'expiration_period' => (string) ( $level->expiration_period ?? '' ),
		);
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
	public function create( array $payload, array $context ) {
		$request_uuid = strtolower( trim( (string) ( $context['request_uuid'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $request_uuid ) ) {
			return new \WP_Error( 'oras_desk_request_required', 'This request could not be safely identified.', array( 'status' => 400 ) );
		}

		// Hold the database lock through the first email status update so a replay
		// always observes the completed result of the winning request.
		return DbLock::withLock(
			'desk-membership:' . $request_uuid,
			fn() => $this->create_locked( $payload, $context, $request_uuid ),
			10
		);
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
	private function create_locked( array $payload, array $context, string $request_uuid ) {
		$existing = $this->store->find_request( $request_uuid );
		if ( $existing ) {
			return $this->public_record( $existing );
		}
		$first_name = sanitize_text_field( (string) ( $payload['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( (string) ( $payload['last_name'] ?? '' ) );
		$email      = sanitize_email( (string) ( $payload['email'] ?? '' ) );
		$payment    = sanitize_key( (string) ( $payload['payment_method'] ?? '' ) );
		$level_id   = absint( $payload['level_id'] ?? 0 );
		$mapping    = Config::membership_mapping( $level_id );
		if ( '' === $first_name || '' === $last_name || ! is_email( $email ) || ! in_array( $payment, array( 'card', 'cash', 'check' ), true ) || null === $mapping ) {
			return new \WP_Error( 'oras_desk_membership_invalid', 'Complete the name, email, membership level, and Card, Cash, or Check fields.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( '\\PMPro_Discount_Code' ) || ! function_exists( 'pmpro_getLevel' ) ) {
			return new \WP_Error( 'oras_desk_membership_unavailable', 'Membership activation is not available right now. Ask a manager for help.', array( 'status' => 503 ) );
		}
		$level = pmpro_getLevel( $level_id );
		if ( ! is_object( $level ) ) {
			return new \WP_Error( 'oras_desk_membership_level_missing', 'That membership level is no longer available.', array( 'status' => 409 ) );
		}

		$phone  = sanitize_text_field( (string) ( $payload['phone'] ?? '' ) );
		$result = Store::transaction(
			function () use ( $request_uuid, $context, $first_name, $last_name, $email, $phone, $payment, $mapping, $level ) {
				$code = $this->generate_code();
				$now  = current_datetime()->setTimezone( wp_timezone() );
				$end  = $now->modify( '+90 days' );
				/** @var class-string $discount_class_name */
				$discount_class_name = 'PMPro_Discount_Code';
				$discount_class      = new \ReflectionClass( $discount_class_name );
				$discount       = $discount_class->newInstance();
				$discount_class->getProperty( 'code' )->setValue( $discount, $code );
				$discount_class->getProperty( 'starts' )->setValue( $discount, $now->format( 'Y-m-d' ) );
				$discount_class->getProperty( 'expires' )->setValue( $discount, $end->format( 'Y-m-d' ) );
				$discount_class->getProperty( 'uses' )->setValue( $discount, 1 );
				$discount_class->getProperty( 'levels' )->setValue( $discount, array( (int) $mapping['level_id'] => self::discount_level_data( $level ) ) );
				$saved = $discount_class->getMethod( 'save' )->invoke( $discount );
				if ( ! is_object( $saved ) || empty( $saved->id ) ) {
					return new \WP_Error( 'oras_desk_credit_create_failed', 'The membership credit could not be created.', array( 'status' => 500 ) );
				}
				return $this->store->create(
					array(
						'activation_uuid'  => Store::uuid(),
						'request_uuid'     => $request_uuid,
						'event_id'         => (int) $context['event_id'],
						'first_name'       => $first_name,
						'last_name'        => $last_name,
						'email'            => $email,
						'normalized_email' => strtolower( $email ),
						'phone'            => $phone,
						'level_id'         => (int) $mapping['level_id'],
						'level_name'       => (string) $mapping['display_name'],
						'reference_price'  => (string) $mapping['price'],
						'checkout_url'     => (string) $mapping['checkout_url'],
						'payment_method'   => $payment,
						'credit_code'      => $code,
						'discount_code_id' => (int) $saved->id,
						'status'           => 'pending',
						'email_status'     => 'not_sent',
						'expires_at_utc'   => $end->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
						'actor_user_id'    => (int) $context['actor_user_id'],
						'station_uuid'     => (string) $context['station_uuid'],
						'operator_label'   => (string) $context['operator_label'],
					)
				);
			}
		);
		if ( $result instanceof \WP_Error ) {
			// A unique-key collision after a provisional PMPro save rolls the
			// transaction back. Only then may the existing activation be returned.
			$existing = $this->store->find_request( $request_uuid );
			if ( $existing ) {
				return $this->public_record( $existing );
			}
			return $result;
		}
		$sent = $this->send_email( $result );

		return $sent instanceof \WP_Error ? $sent : $this->public_record( $sent );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function resend( string $activation_uuid ) {
		$row = $this->store->find_activation( $activation_uuid );
		if ( ! $row || 'pending' !== (string) $row['status'] ) {
			return new \WP_Error( 'oras_desk_membership_not_pending', 'This activation cannot be emailed.', array( 'status' => 409 ) );
		}
		$result = $this->send_email( $row );

		return $result instanceof \WP_Error ? $result : $this->public_record( $result );
	}

	/** @param array<string,mixed> $payload @return array<string,mixed>|\WP_Error */
	public function correct_contact( string $activation_uuid, array $payload ) {
		$row = $this->store->find_activation( $activation_uuid );
		if ( ! $row || 'pending' !== (string) $row['status'] ) {
			return new \WP_Error( 'oras_desk_membership_not_pending', 'Only a pending membership activation can be corrected.', array( 'status' => 409 ) );
		}
		$first_name = sanitize_text_field( (string) ( $payload['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( (string) ( $payload['last_name'] ?? '' ) );
		$email      = sanitize_email( (string) ( $payload['email'] ?? '' ) );
		if ( '' === $first_name || '' === $last_name || ! is_email( $email ) ) {
			return new \WP_Error( 'oras_desk_membership_invalid', 'Enter a first name, last name, and valid email address.', array( 'status' => 400 ) );
		}
		$changes = array(
			'first_name'       => $first_name,
			'last_name'        => $last_name,
			'email'            => $email,
			'normalized_email' => strtolower( $email ),
			'phone'            => sanitize_text_field( (string) ( $payload['phone'] ?? '' ) ),
		);
		if ( ! hash_equals( (string) $row['normalized_email'], strtolower( $email ) ) ) {
			$changes['email_status'] = 'not_sent';
		}
		$updated = $this->store->update( $activation_uuid, $changes );

		return $updated instanceof \WP_Error ? $updated : $this->public_record( $updated );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function cancel( string $activation_uuid, int $actor_user_id, string $reason ) {
		$row = $this->store->find_activation( $activation_uuid );
		if ( ! $row || 'pending' !== (string) $row['status'] ) {
			return new \WP_Error( 'oras_desk_membership_not_pending', 'Only an unused membership credit can be cancelled.', array( 'status' => 409 ) );
		}
		if ( class_exists( '\\PMPro_Discount_Code' ) ) {
			$discount = new \PMPro_Discount_Code( (int) $row['discount_code_id'] );
			if ( is_object( $discount ) ) {
				$discount->expires = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
				$discount->uses    = 1;
				$discount->save();
			}
		}
		$updated = $this->store->update(
			$activation_uuid,
			array(
				'status'           => 'cancelled',
				'cancelled_at_utc' => Store::utc_now(),
				'cancelled_by'     => $actor_user_id,
				'cancel_reason'    => sanitize_text_field( $reason ),
			)
		);

		return $updated instanceof \WP_Error ? $updated : $this->public_record( $updated );
	}

	/** @param bool|string $okay @param object $dbcode @param int|array<int> $level_id @return bool|string */
	public static function validate_discount( $okay, object $dbcode, $level_id, string $code ) {
		if ( ! $okay ) {
			return $okay;
		}
		$row = ( new Offline_Membership_Store() )->find_code( $code );
		if ( ! $row ) {
			return $okay;
		}
		if ( 'pending' !== (string) $row['status'] ) {
			return 'This membership credit is no longer active.';
		}
		$requested_email = isset( $_REQUEST['bemail'] ) ? sanitize_email( wp_unslash( $_REQUEST['bemail'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Validation-only checkout filter.
		if ( '' === $requested_email && is_user_logged_in() ) {
			$user = wp_get_current_user();
			$requested_email = sanitize_email( (string) $user->user_email );
		}
		if ( '' !== $requested_email && ! hash_equals( (string) $row['normalized_email'], strtolower( $requested_email ) ) ) {
			return 'Use the same email address that was recorded when this membership was purchased.';
		}

		return (int) $row['level_id'] === (int) ( is_array( $level_id ) ? reset( $level_id ) : $level_id ) ? $okay : 'This membership credit is for a different membership level.';
	}

	/** @param object|null $order */
	public static function after_checkout( int $user_id, $order ): void {
		$discount_id = is_object( $order ) ? absint( $order->discount_code_id ?? 0 ) : 0;
		if ( $discount_id <= 0 ) {
			return;
		}
		global $wpdb;
		$table = Schema::table_names()['offline_memberships'];
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT activation_uuid,status FROM {$table} WHERE discount_code_id = %d", $discount_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		if ( ! is_array( $row ) || 'pending' !== (string) $row['status'] ) {
			return;
		}
		( new Offline_Membership_Store() )->update(
			(string) $row['activation_uuid'],
			array(
				'status'          => 'redeemed',
				'linked_user_id'  => $user_id,
				'redeemed_at_utc' => Store::utc_now(),
			)
		);
	}

	/** @param array<string,mixed> $row */
	public static function email_message( array $row ): string {
		return sprintf(
			"Hello %s,\n\nOil Region Astronomical Society received your %s payment at %s for %s (%s).\n\nYour payment has already been received. You SHOULD NOT BE CHARGED AGAIN.\n\nComplete your membership registration here:\n%s\n\nWhen asked for a discount or credit code, enter:\n%s\n\nUse the same email address that you gave the volunteer. This one-use code expires 90 days after purchase and covers the membership period you already paid for. Normal future renewal terms remain unchanged.\n\nIf you need help, please contact ORAS through oras.org.\n",
			(string) $row['first_name'],
			ucfirst( (string) $row['payment_method'] ),
			(string) $row['event_title'],
			(string) $row['level_name'],
			(string) $row['reference_price'],
			(string) $row['checkout_url'],
			(string) $row['credit_code']
		);
	}

	/** @param array<string,mixed> $row @return array<string,mixed>|\WP_Error */
	private function send_email( array $row ) {
		$row['event_title'] = get_the_title( (int) $row['event_id'] );
		$sent = wp_mail( (string) $row['email'], 'Complete Your ORAS Membership Registration', self::email_message( $row ) );
		$updated = $this->store->update(
			(string) $row['activation_uuid'],
			array(
				'email_status'      => $sent ? 'sent' : 'failed',
				'email_attempts'    => (int) $row['email_attempts'] + 1,
				'last_email_at_utc' => Store::utc_now(),
			)
		);
		if ( $updated instanceof \WP_Error ) {
			return $updated;
		}

		return $sent ? $updated : new \WP_Error(
			'oras_desk_membership_email_failed',
			'Membership was recorded, but the email could not be sent.',
			array(
				'status'     => 502,
				'activation' => $this->public_record( $updated, false ),
			)
		);
	}

	private function generate_code(): string {
		do {
			$raw  = strtoupper( wp_generate_password( 20, false, false ) );
			$code = 'ORAS-' . substr( $raw, 0, 5 ) . '-' . substr( $raw, 5, 5 ) . '-' . substr( $raw, 10, 5 );
		} while ( null !== $this->store->find_code( $code ) );

		return $code;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function public_record( array $row, bool $email_sent = true ): array {
		return array(
			'activation_uuid' => (string) $row['activation_uuid'],
			'first_name'      => (string) $row['first_name'],
			'last_name'       => (string) $row['last_name'],
			'email'           => (string) $row['email'],
			'phone'           => (string) $row['phone'],
			'level_id'        => (int) $row['level_id'],
			'level_name'      => (string) $row['level_name'],
			'payment_method'  => (string) $row['payment_method'],
			'credit_code'     => (string) $row['credit_code'],
			'status'          => (string) $row['status'],
			'email_status'    => (string) $row['email_status'],
			'email_sent'      => $email_sent && 'sent' === (string) $row['email_status'],
			'expires_at_utc'  => (string) $row['expires_at_utc'],
		);
	}
}

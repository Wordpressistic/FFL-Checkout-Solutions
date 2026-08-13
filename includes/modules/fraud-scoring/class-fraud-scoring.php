<?php
/**
 * Risk scoring (Section 10.8).
 *
 * A transparent, rules-based score computed from signals this plugin already
 * has. There is no external fraud vendor and no model — every point is
 * attributable to a named rule the store can read and re-weight.
 *
 * It is advisory, and that is enforced, not just stated: nothing in this class
 * writes a transfer status, cancels an order or blocks a checkout. A high score
 * puts a row in front of a person.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Services\Dealer_Service;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Rules-based risk assessment.
 */
class Fraud_Scoring {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'fflcs_transfer_created', array( $this, 'score_transfer' ), 20, 2 );
	}

	/**
	 * Default signal weights.
	 *
	 * @return array<string,int>
	 */
	public static function weights(): array {
		$weights = array(
			'order_velocity'      => 25,
			'ip_velocity'         => 20,
			'first_time_highvalue' => 15,
			'state_mismatch'      => 5,
			'disposable_email'    => 20,
			'dealer_switching'    => 15,
			'multi_handgun'       => 20,
		);

		/**
		 * Filter the risk signal weights.
		 *
		 * @param array<string,int> $weights Signal weights.
		 */
		return (array) apply_filters( 'fflcs_fraud_score_weights', $weights );
	}

	/**
	 * Score a newly created transfer.
	 *
	 * @param int   $transfer_id Transfer ID.
	 * @param array $context     Creation context.
	 */
	public function score_transfer( int $transfer_id, array $context ): void {
		global $wpdb;

		$transfer = Transfer_Service::get( $transfer_id );

		if ( ! $transfer ) {
			return;
		}

		$weights = self::weights();
		$signals = array();
		$score   = 0;

		$transfers = fflcs_table( 'transfers' );
		$email     = (string) $transfer->customer_email;

		// Several transfers from one buyer in 24 hours.
		if ( '' !== $email ) {
			$recent = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$transfers} WHERE customer_email = %s AND created_at >= %s",
					$email,
					gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) )
				)
			);

			if ( $recent > 2 ) {
				$score += $weights['order_velocity'];
				$signals['order_velocity'] = sprintf(
					/* translators: %d: transfer count. */
					__( '%d transfers from this buyer in the last 24 hours.', 'ffl-checkout-solutions' ),
					$recent
				);
			}
		}

		// Several distinct buyers from one IP.
		if ( ! empty( $transfer->customer_ip ) ) {
			$distinct = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(DISTINCT customer_email) FROM {$transfers} WHERE customer_ip = %s AND created_at >= %s",
					(string) $transfer->customer_ip,
					gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
				)
			);

			if ( $distinct > 2 ) {
				$score += $weights['ip_velocity'];
				$signals['ip_velocity'] = sprintf(
					/* translators: %d: number of distinct buyers. */
					__( '%d different buyers from the same IP address in the last week.', 'ffl-checkout-solutions' ),
					$distinct
				);
			}
		}

		// A disposable email domain on a firearm purchase.
		if ( $this->is_disposable_email( $email ) ) {
			$score += $weights['disposable_email'];
			$signals['disposable_email'] = __( 'The buyer used a disposable email domain.', 'ffl-checkout-solutions' );
		}

		// Buyer and dealer in different states. Common and legitimate for
		// online sales — weighted low precisely because it is normal.
		$dealer = Dealer_Service::get( (int) $transfer->dealer_id );

		if ( $dealer && $transfer->order_id ) {
			$order = wc_get_order( (int) $transfer->order_id );

			if ( $order instanceof \WC_Order ) {
				$buyer_state = strtoupper( (string) $order->get_billing_state() );

				if ( '' !== $buyer_state && $buyer_state !== strtoupper( (string) $dealer->premise_state ) ) {
					$score += $weights['state_mismatch'];
					$signals['state_mismatch'] = __( 'The buyer and dealer are in different states. This is normal for online sales.', 'ffl-checkout-solutions' );
				}
			}
		}

		// Same buyer, several different dealers, short window.
		if ( '' !== $email ) {
			$dealers_used = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(DISTINCT dealer_id) FROM {$transfers} WHERE customer_email = %s AND created_at >= %s",
					$email,
					gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
				)
			);

			if ( $dealers_used > 2 ) {
				$score += $weights['dealer_switching'];
				$signals['dealer_switching'] = sprintf(
					/* translators: %d: number of dealers. */
					__( 'This buyer has used %d different dealers in the last 30 days.', 'ffl-checkout-solutions' ),
					$dealers_used
				);
			}
		}

		// Several handguns in a short window — overlaps the multiple-sale
		// watcher deliberately: one is a filing obligation, this is a risk hint.
		if ( 'handgun' === (string) $transfer->product_type && '' !== $email ) {
			$handguns = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$transfers} WHERE customer_email = %s AND product_type = %s AND created_at >= %s",
					$email,
					'handgun',
					gmdate( 'Y-m-d H:i:s', strtotime( '-5 days' ) )
				)
			);

			if ( $handguns > 1 ) {
				$score += $weights['multi_handgun'];
				$signals['multi_handgun'] = sprintf(
					/* translators: %d: number of handguns. */
					__( '%d handgun transfers for this buyer within five days.', 'ffl-checkout-solutions' ),
					$handguns
				);
			}
		}

		$level = 'low';

		if ( $score >= 50 ) {
			$level = 'high';
		} elseif ( $score >= 25 ) {
			$level = 'medium';
		}

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'fraud_scores' ),
			array(
				'transfer_id'    => $transfer_id,
				'order_id'       => (int) $transfer->order_id,
				'customer_email' => $email,
				'score'          => $score,
				'risk_level'     => $level,
				'signals_json'   => wp_json_encode( $signals ),
				'status'         => 'open',
				'created_at'     => fflcs_mysql_now(),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( 'low' !== $level ) {
			Transfer_Service::log_event(
				$transfer_id,
				'risk_scored',
				array(
					'dealer_id' => (int) $transfer->dealer_id,
					'actor'     => 'system',
					'notes'     => sprintf(
						/* translators: 1: risk level, 2: score. */
						__( 'Risk review suggested: %1$s (%2$d points). Advisory only — no action was taken.', 'ffl-checkout-solutions' ),
						$level,
						$score
					),
					'data'      => $signals,
				)
			);
		}
	}

	/**
	 * Whether an address uses a known disposable domain.
	 *
	 * @param string $email Email address.
	 */
	private function is_disposable_email( string $email ): bool {
		if ( ! str_contains( $email, '@' ) ) {
			return false;
		}

		$domain = strtolower( substr( strrchr( $email, '@' ), 1 ) );

		$domains = array(
			'mailinator.com',
			'guerrillamail.com',
			'10minutemail.com',
			'tempmail.com',
			'throwawaymail.com',
			'yopmail.com',
			'trashmail.com',
			'sharklasers.com',
			'getnada.com',
			'dispostable.com',
		);

		/**
		 * Filter the disposable email domain list.
		 *
		 * @param string[] $domains Domains.
		 */
		$domains = (array) apply_filters( 'fflcs_disposable_email_domains', $domains );

		return in_array( $domain, $domains, true );
	}
}

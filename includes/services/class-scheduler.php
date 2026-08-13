<?php
/**
 * Scheduler abstraction (Section 18.1).
 *
 * Wraps Action Scheduler — which WooCommerce bundles, so it is always present
 * once the dependency guard passes — and falls back to WP-Cron if it is not.
 * Two properties matter to callers:
 *
 *   Idempotent enqueue. async() and recurring() will not stack a second copy of
 *   an identical pending job. Transfer emails are triggered from hooks that can
 *   fire twice for one order; without this, dealers get duplicate mail.
 *
 *   Non-blocking. Anything slow (SMTP, a distributor API, a label purchase) is
 *   queued rather than run inside the request, so a slow third party can never
 *   stall a customer's checkout.
 *
 * @package FFLCS
 */

namespace FFLCS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Job queue facade.
 */
class Scheduler {

	/**
	 * Action Scheduler group, so plugin jobs are filterable in the WooCommerce
	 * "Scheduled Actions" screen.
	 */
	const GROUP = 'fflcs';

	/**
	 * No hooks of its own — this is a static facade with an instance shell so
	 * the container can hold it uniformly.
	 */
	public function init(): void {}

	/**
	 * Whether Action Scheduler is available.
	 */
	public static function has_action_scheduler(): bool {
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Queue a one-off job to run as soon as possible.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Arguments passed to the hook.
	 */
	public static function async( string $hook, array $args = array() ): void {
		if ( self::has_action_scheduler() ) {
			if ( as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
				return;
			}
			as_enqueue_async_action( $hook, $args, self::GROUP );
			return;
		}

		if ( wp_next_scheduled( $hook, $args ) ) {
			return;
		}
		wp_schedule_single_event( time() + 5, $hook, $args );
	}

	/**
	 * Queue a one-off job for a specific time.
	 *
	 * @param int    $timestamp When to run.
	 * @param string $hook      Hook name.
	 * @param array  $args      Arguments.
	 */
	public static function once( int $timestamp, string $hook, array $args = array() ): void {
		if ( self::has_action_scheduler() ) {
			if ( as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
				return;
			}
			as_schedule_single_action( $timestamp, $hook, $args, self::GROUP );
			return;
		}

		if ( wp_next_scheduled( $hook, $args ) ) {
			return;
		}
		wp_schedule_single_event( $timestamp, $hook, $args );
	}

	/**
	 * Ensure a recurring job exists.
	 *
	 * @param int    $interval Seconds between runs.
	 * @param string $hook     Hook name.
	 * @param array  $args     Arguments.
	 */
	public static function recurring( int $interval, string $hook, array $args = array() ): void {
		if ( self::has_action_scheduler() ) {
			if ( as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
				return;
			}
			as_schedule_recurring_action( time() + $interval, $interval, $hook, $args, self::GROUP );
			return;
		}

		if ( wp_next_scheduled( $hook, $args ) ) {
			return;
		}
		wp_schedule_event( time() + $interval, 'hourly', $hook, $args );
	}

	/**
	 * Cancel every pending instance of a hook, in both engines.
	 *
	 * Both, not one: a site that had Action Scheduler and later lost it (or
	 * vice versa) can hold jobs in either queue, and a half-cancelled job fires
	 * against a class that is no longer loaded.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Arguments.
	 */
	public static function cancel( string $hook, array $args = array() ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, $args, self::GROUP );
		}

		wp_clear_scheduled_hook( $hook, $args );
	}

	/**
	 * Pending job count for a hook — surfaced on the diagnostics screen.
	 *
	 * @param string $hook Hook name.
	 */
	public static function pending_count( string $hook ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return wp_next_scheduled( $hook ) ? 1 : 0;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'group'    => self::GROUP,
				'per_page' => 100,
			),
			'ids'
		);

		return count( (array) $actions );
	}
}

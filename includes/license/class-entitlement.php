<?php
/**
 * Tier entitlement (Section 6.2).
 *
 * The single gate every premium feature passes through. Section 29.4: no module
 * ever hardcodes an unlock, and no module decides its own entitlement — it
 * declares a required tier in its manifest and asks this class.
 *
 * Tier hierarchy: free < starter < pro < business < enterprise.
 *
 * @package FFLCS
 */

namespace FFLCS\License;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves what this installation is entitled to.
 */
class Entitlement {

	/**
	 * Tier ranking. Higher includes everything lower.
	 *
	 * @var array<string,int>
	 */
	const TIERS = array(
		'free'       => 0,
		'starter'    => 1,
		'pro'        => 2,
		'business'   => 3,
		'enterprise' => 4,
	);

	/**
	 * Whether an add-on may run.
	 *
	 * @param string $addon_id      Module identifier.
	 * @param string $required_tier Tier the module declares.
	 */
	public static function can( string $addon_id, string $required_tier = 'free' ): bool {
		$current  = self::plan();
		$entitled = self::plan_at_least( $required_tier );

		/**
		 * Filter an entitlement decision.
		 *
		 * The documented seam for bundling and for support overrides. It is a
		 * filter rather than a constant precisely so an override is visible in
		 * code review instead of hidden in wp-config.
		 *
		 * @param bool   $entitled Whether the module may run.
		 * @param string $addon_id Module identifier.
		 * @param string $required Required tier.
		 * @param string $current  Current plan.
		 */
		return (bool) apply_filters( 'fflcs_entitlement', $entitled, $addon_id, $required_tier, $current );
	}

	/**
	 * The current plan slug.
	 *
	 * Falls back to free whenever the licence is missing, rejected or past its
	 * trust window — never to a paid tier.
	 */
	public static function plan(): string {
		if ( ! License_Client::is_valid() ) {
			return 'free';
		}

		$plan = sanitize_key( (string) ( License_Client::meta()['plan'] ?? 'free' ) );

		return isset( self::TIERS[ $plan ] ) ? $plan : 'free';
	}

	/**
	 * Whether the current plan meets or exceeds a tier.
	 *
	 * @param string $tier Tier to test against.
	 */
	public static function plan_at_least( string $tier ): bool {
		$required = self::TIERS[ $tier ] ?? 0;

		if ( 0 === $required ) {
			return true;
		}

		return ( self::TIERS[ self::plan() ] ?? 0 ) >= $required;
	}

	/**
	 * Whether the plan is pro or higher.
	 */
	public static function is_pro(): bool {
		return self::plan_at_least( 'pro' );
	}

	/**
	 * Translated tier label.
	 *
	 * @param string $tier Tier slug.
	 */
	public static function tier_label( string $tier ): string {
		$labels = array(
			'free'       => __( 'Free', 'ffl-checkout-solutions' ),
			'starter'    => __( 'Starter', 'ffl-checkout-solutions' ),
			'pro'        => __( 'Pro', 'ffl-checkout-solutions' ),
			'business'   => __( 'Business', 'ffl-checkout-solutions' ),
			'enterprise' => __( 'Enterprise', 'ffl-checkout-solutions' ),
		);

		return $labels[ $tier ] ?? ucfirst( $tier );
	}

	/**
	 * The upgrade destination shown on locked cards.
	 */
	public static function upgrade_url(): string {
		return 'https://app.wpistic.com';
	}
}

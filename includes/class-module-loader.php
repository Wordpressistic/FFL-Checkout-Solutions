<?php
/**
 * Module loader (Section 8.2).
 *
 * Boot sequence for each module:
 *   1. Is it switched on in options?
 *   2. Is this installation entitled to its tier?
 *   3. Only then is the class loaded and instantiated.
 *
 * Step three matters more than it looks. A module that fails the check is never
 * constructed at all — so it registers no hooks, no AJAX actions, no REST
 * routes, no settings tabs and no cron events. Gating at render time instead
 * would leave a locked module's endpoints live and reachable.
 *
 * @package FFLCS
 */

namespace FFLCS;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers, gates and boots feature modules.
 */
class Module_Loader {

	/**
	 * Booted module instances, keyed by module ID.
	 *
	 * @var array<string,object>
	 */
	private array $booted = array();

	/**
	 * Modules that were skipped, with the reason.
	 *
	 * Surfaced on the diagnostics screen so "why is this feature not working?"
	 * has an answer that does not require reading code.
	 *
	 * @var array<string,string>
	 */
	private array $skipped = array();

	/**
	 * Boot every eligible module.
	 */
	public function boot(): void {
		foreach ( Addon_Manager::manifests() as $id => $manifest ) {
			$this->boot_module( (string) $id, $manifest );
		}

		/**
		 * Fires after all modules have booted.
		 *
		 * @param Module_Loader $loader The loader.
		 */
		do_action( 'fflcs_modules_booted', $this );
	}

	/**
	 * Boot one module if it passes both gates.
	 *
	 * @param string              $id       Module ID.
	 * @param array<string,mixed> $manifest Module manifest.
	 */
	private function boot_module( string $id, array $manifest ): void {
		if ( ! Addon_Manager::is_active( $id ) ) {
			$this->skipped[ $id ] = License\Entitlement::can( $id, (string) $manifest['tier'] )
				? __( 'Switched off', 'ffl-checkout-solutions' )
				: sprintf(
					/* translators: %s: required plan name. */
					__( 'Requires the %s plan', 'ffl-checkout-solutions' ),
					License\Entitlement::tier_label( (string) $manifest['tier'] )
				);

			return;
		}

		$class = $this->resolve_class( $id, $manifest );

		if ( '' === $class || ! class_exists( $class ) ) {
			// A manifest entry with no implementation is normal for modules
			// whose behaviour lives entirely in core services (core_checkout,
			// customer_tracking). Recorded, not warned about.
			$this->skipped[ $id ] = __( 'No separate bootstrap — handled by core services', 'ffl-checkout-solutions' );
			return;
		}

		$module = new $class();

		if ( method_exists( $module, 'init' ) ) {
			$module->init();
		}

		$this->booted[ $id ] = $module;
	}

	/**
	 * Resolve a module's bootstrap class.
	 *
	 * A manifest may name one explicitly; otherwise the convention is
	 * FFLCS\Modules\{Studly_Id}.
	 *
	 * @param string              $id       Module ID.
	 * @param array<string,mixed> $manifest Manifest.
	 */
	private function resolve_class( string $id, array $manifest ): string {
		if ( ! empty( $manifest['bootstrap'] ) ) {
			return (string) $manifest['bootstrap'];
		}

		$studly = implode( '_', array_map( 'ucfirst', explode( '_', $id ) ) );

		return '\\FFLCS\\Modules\\' . $studly;
	}

	/**
	 * A booted module instance, if it is running.
	 *
	 * @param string $id Module ID.
	 */
	public function get( string $id ): ?object {
		return $this->booted[ $id ] ?? null;
	}

	/**
	 * IDs of every running module.
	 *
	 * @return string[]
	 */
	public function booted_ids(): array {
		return array_keys( $this->booted );
	}

	/**
	 * Modules that did not boot, with reasons.
	 *
	 * @return array<string,string>
	 */
	public function skipped(): array {
		return $this->skipped;
	}
}

<?php
/**
 * Theming engine (Section 15.1).
 *
 * All presentation colour, typography and logo choices come from plugin
 * settings. Nothing here reads a WordPress theme's variables or assumes a host
 * theme is installed: the plugin has to look deliberate on any store, and a
 * theme integration would break the moment the merchant switched themes.
 *
 * The shipped palette is a professional blue/gray, defined once here and
 * emitted as CSS custom properties so the checkout widget, dealer portal,
 * tracking page and emails all read from a single source.
 *
 * @package FFLCS
 */

namespace FFLCS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Design token provider.
 */
class Theming {

	/**
	 * Registers the front-end token stylesheet.
	 */
	public function init(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tokens' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_tokens' ), 5 );
	}

	/**
	 * Ship the token stylesheet plus the settings-derived overrides.
	 *
	 * The static file carries structure and the safe defaults; only the values
	 * a merchant actually customised are emitted inline, which keeps the inline
	 * payload to a few hundred bytes.
	 */
	public function enqueue_tokens(): void {
		wp_register_style( 'fflcs-tokens', FFLCS_PLUGIN_URL . 'assets/css/tokens.css', array(), FFLCS_VERSION );
		wp_enqueue_style( 'fflcs-tokens' );
		wp_add_inline_style( 'fflcs-tokens', self::custom_properties() );
	}

	/**
	 * Resolved theme settings.
	 *
	 * @return array<string,string>
	 */
	public static function settings(): array {
		$settings = array(
			'color_primary' => (string) get_option( 'fflcs_theme_primary', '#2563EB' ),
			'color_success' => (string) get_option( 'fflcs_theme_success', '#059669' ),
			'color_warning' => (string) get_option( 'fflcs_theme_warning', '#D97706' ),
			'color_danger'  => (string) get_option( 'fflcs_theme_danger', '#DC2626' ),
			'color_surface' => (string) get_option( 'fflcs_theme_surface', '#1E293B' ),
			'color_text'    => (string) get_option( 'fflcs_theme_text', '#F8FAFC' ),
			'logo'          => (string) get_option( 'fflcs_theme_logo', '' ),
			'font_stack'    => (string) get_option( 'fflcs_theme_font', '' ),
			'custom_css'    => (string) get_option( 'fflcs_theme_custom_css', '' ),
		);

		/**
		 * Filter the resolved theme settings.
		 *
		 * @param array $settings Theme settings.
		 */
		return (array) apply_filters( 'fflcs_theming_settings', $settings );
	}

	/**
	 * The default palette, for the "reset to defaults" action.
	 *
	 * @return array<string,string>
	 */
	public static function defaults(): array {
		return array(
			'color_primary' => '#2563EB',
			'color_success' => '#059669',
			'color_warning' => '#D97706',
			'color_danger'  => '#DC2626',
			'color_surface' => '#1E293B',
			'color_text'    => '#F8FAFC',
		);
	}

	/**
	 * CSS custom properties for the settings that differ from the defaults.
	 */
	public static function custom_properties(): string {
		$settings = self::settings();
		$defaults = self::defaults();

		$map = array(
			'color_primary' => '--fflcs-primary',
			'color_success' => '--fflcs-success',
			'color_warning' => '--fflcs-warning',
			'color_danger'  => '--fflcs-danger',
			'color_surface' => '--fflcs-surface',
			'color_text'    => '--fflcs-text-inverse',
		);

		$declarations = array();

		foreach ( $map as $key => $property ) {
			$value = self::sanitize_color( (string) ( $settings[ $key ] ?? '' ) );
			if ( '' === $value || ( isset( $defaults[ $key ] ) && strcasecmp( $value, $defaults[ $key ] ) === 0 ) ) {
				continue;
			}
			$declarations[] = $property . ':' . $value;
		}

		if ( ! empty( $settings['font_stack'] ) ) {
			// The font stack lands inside a CSS declaration, so anything that
			// could terminate it or open a new rule has to go.
			$font = preg_replace( '/[^A-Za-z0-9 ,\'"\-]/', '', (string) $settings['font_stack'] );
			if ( '' !== $font ) {
				$declarations[] = '--fflcs-font:' . $font;
			}
		}

		$css = $declarations ? ':root{' . implode( ';', $declarations ) . ';}' : '';

		if ( ! empty( $settings['custom_css'] ) ) {
			$css .= "\n" . wp_strip_all_tags( (string) $settings['custom_css'] );
		}

		return $css;
	}

	/**
	 * Validate a hex colour, returning '' when it is not one.
	 *
	 * @param string $color Candidate colour.
	 */
	public static function sanitize_color( string $color ): string {
		$color = trim( $color );

		return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ? $color : '';
	}

	/**
	 * The store logo URL, falling back to the bundled default.
	 */
	public static function logo_url(): string {
		$logo = (string) get_option( 'fflcs_theme_logo', '' );

		if ( '' !== $logo && wp_http_validate_url( $logo ) ) {
			return $logo;
		}

		return FFLCS_PLUGIN_URL . 'assets/images/logo-default.png';
	}

	/**
	 * Colour for a transfer status badge.
	 *
	 * @param string $status Status slug.
	 */
	public static function status_color( string $status ): string {
		$settings = self::settings();

		$map = array(
			'dealer_selected'    => $settings['color_primary'],
			'payment_confirmed'  => $settings['color_primary'],
			'shipped_to_dealer'  => $settings['color_primary'],
			'received_by_dealer' => $settings['color_success'],
			'nics_pending'       => $settings['color_warning'],
			'nics_delayed'       => $settings['color_warning'],
			'nics_approved'      => $settings['color_success'],
			'nics_denied'        => $settings['color_danger'],
			'transferred'        => $settings['color_success'],
			'cancelled'          => $settings['color_danger'],
			'issue_reported'     => $settings['color_danger'],
		);

		return $map[ $status ] ?? $settings['color_primary'];
	}
}

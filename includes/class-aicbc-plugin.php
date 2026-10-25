<?php
/**
 * Main plugin controller for AiCB Chat Widget.
 *
 * @package AiCB_Chat_Widget
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires up settings, admin, REST and the front-end widget.
 */
class AICBC_Plugin {

	/**
	 * Settings controller.
	 *
	 * @var AICBC_Settings
	 */
	public $settings;

	/**
	 * Constructor: boot all components.
	 */
	public function __construct() {
		if ( is_admin() ) {
			AICBC_Store::maybe_upgrade();
		}

		$this->settings = new AICBC_Settings();
		$this->settings->init();

		$admin = new AICBC_Admin( $this->settings );
		$admin->init();

		$rest = new AICBC_REST();
		$rest->init();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_widget' ) );
		add_filter( 'script_loader_tag', array( $this, 'hosted_script_attrs' ), 10, 2 );
		$this->register_optimizer_exclusions();
	}

	/**
	 * Keep the widget script OUT of JS minifier/combiner bundles (W3 Total
	 * Cache, Autoptimize, LiteSpeed Cache, WP Rocket, SiteGround Optimizer).
	 *
	 * Bundles keep a stable URL across plugin updates while browsers cache
	 * them for up to a year, so bundled copies of the widget go stale on every
	 * release. Served standalone, the script carries its own ?ver=<version>
	 * cache-buster and every browser picks updates up immediately.
	 *
	 * @return void
	 */
	private function register_optimizer_exclusions() {
		// W3 Total Cache (minify): skip our tag.
		add_filter(
			'w3tc_minify_js_do_tag_minification',
			function ( $do_minify, $script_tag = '', $file = '' ) {
				if ( false !== strpos( (string) $file, 'aicb-chat-widget' ) || false !== strpos( (string) $script_tag, 'aicb-chat-widget' ) ) {
					return false;
				}
				return $do_minify;
			},
			10,
			3
		);

		// Autoptimize: comma-separated exclusion list (matches on src substring).
		add_filter(
			'autoptimize_filter_js_exclude',
			function ( $exclude ) {
				return rtrim( (string) $exclude, ', ' ) . ', aicb-chat-widget';
			}
		);

		// LiteSpeed Cache: array of URL substrings excluded from JS optimisation.
		$litespeed_exclude = function ( $excludes ) {
			$excludes   = is_array( $excludes ) ? $excludes : array();
			$excludes[] = 'aicb-chat-widget';
			return $excludes;
		};
		add_filter( 'litespeed_optimize_js_excludes', $litespeed_exclude );
		add_filter( 'litespeed_optm_js_defer_exc', $litespeed_exclude );

		// WP Rocket: minify/combine + delay-JS exclusions (path patterns).
		$rocket_exclude = function ( $excludes ) {
			$excludes   = is_array( $excludes ) ? $excludes : array();
			$excludes[] = '/wp-content/plugins/aicb-chat-widget/(.*)\.js';
			return $excludes;
		};
		add_filter( 'rocket_exclude_js', $rocket_exclude );
		add_filter( 'rocket_minify_excluded_external_js', $rocket_exclude );
		add_filter( 'rocket_delay_js_exclusions', $litespeed_exclude );

		// SiteGround Optimizer: script-handle based.
		$handle_exclude = function ( $handles ) {
			$handles   = is_array( $handles ) ? $handles : array();
			$handles[] = 'aicb-chat-widget';
			return $handles;
		};
		add_filter( 'sgo_js_minify_exclude', $handle_exclude );
		add_filter( 'sgo_javascript_combine_exclude', $handle_exclude );
	}

	/**
	 * Enqueue the chat widget on the public site.
	 *
	 * @return void
	 */
	public function enqueue_widget() {
		if ( ! AICBC_Settings::get( 'enabled' ) || is_admin() ) {
			return;
		}

		if ( 'hosted' === AICBC_Settings::get( 'mode' ) ) {
			$key = trim( (string) AICBC_Settings::get( 'hosted_site_key' ) );
			if ( '' === $key ) {
				return;
			}
			$base = untrailingslashit( (string) AICBC_Settings::get( 'aicb_base', 'https://caimunicate.com' ) );
			wp_enqueue_script( 'aicb-chat-hosted', $base . '/widget.js', array(), AICBC_VERSION, true );
			return;
		}

		wp_enqueue_script( 'aicb-chat-widget', AICBC_URL . 'assets/js/aicb-chat-widget.js', array(), AICBC_VERSION, true );
		wp_localize_script(
			'aicb-chat-widget',
			'AICBC_CFG',
			array(
				'rest'     => esc_url_raw( rest_url( AICBC_REST::NS ) ),
				'title'    => AICBC_Settings::bot_name(),
				'greeting' => AICBC_Settings::greeting(),
			)
		);
	}

	/**
	 * Add the data-site-key attribute + async to the hosted Caimunicate widget.
	 *
	 * @param string $tag    Script tag HTML.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public function hosted_script_attrs( $tag, $handle ) {
		if ( 'aicb-chat-hosted' !== $handle ) {
			return $tag;
		}
		$key = trim( (string) AICBC_Settings::get( 'hosted_site_key' ) );
		return str_replace( ' src=', ' async data-site-key="' . esc_attr( $key ) . '" src=', $tag );
	}
}

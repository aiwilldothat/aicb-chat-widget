<?php
/**
 * Demfilz Pro licence client — drop-in for any Demfilz WordPress/WooCommerce
 * plugin. Talks to the shared licensing service at api.demfilz.com, scoped to a
 * product slug, and gates that plugin's Pro features.
 *
 * One Stripe account + webhook serves every product; each product just has its
 * own Price registered server-side. The plugin never holds Stripe secrets — it
 * only ever holds the customer's licence key.
 *
 * Usage in a plugin (see README):
 *   $license = new Demfilz_License_Client( array(
 *       'product'     => 'modal-popups',
 *       'item_name'   => 'Demfilz Modal Popups Pro',
 *       'option'      => 'dmpop_license',   // unique per plugin
 *       'text_domain' => 'demfilz-modal-popups',
 *   ) );
 *   if ( $license->is_active() ) { /* unlock Pro *\/ }
 *
 * @package DemfilzShared
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Demfilz_License_Client' ) ) :

	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Shared cross-plugin Demfilz class (studio prefix), bundled into each plugin.
	/**
	 * Per-product licence client.
	 */
	class Demfilz_License_Client {
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

		const CHECK_INTERVAL = DAY_IN_SECONDS;
		const GRACE          = WEEK_IN_SECONDS;

		/**
		 * Config: product, item_name, option, text_domain, api_base, return_url.
		 *
		 * @var array
		 */
		private $cfg;

		/**
		 * Constructor.
		 *
		 * @param array $cfg Config.
		 */
		public function __construct( $cfg ) {
			$this->cfg = wp_parse_args(
				$cfg,
				array(
					'product'     => '',
					'item_name'   => 'Demfilz Pro',
					'option'      => 'demfilz_license',
					'text_domain' => 'default',
					'api_base'    => 'https://api.demfilz.com/auto-order',
					'return_url'  => '',
					'version'     => '1.0.0',
				)
			);
		}

		/** The licensing service base URL (filterable). */
		public function api_base() {
			return apply_filters( 'demfilz_license_api_base', $this->cfg['api_base'], $this->cfg['product'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional shared Demfilz cross-plugin hook name.
		}

		/** Hosted Stripe checkout URL for this product's subscription. */
		public function checkout_url() {
			$url = add_query_arg(
				array(
					'product' => rawurlencode( $this->cfg['product'] ),
					'site'    => rawurlencode( home_url() ),
				),
				$this->api_base() . '/subscribe'
			);
			return apply_filters( 'demfilz_license_checkout_url', $url, $this->cfg['product'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional shared Demfilz cross-plugin hook name.
		}

		/** Stripe customer-portal / account URL. */
		public function manage_url() {
			return apply_filters( 'demfilz_license_manage_url', $this->api_base() . '/manage', $this->cfg['product'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional shared Demfilz cross-plugin hook name.
		}

		/** Customer account portal URL. */
		public function account_url() {
			return 'https://api.demfilz.com/account/';
		}

		/** Stored licence record. */
		public function get() {
			$defaults = array(
				'key'        => '',
				'status'     => 'inactive',
				'plan'       => 'basic',
				'last_check' => 0,
				'last_good'  => 0,
				'expires'    => 0,
				'message'    => '',
			);
			return wp_parse_args( get_option( $this->cfg['option'], array() ), $defaults );
		}

		/** Whether Pro is unlocked (cached, with a grace window if offline). */
		public function is_active() {
			$lic = $this->get();
			if ( '' === $lic['key'] ) {
				return false;
			}
			if ( $lic['last_check'] < ( time() - self::CHECK_INTERVAL ) ) {
				$lic = $this->validate( $lic['key'] );
			}
			if ( 'active' === $lic['status'] ) {
				return true;
			}
			if ( 'unreachable' === $lic['status'] && $lic['last_good'] > ( time() - self::GRACE ) ) {
				return true;
			}
			return false;
		}

		/**
		 * Validate a key against the service and persist the result.
		 *
		 * @param string $key Licence key.
		 * @return array Licence record.
		 */
		public function validate( $key ) {
			$key               = trim( sanitize_text_field( $key ) );
			$lic               = $this->get();
			$lic['key']        = $key;
			$lic['last_check'] = time();

			if ( '' === $key ) {
				$lic['status']  = 'inactive';
				$lic['plan']    = 'basic';
				$lic['message'] = '';
				update_option( $this->cfg['option'], $lic );
				return $lic;
			}

			$response = wp_remote_post(
				$this->api_base() . '/validate',
				array(
					'timeout' => 15,
					'body'    => array(
						'license_key' => $key,
						'product'     => $this->cfg['product'],
						'site'        => home_url(),
						'version'     => $this->cfg['version'],
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$lic['status']  = 'unreachable';
				$lic['message'] = $response->get_error_message();
				update_option( $this->cfg['option'], $lic );
				return $lic;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 === $code && is_array( $body ) && ! empty( $body['active'] ) ) {
				$lic['status']    = 'active';
				$lic['plan']      = 'pro';
				$lic['expires']   = isset( $body['expires'] ) ? absint( $body['expires'] ) : 0;
				$lic['last_good'] = time();
				$lic['message']   = '';
			} else {
				$lic['status']  = 'invalid';
				$lic['plan']    = 'basic';
				$lic['message'] = ( is_array( $body ) && isset( $body['message'] ) ) ? sanitize_text_field( $body['message'] ) : 'Licence key was not accepted.';
			}

			update_option( $this->cfg['option'], $lic );
			return $lic;
		}

		/** Remove the licence locally. */
		public function clear() {
			update_option(
				$this->cfg['option'],
				array(
					'key'        => '',
					'status'     => 'inactive',
					'plan'       => 'basic',
					'last_check' => time(),
					'last_good'  => 0,
					'expires'    => 0,
					'message'    => '',
				)
			);
		}

		/**
		 * Render a complete Upgrade / Licence settings panel. Handles its own
		 * form POST (call early on your settings page, before output).
		 *
		 * @return void
		 */
		public function render_panel() {
			$opt = $this->cfg['option'];

			if ( isset( $_POST[ $opt . '_action' ] ) && current_user_can( 'manage_options' ) ) {
				check_admin_referer( $opt . '_nonce' );
				$action = sanitize_key( wp_unslash( $_POST[ $opt . '_action' ] ) );
				if ( 'save' === $action ) {
					$this->validate( sanitize_text_field( wp_unslash( $_POST[ $opt . '_key' ] ?? '' ) ) );
				} elseif ( 'clear' === $action ) {
					$this->clear();
				}
			}

			$lic    = $this->get();
			$active = $this->is_active();
			$name   = $this->cfg['item_name'];
			?>
			<div class="demfilz-license">
				<p><?php echo esc_html( 'Current plan:' ); ?>
					<strong style="color:<?php echo $active ? '#1a7f37' : '#646970'; ?>"><?php echo $active ? esc_html( 'Pro' ) : esc_html( 'Free' ); ?></strong>
				</p>

				<?php if ( ! $active ) : ?>
					<p><a class="button button-primary button-hero" href="<?php echo esc_url( $this->checkout_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( sprintf( 'Upgrade to %s', $name ) ); ?></a></p>
					<p class="description"><?php echo esc_html( 'After subscribing you receive a licence key by email. Enter it below to activate Pro.' ); ?></p>
				<?php else : ?>
					<p><a class="button" href="<?php echo esc_url( $this->account_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( 'Manage subscription' ); ?></a></p>
				<?php endif; ?>

				<form method="post">
					<?php wp_nonce_field( $opt . '_nonce' ); ?>
					<input type="hidden" name="<?php echo esc_attr( $opt ); ?>_action" value="save">
					<input type="text" class="regular-text" name="<?php echo esc_attr( $opt ); ?>_key" value="<?php echo esc_attr( $lic['key'] ); ?>" placeholder="AOBD-XXXXX-XXXXX-XXXXX-XXXXX">
					<?php submit_button( 'Activate licence', 'secondary', '', false ); ?>
					<?php if ( ! empty( $lic['message'] ) && ! $active ) : ?>
						<p class="description" style="color:#b32d2e"><?php echo esc_html( $lic['message'] ); ?></p>
					<?php endif; ?>
				</form>

				<?php if ( '' !== $lic['key'] ) : ?>
					<form method="post" style="margin-top:8px">
						<?php wp_nonce_field( $opt . '_nonce' ); ?>
						<input type="hidden" name="<?php echo esc_attr( $opt ); ?>_action" value="clear">
						<button type="submit" class="button-link" style="color:#b32d2e"><?php echo esc_html( 'Remove licence from this site' ); ?></button>
					</form>
				<?php endif; ?>

				<p class="description" style="margin-top:14px;color:#8a8f94">
					<?php echo esc_html( 'Licensing connects to api.demfilz.com to validate your subscription. Billing is handled by Stripe.' ); ?>
				</p>
			</div>
			<?php
		}
	}

endif;

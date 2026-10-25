<?php
/**
 * Settings screen and options accessor for AiCB Chat Widget.
 *
 * @package AiCB_Chat_Widget
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the admin settings page and provides typed access to plugin options.
 */
class AICBC_Settings {

	/**
	 * Return the default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'             => 1,
			'mode'                => 'local',
			'hosted_site_key'     => '',
			'bot_name'            => '',
			'greeting'            => '',
			'instructions'        => '',
			'anthropic_api_key'   => '',
			'model'               => 'claude-sonnet-5',
			'woo_orders'          => 1,
			'woo_products'        => 1,
			'store_conversations' => 1,
			'use_learnings'       => 1,
			'aicb_base'           => 'https://caimunicate.com',
			'aicb_agent_key'      => '',
			'aicb_recipients'     => '',
			'forward_leads'       => 1,
			'email_leads'         => 1,
			'aicb_platform_key'   => '',
			'aicb_handoff'        => '',
			'human_wait_secs'     => 30,
			'handoff_email'       => '',
		);
	}

	/**
	 * Get all options merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( AICBC_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	/**
	 * Get a single option value.
	 *
	 * @param string $key      Option key.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	public static function get( $key, $fallback = '' ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $fallback;
	}

	/**
	 * The display name for the chat header.
	 *
	 * @return string
	 */
	public static function bot_name() {
		$name = trim( (string) self::get( 'bot_name' ) );
		return '' !== $name ? $name : get_bloginfo( 'name' );
	}

	/**
	 * The greeting line shown when a chat opens.
	 *
	 * @return string
	 */
	public static function greeting() {
		$greet = trim( (string) self::get( 'greeting' ) );
		if ( '' !== $greet ) {
			return $greet;
		}
		/* translators: %s: site name. */
		return sprintf( __( 'Hi! Welcome to %s. How can I help?', 'aicb-chat-widget' ), get_bloginfo( 'name' ) );
	}

	/**
	 * Whether AiCB forwarding is configured.
	 *
	 * @return bool
	 */
	public static function can_forward() {
		$all = self::all();
		return '' !== trim( (string) $all['aicb_agent_key'] ) && '' !== trim( (string) $all['aicb_recipients'] ) && '' !== trim( (string) $all['aicb_base'] );
	}

	/**
	 * Parsed list of AiCB recipient handles (without the leading @).
	 *
	 * @return array
	 */
	public static function recipients() {
		$raw  = (string) self::get( 'aicb_recipients' );
		$list = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$out  = array();
		foreach ( (array) $list as $h ) {
			$h = strtolower( ltrim( trim( $h ), '@' ) );
			if ( preg_match( '/^[a-z0-9_\-]{2,30}$/', $h ) ) {
				$out[] = $h;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The @username live human chat connects to (falls back to the first
	 * forwarding recipient).
	 *
	 * @return string Handle without the leading @, '' when unset.
	 */
	public static function handoff_handle() {
		$h = strtolower( ltrim( trim( (string) self::get( 'aicb_handoff' ) ), '@' ) );
		if ( preg_match( '/^[a-z0-9_\-]{2,30}$/', $h ) ) {
			return $h;
		}
		$recipients = self::recipients();
		return $recipients ? $recipients[0] : '';
	}

	/**
	 * Where escalations and leads are emailed (falls back to the site admin).
	 *
	 * @return string
	 */
	public static function handoff_email() {
		$email = sanitize_email( (string) self::get( 'handoff_email' ) );
		return ( '' !== $email && is_email( $email ) ) ? $email : get_option( 'admin_email' );
	}

	/**
	 * Whether live two-way human chat is configured (platform key + handoff).
	 *
	 * @return bool
	 */
	public static function human_available() {
		return '' !== trim( (string) self::get( 'aicb_platform_key' ) )
			&& '' !== self::handoff_handle()
			&& '' !== trim( (string) self::get( 'aicb_base' ) );
	}

	/**
	 * Seconds to wait for a human reply before offering the email fallback.
	 *
	 * @return int
	 */
	public static function human_wait_secs() {
		return min( 300, max( 5, (int) self::get( 'human_wait_secs', 30 ) ) );
	}

	/**
	 * Register hooks for the settings UI.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register the option and its sanitizer.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'aicbc_group',
			AICBC_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize submitted options.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();
		$prev  = self::all();

		$out['enabled']             = empty( $input['enabled'] ) ? 0 : 1;
		$out['woo_orders']          = empty( $input['woo_orders'] ) ? 0 : 1;
		$out['woo_products']        = empty( $input['woo_products'] ) ? 0 : 1;
		$out['store_conversations'] = empty( $input['store_conversations'] ) ? 0 : 1;
		$out['use_learnings']       = empty( $input['use_learnings'] ) ? 0 : 1;
		$out['forward_leads']       = empty( $input['forward_leads'] ) ? 0 : 1;
		$out['email_leads']         = empty( $input['email_leads'] ) ? 0 : 1;

		$mode        = isset( $input['mode'] ) ? sanitize_key( $input['mode'] ) : 'local';
		$out['mode'] = in_array( $mode, array( 'local', 'hosted' ), true ) ? $mode : 'local';

		$model        = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : '';
		$models       = array( 'claude-sonnet-5', 'claude-haiku-4-5', 'claude-opus-4-8' );
		$out['model'] = in_array( $model, $models, true ) ? $model : 'claude-sonnet-5';

		$out['hosted_site_key'] = isset( $input['hosted_site_key'] ) ? sanitize_text_field( $input['hosted_site_key'] ) : '';
		$out['aicb_handoff']    = isset( $input['aicb_handoff'] ) ? sanitize_text_field( $input['aicb_handoff'] ) : '';
		$out['handoff_email']   = isset( $input['handoff_email'] ) ? sanitize_email( $input['handoff_email'] ) : '';
		$out['human_wait_secs'] = isset( $input['human_wait_secs'] ) ? min( 300, max( 5, absint( $input['human_wait_secs'] ) ) ) : 30;
		$out['bot_name']        = isset( $input['bot_name'] ) ? sanitize_text_field( $input['bot_name'] ) : '';
		$out['greeting']        = isset( $input['greeting'] ) ? sanitize_text_field( $input['greeting'] ) : '';
		$out['instructions']    = isset( $input['instructions'] ) ? sanitize_textarea_field( $input['instructions'] ) : '';
		$out['aicb_recipients'] = isset( $input['aicb_recipients'] ) ? sanitize_text_field( $input['aicb_recipients'] ) : '';

		$base             = isset( $input['aicb_base'] ) ? esc_url_raw( trim( (string) $input['aicb_base'] ) ) : '';
		$out['aicb_base'] = '' !== $base ? untrailingslashit( $base ) : 'https://caimunicate.com';

		// Secrets: keep the stored value when the masked placeholder comes back unchanged.
		$out['anthropic_api_key'] = $this->keep_secret( $input, 'anthropic_api_key', $prev );
		$out['aicb_agent_key']    = $this->keep_secret( $input, 'aicb_agent_key', $prev );
		$out['aicb_platform_key'] = $this->keep_secret( $input, 'aicb_platform_key', $prev );

		return $out;
	}

	/**
	 * Resolve a secret field: blank keeps the previous value, "-" clears it.
	 *
	 * @param array  $input Raw input.
	 * @param string $key   Field key.
	 * @param array  $prev  Previously saved options.
	 * @return string
	 */
	private function keep_secret( $input, $key, $prev ) {
		$val = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
		if ( '' === $val ) {
			return isset( $prev[ $key ] ) ? (string) $prev[ $key ] : '';
		}
		if ( '-' === $val ) {
			return '';
		}
		return sanitize_text_field( $val );
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = self::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AiCB Chat Widget — Settings', 'aicb-chat-widget' ); ?></h1>

			<h2><?php esc_html_e( 'AiCB Chat Widget Pro', 'aicb-chat-widget' ); ?></h2>
			<?php if ( aicbc_license()->is_active() ) : ?>
				<p class="description" style="max-width:640px"><?php esc_html_e( 'Pro is active: managed AI is included with your subscription — no Anthropic API key needed. If you also save your own key below, it is used as a fallback when the included monthly allowance runs out.', 'aicb-chat-widget' ); ?></p>
			<?php else : ?>
				<p class="description" style="max-width:640px"><?php esc_html_e( 'The free version works with your own Anthropic API key (below). Pro includes the AI — no API key, no separate AI bill: subscribe, paste your licence key, done.', 'aicb-chat-widget' ); ?></p>
			<?php endif; ?>
			<?php aicbc_license()->render_panel(); ?>
			<hr/>

			<form method="post" action="options.php">
				<?php settings_fields( 'aicbc_group' ); ?>

				<h2><?php esc_html_e( 'Widget', 'aicb-chat-widget' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show chat widget', 'aicb-chat-widget' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( AICBC_OPTION ); ?>[enabled]" value="1" <?php checked( $o['enabled'], 1 ); ?> /> <?php esc_html_e( 'Display the chat launcher on the public site', 'aicb-chat-widget' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'aicb-chat-widget' ); ?></th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( AICBC_OPTION ); ?>[mode]" value="local" <?php checked( $o['mode'], 'local' ); ?> /> <?php esc_html_e( 'On this site (recommended) — AI answers here with WooCommerce tools, conversations stored below', 'aicb-chat-widget' ); ?></label><br/>
							<label><input type="radio" name="<?php echo esc_attr( AICBC_OPTION ); ?>[mode]" value="hosted" <?php checked( $o['mode'], 'hosted' ); ?> /> <?php esc_html_e( 'Hosted by Caimunicate — embed your caimunicate.com widget site key (no WooCommerce tools)', 'aicb-chat-widget' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aicbc_hosted_key"><?php esc_html_e( 'Hosted site key', 'aicb-chat-widget' ); ?></label></th>
						<td>
							<input type="text" id="aicbc_hosted_key" class="regular-text" name="<?php echo esc_attr( AICBC_OPTION ); ?>[hosted_site_key]" value="<?php echo esc_attr( $o['hosted_site_key'] ); ?>" placeholder="wk_…" />
							<p class="description"><?php esc_html_e( 'Only used in Hosted mode. Create one in your Caimunicate Business tab.', 'aicb-chat-widget' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aicbc_bot_name"><?php esc_html_e( 'Chat title', 'aicb-chat-widget' ); ?></label></th>
						<td><input type="text" id="aicbc_bot_name" class="regular-text" name="<?php echo esc_attr( AICBC_OPTION ); ?>[bot_name]" value="<?php echo esc_attr( $o['bot_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="aicbc_greeting"><?php esc_html_e( 'Greeting', 'aicb-chat-widget' ); ?></label></th>
						<td><input type="text" id="aicbc_greeting" class="large-text" name="<?php echo esc_attr( AICBC_OPTION ); ?>[greeting]" value="<?php echo esc_attr( $o['greeting'] ); ?>" placeholder="<?php esc_attr_e( 'Hi! How can I help?', 'aicb-chat-widget' ); ?>" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Assistant', 'aicb-chat-widget' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aicbc_instructions"><?php esc_html_e( 'Instructions', 'aicb-chat-widget' ); ?></label></th>
						<td>
							<textarea id="aicbc_instructions" class="large-text" rows="10" name="<?php echo esc_attr( AICBC_OPTION ); ?>[instructions]" placeholder="<?php esc_attr_e( 'e.g. We sell organic teas. Shipping is $9.95 flat, free over $75. Returns within 30 days. Be friendly and concise.', 'aicb-chat-widget' ); ?>"><?php echo esc_textarea( $o['instructions'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Tell the assistant about your business: products, shipping, returns, tone, what it must not do. This is sent with every chat.', 'aicb-chat-widget' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aicbc_api_key"><?php esc_html_e( 'Anthropic API key', 'aicb-chat-widget' ); ?></label></th>
						<td>
							<input type="password" id="aicbc_api_key" class="regular-text" name="<?php echo esc_attr( AICBC_OPTION ); ?>[anthropic_api_key]" value="" placeholder="<?php echo esc_attr( '' !== $o['anthropic_api_key'] ? __( 'Saved — leave blank to keep, enter - to clear', 'aicb-chat-widget' ) : 'sk-ant-…' ); ?>" autocomplete="new-password" />
							<p class="description">
								<?php if ( aicbc_license()->is_active() ) : ?>
									<?php esc_html_e( 'Optional with Pro — managed AI is included. A saved key acts as a fallback. Leave blank to keep the saved key; enter a single dash (-) to clear it.', 'aicb-chat-widget' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Required for on-site mode (Bring Your Own Key — free version). Get one at console.anthropic.com, or upgrade to Pro above and skip this. Leave blank to keep the saved key; enter a single dash (-) to clear it.', 'aicb-chat-widget' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aicbc_model"><?php esc_html_e( 'Model', 'aicb-chat-widget' ); ?></label></th>
						<td>
							<select id="aicbc_model" name="<?php echo esc_attr( AICBC_OPTION ); ?>[model]">
								<option value="claude-sonnet-5" <?php selected( $o['model'], 'claude-sonnet-5' ); ?>>Claude Sonnet 5 (recommended)</option>
								<option value="claude-haiku-4-5" <?php selected( $o['model'], 'claude-haiku-4-5' ); ?>>Claude Haiku 4.5 (fastest / cheapest)</option>
								<option value="claude-opus-4-8" <?php selected( $o['model'], 'claude-opus-4-8' ); ?>>Claude Opus 4.8 (most capable)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Learning', 'aicb-chat-widget' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( AICBC_OPTION ); ?>[store_conversations]" value="1" <?php checked( $o['store_conversations'], 1 ); ?> /> <?php esc_html_e( 'Store conversations for review (AiCB Chat → Conversations)', 'aicb-chat-widget' ); ?></label><br/>
							<label><input type="checkbox" name="<?php echo esc_attr( AICBC_OPTION ); ?>[use_learnings]" value="1" <?php checked( $o['use_learnings'], 1 ); ?> /> <?php esc_html_e( 'Apply saved learnings to future chats (AiCB Chat → Learnings)', 'aicb-chat-widget' ); ?></label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'WooCommerce tools', 'aicb-chat-widget' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Abilities', 'aicb-chat-widget' ); ?></th>
						<td>
							<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
								<p class="description"><?php esc_html_e( 'WooCommerce is not active — these tools will stay off until it is.', 'aicb-chat-widget' ); ?></p>
							<?php endif; ?>
							<label><input type="checkbox" name="<?php echo esc_attr( AICBC_OPTION ); ?>[woo_orders]" value="1" <?php checked( $o['woo_orders'], 1 ); ?> /> <?php esc_html_e( 'Check order status (visitor must supply order number AND the billing email — both must match)', 'aicb-chat-widget' ); ?></label><br/>
							<label><input type="checkbox" name="<?php echo esc_attr( AICBC_OPTION ); ?>[woo_products]" value="1" <?php checked( $o['woo_products'], 1 ); ?> /> <?php esc_html_e( 'Look up product info (search the catalogue, prices, stock)', 'aicb-chat-widget' ); ?></label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Forward to your team', 'aicb-chat-widget' ); ?></h2>
				<p class="description" style="max-width:640px"><?php esc_html_e( 'When a visitor asks for a human (or the assistant decides to escalate), the chat and any leads are emailed to you — that works out of the box with no other setup. Optionally, also connect AiCB/Caimunicate below to receive them as instant @username messages and to offer live two-way chat inside the widget.', 'aicb-chat-widget' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aicbc_handoff_email"><?php esc_html_e( 'Escalation email', 'aicb-chat-widget' ); ?></label></th>
						<td>
							<input type="email" id="aicbc_handoff_email" class="regular-text" name="<?php echo esc_attr( AICBC_OPTION ); ?>[handoff_email]" value="<?php echo esc_attr( $o['handoff_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Escalations and leads are emailed here. Leave blank to use the site admin address.', 'aicb-chat-widget' ); ?></p>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'AiCB / Caimunicate (optional)', 'aicb-chat-widget' ); ?></h3>
				<p class="description" style="max-width:640px"><?php esc_html_e( 'Register an agent for your account (Caimunicate app → agents) to get an agent API key. Leave these blank to run email-only.', 'aicb-chat-widget' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aicbc_aicb_base"><?php esc_html_e( 'AiCB endpoint', 'aicb-chat-widget' ); ?></label></th>
						<td><input type="url" id="aicbc_aicb_base" class="regular-text" name="<?php echo esc_attr( AICBC_OPTION ); ?>[aicb_base]" value="<?php echo esc_attr( $o['aicb_base'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="aicbc_agent_key"><?php esc_html_e( 'Agent API key', 'aicb-chat-widget' ); ?></label></th>
						<td><input type="password" id="aicbc_agent_key" class="regular-text" name="<?php echo esc_attr( AICBC_OPTION ); ?>[aicb_agent_key]" value="" placeholder="<?php echo esc_attr( '' !== $o['aicb_agent_key'] ? __( 'Saved — leave blank to keep, enter - to clear', 'aicb-chat-widget' ) : 'ak_…' ); ?>" autocomplete="new-password" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="aicbc_recipients"><?php esc_html_e( 'Forward to @usernames', 'aicb-chat-widget' ); ?></label></th>
						<td>
							<input type="text" id="aicbc_recipients" class="regular-text" name="<?php echo esc_attr( AICBC_OPTION ); ?>[aicb_recipients]" value="<?php echo esc_attr( $o['aicb_recipients'] ); ?>" placeholder="@markus, @support" />
							<p class="description"><?php esc_html_e( 'Comma-separated AiCB handles that receive escalations and leads.', 'aicb-chat-widget' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aicbc_handoff"><?php esc_html_e( 'Live chat @username', 'aicb-chat-widget' ); ?></label></th>
						<td>
							<input type="text" id="aicbc_handoff" class="regular-text" name="<?php echo esc_attr( AICBC_OPTION ); ?>[aicb_handoff]" value="<?php echo esc_attr( $o['aicb_handoff'] ); ?>" placeholder="@markus" />
							<p class="description"><?php esc_html_e( 'Who visitors chat with live inside the widget (two-way, via Caimunicate). Defaults to the first forwarding @username above.', 'aicb-chat-widget' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aicbc_platform_key"><?php esc_html_e( 'Platform key', 'aicb-chat-widget' ); ?></label></th>
						<td>
							<input type="password" id="aicbc_platform_key" class="regular-text" name="<?php echo esc_attr( AICBC_OPTION ); ?>[aicb_platform_key]" value="" placeholder="<?php echo esc_attr( '' !== $o['aicb_platform_key'] ? __( 'Saved — leave blank to keep, enter - to clear', 'aicb-chat-widget' ) : __( 'Caimunicate platform key', 'aicb-chat-widget' ) ); ?>" autocomplete="new-password" />
							<p class="description"><?php esc_html_e( 'Required for live human chat: lets the widget mint a per-visitor relay agent on Caimunicate. Without it, visitors get the “leave your details” card instead.', 'aicb-chat-widget' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aicbc_human_wait"><?php esc_html_e( 'No-reply timeout', 'aicb-chat-widget' ); ?></label></th>
						<td>
							<input type="number" id="aicbc_human_wait" min="5" max="300" step="1" name="<?php echo esc_attr( AICBC_OPTION ); ?>[human_wait_secs]" value="<?php echo esc_attr( (string) $o['human_wait_secs'] ); ?>" style="width:90px" /> <?php esc_html_e( 'seconds', 'aicb-chat-widget' ); ?>
							<p class="description"><?php esc_html_e( 'If the team member hasn’t replied within this time, the visitor is told they’re unavailable and the message is forwarded as an email/lead.', 'aicb-chat-widget' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Leads', 'aicb-chat-widget' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( AICBC_OPTION ); ?>[forward_leads]" value="1" <?php checked( $o['forward_leads'], 1 ); ?> /> <?php esc_html_e( 'Forward “leave your details” leads to the AiCB @usernames above', 'aicb-chat-widget' ); ?></label><br/>
							<label><input type="checkbox" name="<?php echo esc_attr( AICBC_OPTION ); ?>[email_leads]" value="1" <?php checked( $o['email_leads'], 1 ); ?> /> <?php esc_html_e( 'Also email leads/escalations when AiCB delivers them (email always fires when AiCB is not set up)', 'aicb-chat-widget' ); ?></label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

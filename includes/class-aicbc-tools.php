<?php
/**
 * Assistant tools: WooCommerce lookups and AiCB forwarding.
 *
 * @package AiCB_Chat_Widget
 */

defined( 'ABSPATH' ) || exit;

/**
 * Executes the tool calls the assistant makes, and handles forwarding
 * chats/leads to the configured AiCB @usernames on Caimunicate.
 */
class AICBC_Tools {

	/**
	 * Whether WooCommerce is available.
	 *
	 * @return bool
	 */
	public static function woo_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * Tool definitions for the Anthropic API, based on current settings.
	 *
	 * @return array
	 */
	public static function definitions() {
		$tools = array();

		if ( self::woo_active() && AICBC_Settings::get( 'woo_orders' ) ) {
			$tools[] = array(
				'name'         => 'lookup_order',
				'description'  => 'Look up the status of a WooCommerce order. BOTH the order number and the billing email used on the order are required, and they must match — never guess or omit the email. If the visitor has not provided both, ask for them first.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_number' => array(
							'type'        => 'string',
							'description' => 'The order number the visitor was given (digits, e.g. 1234).',
						),
						'email'        => array(
							'type'        => 'string',
							'description' => 'The billing email address used when placing the order.',
						),
					),
					'required'   => array( 'order_number', 'email' ),
				),
			);
		}

		if ( self::woo_active() && AICBC_Settings::get( 'woo_products' ) ) {
			$tools[] = array(
				'name'         => 'search_products',
				'description'  => 'Search the store product catalogue by keyword. Returns up to 5 matching products with price, stock status and link.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' => 'Search keywords, e.g. "green tea 500g".',
						),
					),
					'required'   => array( 'query' ),
				),
			);
			$tools[] = array(
				'name'         => 'get_product',
				'description'  => 'Get full details of one product by its numeric ID (from search_products) or SKU.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array(
							'type'        => 'string',
							'description' => 'Product ID or SKU.',
						),
					),
					'required'   => array( 'product_id' ),
				),
			);
		}

		// Always available: escalations go by email at minimum, plus AiCB when configured.
		$tools[] = array(
			'name'         => 'forward_to_team',
			'description'  => 'Forward this conversation to a human team member. Use when the visitor asks for a human, is upset, or the question cannot be answered with the information and tools available. Include a short summary of what the visitor needs.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'summary' => array(
						'type'        => 'string',
						'description' => 'One or two sentences: who the visitor is (if known) and what they need.',
					),
				),
				'required'   => array( 'summary' ),
			),
		);

		return $tools;
	}

	/**
	 * Execute a tool call and return a plain-text result for the model.
	 *
	 * @param string $name            Tool name.
	 * @param array  $input           Tool input.
	 * @param int    $conversation_id Conversation id (for forwarding context).
	 * @return array { result: string, handoff: bool }
	 */
	public static function run( $name, $input, $conversation_id ) {
		$input = is_array( $input ) ? $input : array();
		switch ( $name ) {
			case 'lookup_order':
				return array(
					'result'  => self::lookup_order( $input ),
					'handoff' => false,
				);
			case 'search_products':
				return array(
					'result'  => self::search_products( $input ),
					'handoff' => false,
				);
			case 'get_product':
				return array(
					'result'  => self::get_product( $input ),
					'handoff' => false,
				);
			case 'forward_to_team':
				$summary = isset( $input['summary'] ) ? sanitize_textarea_field( (string) $input['summary'] ) : '';
				$ok      = self::forward_conversation( $conversation_id, $summary );
				return array(
					'result'  => $ok
						? 'The conversation was forwarded to the team. Tell the visitor a team member has been notified and will follow up, and offer to take their contact details.'
						: 'Forwarding is not available right now. Apologise and offer the "leave your details" option instead.',
					'handoff' => true,
				);
		}
		return array(
			'result'  => 'Unknown tool.',
			'handoff' => false,
		);
	}

	/**
	 * Order status lookup — requires order number + matching billing email.
	 *
	 * @param array $input Tool input.
	 * @return string
	 */
	private static function lookup_order( $input ) {
		if ( ! self::woo_active() || ! AICBC_Settings::get( 'woo_orders' ) ) {
			return 'Order lookup is not available.';
		}
		$number = isset( $input['order_number'] ) ? preg_replace( '/[^0-9]/', '', (string) $input['order_number'] ) : '';
		$email  = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
		if ( '' === $number || '' === $email || ! is_email( $email ) ) {
			return 'Both a numeric order number and a valid billing email are required.';
		}

		$order = wc_get_order( (int) $number );
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return 'No order was found for that order number and email. Ask the visitor to double-check both.';
		}
		$billing = strtolower( (string) $order->get_billing_email() );
		if ( '' === $billing || strtolower( $email ) !== $billing ) {
			// Same message as not-found so the tool can't be used to probe which order numbers exist.
			return 'No order was found for that order number and email. Ask the visitor to double-check both.';
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_quantity() . ' x ' . $item->get_name();
		}
		$created = $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '';
		$lines   = array(
			'Order #' . $order->get_order_number(),
			'Status: ' . wc_get_order_status_name( $order->get_status() ),
			'Placed: ' . $created,
			'Total: ' . html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES ),
			'Items: ' . implode( '; ', $items ),
		);
		$ship    = $order->get_shipping_method();
		if ( $ship ) {
			$lines[] = 'Shipping method: ' . $ship;
		}
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'customer',
				'limit'    => 3,
			)
		);
		if ( ! empty( $notes ) ) {
			$note_lines = array();
			foreach ( $notes as $n ) {
				$note_lines[] = wp_strip_all_tags( $n->content );
			}
			$lines[] = 'Latest updates for the customer: ' . implode( ' | ', $note_lines );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Keyword product search.
	 *
	 * @param array $input Tool input.
	 * @return string
	 */
	private static function search_products( $input ) {
		if ( ! self::woo_active() || ! AICBC_Settings::get( 'woo_products' ) ) {
			return 'Product lookup is not available.';
		}
		$query = isset( $input['query'] ) ? sanitize_text_field( (string) $input['query'] ) : '';
		if ( '' === $query ) {
			return 'A search query is required.';
		}
		$products = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => 5,
				's'      => $query,
			)
		);
		if ( empty( $products ) ) {
			return 'No products matched "' . $query . '".';
		}
		$lines = array();
		foreach ( $products as $p ) {
			$lines[] = self::product_line( $p );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Single product details by ID or SKU.
	 *
	 * @param array $input Tool input.
	 * @return string
	 */
	private static function get_product( $input ) {
		if ( ! self::woo_active() || ! AICBC_Settings::get( 'woo_products' ) ) {
			return 'Product lookup is not available.';
		}
		$ref = isset( $input['product_id'] ) ? sanitize_text_field( (string) $input['product_id'] ) : '';
		if ( '' === $ref ) {
			return 'A product ID or SKU is required.';
		}
		$product = null;
		if ( ctype_digit( $ref ) ) {
			$product = wc_get_product( (int) $ref );
		}
		if ( ! $product ) {
			$id = wc_get_product_id_by_sku( $ref );
			if ( $id ) {
				$product = wc_get_product( $id );
			}
		}
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return 'No published product was found for "' . $ref . '".';
		}
		$lines = array( self::product_line( $product ) );
		$desc  = wp_strip_all_tags( $product->get_short_description() ? $product->get_short_description() : $product->get_description() );
		if ( '' !== $desc ) {
			$lines[] = 'Description: ' . mb_substr( $desc, 0, 600 );
		}
		if ( $product->is_type( 'variable' ) ) {
			$lines[] = 'This is a variable product — attributes: ' . implode( ', ', array_keys( $product->get_attributes() ) );
		}
		return implode( "\n", $lines );
	}

	/**
	 * One-line product summary.
	 *
	 * @param WC_Product $p Product.
	 * @return string
	 */
	private static function product_line( $p ) {
		$price = html_entity_decode( wp_strip_all_tags( wc_price( (float) $p->get_price() ) ), ENT_QUOTES );
		$stock = $p->is_in_stock() ? 'in stock' : 'out of stock';
		if ( $p->managing_stock() && null !== $p->get_stock_quantity() ) {
			$stock .= ' (' . (int) $p->get_stock_quantity() . ' left)';
		}
		return sprintf(
			'[ID %d] %s — %s — %s — %s',
			$p->get_id(),
			$p->get_name(),
			$price,
			$stock,
			get_permalink( $p->get_id() )
		);
	}

	/**
	 * Forward a conversation summary + transcript tail to the team: by email
	 * always (the guaranteed channel), and to the AiCB @usernames when
	 * Caimunicate forwarding is configured.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param string $summary         Assistant-written summary.
	 * @return bool True when at least one channel accepted the message.
	 */
	public static function forward_conversation( $conversation_id, $summary ) {
		$text = '🤖 Chat escalation from ' . home_url( '/' ) . "\n";
		if ( '' !== $summary ) {
			$text .= $summary . "\n";
		}
		$msgs = AICBC_Store::get_messages( (int) $conversation_id, 12 );
		if ( ! empty( $msgs ) ) {
			$text .= "--- Recent transcript ---\n";
			foreach ( $msgs as $m ) {
				if ( 'visitor' === $m->role || 'assistant' === $m->role ) {
					$text .= ( 'visitor' === $m->role ? 'Visitor: ' : 'Bot: ' ) . mb_substr( (string) $m->content, 0, 400 ) . "\n";
				}
			}
		}
		$text .= 'Review: ' . admin_url( 'admin.php?page=aicbc-conversations&conversation=' . (int) $conversation_id );

		$sent = false;
		if ( AICBC_Settings::can_forward() ) {
			$sent = self::send_aicb( $text );
		}
		// Email is the channel that works with zero AiCB setup. Skip it only when
		// AiCB delivered AND the owner turned email copies off.
		if ( ! $sent || AICBC_Settings::get( 'email_leads' ) ) {
			$mailed = wp_mail(
				AICBC_Settings::handoff_email(),
				/* translators: %s: site name. */
				sprintf( __( '[%s] Chat escalation — visitor needs a human', 'aicb-chat-widget' ), get_bloginfo( 'name' ) ),
				$text
			);
			$sent = $sent || $mailed;
		}
		if ( $sent ) {
			AICBC_Store::add_message( (int) $conversation_id, 'tool', __( 'Conversation forwarded to the team.', 'aicb-chat-widget' ) );
		}
		return $sent;
	}

	/**
	 * Forward a "leave your details" lead.
	 *
	 * @param int   $conversation_id Conversation id.
	 * @param array $lead            name, email, phone, message.
	 * @return bool
	 */
	public static function forward_lead( $conversation_id, $lead ) {
		$text  = '✋ New lead from the chat widget on ' . home_url( '/' ) . "\n";
		$text .= 'Name: ' . $lead['name'] . "\n";
		if ( '' !== $lead['email'] ) {
			$text .= 'Email: ' . $lead['email'] . "\n";
		}
		if ( '' !== $lead['phone'] ) {
			$text .= 'Phone: ' . $lead['phone'] . "\n";
		}
		if ( '' !== $lead['message'] ) {
			$text .= 'Message: ' . $lead['message'] . "\n";
		}
		$text .= 'Review: ' . admin_url( 'admin.php?page=aicbc-conversations&conversation=' . (int) $conversation_id );

		$ok = false;
		if ( AICBC_Settings::get( 'forward_leads' ) && AICBC_Settings::can_forward() ) {
			$ok = self::send_aicb( $text );
		}
		// Email always fires as the fallback channel; with AiCB delivered it
		// respects the "also email leads" toggle.
		if ( ! $ok || AICBC_Settings::get( 'email_leads' ) ) {
			$mailed = wp_mail(
				AICBC_Settings::handoff_email(),
				/* translators: %s: site name. */
				sprintf( __( '[%s] New chat lead', 'aicb-chat-widget' ), get_bloginfo( 'name' ) ),
				$text
			);
			$ok = $ok || $mailed;
		}
		return $ok;
	}

	/**
	 * Mint (or rotate) a per-visitor relay agent on Caimunicate bound to the
	 * handoff @username, and store its credentials on the conversation.
	 *
	 * @param object $conv Conversation row.
	 * @return object|null Refreshed conversation with relay fields, or null.
	 */
	public static function provision_relay( $conv ) {
		if ( ! AICBC_Settings::human_available() ) {
			return null;
		}
		if ( ! empty( $conv->relay_key ) ) {
			return $conv;
		}
		$base    = untrailingslashit( (string) AICBC_Settings::get( 'aicb_base' ) );
		$handoff = AICBC_Settings::handoff_handle();
		$handle  = 'wpc-' . substr( md5( $conv->session_key ), 0, 8 );
		$res     = wp_remote_post(
			$base . '/api/aicb/provision-agent',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'        => 'application/json',
					'x-aicb-platform-key' => (string) AICBC_Settings::get( 'aicb_platform_key' ),
				),
				'body'    => wp_json_encode(
					array(
						'owner_handle' => $handoff,
						'agent_handle' => $handle,
						'name'         => get_bloginfo( 'name' ) . ' visitor chat',
						'description'  => 'Live website-chat relay from ' . home_url( '/' ),
					)
				),
			)
		);
		if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) >= 300 ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$key  = isset( $data['agent']['apiKey'] ) ? (string) $data['agent']['apiKey'] : '';
		if ( '' === $key ) {
			return null;
		}
		AICBC_Store::update_conversation(
			(int) $conv->id,
			array(
				'mode'         => 'human',
				'relay_handle' => $handle,
				'relay_key'    => $key,
				'relay_cursor' => (int) round( microtime( true ) * 1000 ),
			)
		);
		return AICBC_Store::get_conversation( (int) $conv->id );
	}

	/**
	 * Relay a visitor message to the handoff @username via the conversation's
	 * per-visitor agent.
	 *
	 * @param object $conv Conversation row (mode=human).
	 * @param string $text Message text.
	 * @return bool
	 */
	public static function relay_send( $conv, $text ) {
		if ( empty( $conv->relay_key ) ) {
			return false;
		}
		$base = untrailingslashit( (string) AICBC_Settings::get( 'aicb_base' ) );
		$res  = wp_remote_post(
			$base . '/api/aicb/messages',
			array(
				'timeout' => 12,
				'headers' => array(
					'Content-Type' => 'application/json',
					'x-api-key'    => (string) $conv->relay_key,
				),
				'body'    => wp_json_encode(
					array(
						'to'   => '@' . AICBC_Settings::handoff_handle(),
						'text' => mb_substr( (string) $text, 0, 4000 ),
					)
				),
			)
		);
		return ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) < 300;
	}

	/**
	 * Fetch new replies from the handoff @username since the stored cursor.
	 *
	 * @param object $conv Conversation row (mode=human).
	 * @return array Array of reply text strings (possibly empty).
	 */
	public static function relay_poll( $conv ) {
		if ( empty( $conv->relay_key ) ) {
			return array();
		}
		$base = untrailingslashit( (string) AICBC_Settings::get( 'aicb_base' ) );
		$res  = wp_remote_get(
			$base . '/api/aicb/messages?since=' . rawurlencode( (string) (int) $conv->relay_cursor ),
			array(
				'timeout' => 12,
				'headers' => array( 'x-api-key' => (string) $conv->relay_key ),
			)
		);
		if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) >= 300 ) {
			return array();
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$msgs = isset( $data['messages'] ) && is_array( $data['messages'] ) ? $data['messages'] : array();
		$out  = array();
		foreach ( $msgs as $m ) {
			if ( isset( $m['text'] ) && '' !== trim( (string) $m['text'] ) ) {
				$out[] = (string) $m['text'];
			}
		}
		if ( isset( $data['cursor'] ) && (int) $data['cursor'] > (int) $conv->relay_cursor ) {
			AICBC_Store::update_conversation( (int) $conv->id, array( 'relay_cursor' => (int) $data['cursor'] ) );
		}
		if ( $out && AICBC_Settings::get( 'store_conversations' ) ) {
			foreach ( $out as $t ) {
				AICBC_Store::add_message( (int) $conv->id, 'human', $t );
			}
		}
		return $out;
	}

	/**
	 * Send a text message to every configured AiCB recipient.
	 *
	 * @param string $text Message body.
	 * @return bool True when at least one send succeeded.
	 */
	public static function send_aicb( $text ) {
		$base = untrailingslashit( (string) AICBC_Settings::get( 'aicb_base' ) );
		$key  = (string) AICBC_Settings::get( 'aicb_agent_key' );
		$to   = AICBC_Settings::recipients();
		if ( '' === $base || '' === $key || empty( $to ) ) {
			return false;
		}
		$ok = false;
		foreach ( $to as $handle ) {
			$res = wp_remote_post(
				$base . '/api/aicb/messages',
				array(
					'timeout' => 12,
					'headers' => array(
						'Content-Type' => 'application/json',
						'x-api-key'    => $key,
					),
					'body'    => wp_json_encode(
						array(
							'to'   => '@' . $handle,
							'text' => mb_substr( $text, 0, 4000 ),
						)
					),
				)
			);
			if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) < 300 ) {
				$ok = true;
			}
		}
		return $ok;
	}
}

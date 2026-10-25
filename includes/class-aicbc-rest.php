<?php
/**
 * Public REST endpoints for the chat widget.
 *
 * @package AiCB_Chat_Widget
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers /aicb-chat/v1/start, /message and /lead. These are public
 * (anonymous site visitors), protected by a per-conversation signed token
 * plus IP rate limiting.
 */
class AICBC_REST {

	const NS = 'aicb-chat/v1';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the widget routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/message',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'message' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/lead',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'lead' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/human',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'human' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/poll',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'poll' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/resume',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resume' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Whether cross-page chat persistence is available (Pro feature; needs
	 * stored conversations to restore the transcript from).
	 *
	 * @return bool
	 */
	private function persist_enabled() {
		return aicbc_license()->is_active() && (bool) AICBC_Settings::get( 'store_conversations' );
	}

	/**
	 * POST /start — open a conversation.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function start( $req ) {
		if ( ! AICBC_Settings::get( 'enabled' ) || 'local' !== AICBC_Settings::get( 'mode' ) ) {
			return new WP_REST_Response( array( 'error' => 'Chat is not available.' ), 403 );
		}
		if ( ! $this->allow_ip( 'start', 10, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_REST_Response( array( 'error' => 'Too many requests — please try again shortly.' ), 429 );
		}

		$page = sanitize_text_field( (string) $req->get_param( 'page' ) );
		$conv = AICBC_Store::create_conversation( $page );
		if ( ! $conv ) {
			return new WP_REST_Response( array( 'error' => 'Could not start a chat.' ), 500 );
		}

		return new WP_REST_Response(
			array(
				'threadId'     => $conv->id . '.' . $this->token( $conv ),
				'businessName' => AICBC_Settings::bot_name(),
				'greeting'     => array( 'text' => AICBC_Settings::greeting() ),
				'human'        => AICBC_Settings::human_available(),
				'humanName'    => ucfirst( AICBC_Settings::handoff_handle() ),
				'waitMs'       => AICBC_Settings::human_wait_secs() * 1000,
				'persist'      => $this->persist_enabled(),
			),
			200
		);
	}

	/**
	 * POST /resume — restore a persisted conversation on a new page load
	 * (Pro feature). Returns the transcript tail + live-chat state so the
	 * widget can continue seamlessly across pages.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function resume( $req ) {
		if ( ! $this->persist_enabled() ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}
		$conv = $this->conversation_from( $req );
		if ( ! $conv ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}
		if ( ! $this->allow_ip( 'resume', 20, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 429 );
		}
		// Only fresh, still-usable conversations resume.
		$age = time() - strtotime( $conv->last_at . ' UTC' );
		if ( $age > DAY_IN_SECONDS || (int) $conv->message_count > 200 ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}

		$messages = array();
		foreach ( AICBC_Store::get_messages( (int) $conv->id, 40 ) as $m ) {
			if ( in_array( $m->role, array( 'visitor', 'assistant', 'human' ), true ) ) {
				$messages[] = array(
					'role' => 'visitor' === $m->role ? 'visitor' : 'bot',
					'text' => (string) $m->content,
				);
			}
		}

		return new WP_REST_Response(
			array(
				'ok'           => true,
				'businessName' => AICBC_Settings::bot_name(),
				'human'        => AICBC_Settings::human_available(),
				'humanName'    => ucfirst( AICBC_Settings::handoff_handle() ),
				'waitMs'       => AICBC_Settings::human_wait_secs() * 1000,
				'persist'      => true,
				'mode'         => (string) $conv->mode,
				'visitorName'  => (string) $conv->visitor_name,
				'messages'     => $messages,
			),
			200
		);
	}

	/**
	 * POST /message — visitor message in, assistant reply out.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function message( $req ) {
		$conv = $this->conversation_from( $req );
		if ( ! $conv ) {
			return new WP_REST_Response( array( 'error' => 'Invalid or expired chat session — please reopen the chat.' ), 403 );
		}
		if ( ! $this->allow_ip( 'msg', 12, MINUTE_IN_SECONDS ) ) {
			return new WP_REST_Response( array( 'error' => 'You are sending messages too quickly — give me a moment.' ), 429 );
		}
		if ( (int) $conv->message_count > 200 ) {
			return new WP_REST_Response( array( 'error' => 'This chat is quite long — please start a new one.' ), 429 );
		}

		$text = trim( (string) $req->get_param( 'text' ) );
		$text = sanitize_textarea_field( mb_substr( $text, 0, 2000 ) );
		if ( '' === $text ) {
			return new WP_REST_Response( array( 'error' => 'Empty message.' ), 400 );
		}

		$store = (bool) AICBC_Settings::get( 'store_conversations' );
		if ( $store ) {
			AICBC_Store::add_message( (int) $conv->id, 'visitor', $text );
		}

		// Live human mode: relay to the team member instead of the AI.
		if ( 'human' === $conv->mode ) {
			$name = '' !== $conv->visitor_name ? $conv->visitor_name . ': ' : '';
			$sent = AICBC_Tools::relay_send( $conv, $name . $text );
			if ( $sent ) {
				return new WP_REST_Response( array( 'relayed' => true ), 200 );
			}
			return new WP_REST_Response(
				array(
					'relayed' => false,
					'reply'   => array( 'text' => __( 'Sorry — the live connection dropped. Please leave your details and we’ll get back to you.', 'aicb-chat-widget' ) ),
				),
				200
			);
		}

		$reply = AICBC_AI::reply( $conv, $text );

		if ( $store ) {
			AICBC_Store::add_message( (int) $conv->id, 'assistant', $reply['text'] );
		} else {
			AICBC_Store::update_conversation( (int) $conv->id, array( 'last_at' => current_time( 'mysql', true ) ) );
		}

		return new WP_REST_Response(
			array(
				'reply'        => array( 'text' => $reply['text'] ),
				'offerHandoff' => (bool) $reply['handoff'],
			),
			200
		);
	}

	/**
	 * POST /lead — the "leave your details" card.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function lead( $req ) {
		$conv = $this->conversation_from( $req );
		if ( ! $conv ) {
			return new WP_REST_Response( array( 'error' => 'Invalid or expired chat session — please reopen the chat.' ), 403 );
		}
		if ( ! $this->allow_ip( 'lead', 5, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_REST_Response( array( 'error' => 'Too many requests — please try again shortly.' ), 429 );
		}

		$lead = array(
			'name'    => sanitize_text_field( mb_substr( (string) $req->get_param( 'name' ), 0, 120 ) ),
			'email'   => sanitize_email( (string) $req->get_param( 'email' ) ),
			'phone'   => sanitize_text_field( mb_substr( (string) $req->get_param( 'phone' ), 0, 40 ) ),
			'message' => sanitize_textarea_field( mb_substr( (string) $req->get_param( 'message' ), 0, 1500 ) ),
		);
		if ( '' === $lead['name'] || ( '' === $lead['email'] && '' === $lead['phone'] ) ) {
			return new WP_REST_Response( array( 'error' => 'A name plus an email or phone is required.' ), 400 );
		}

		AICBC_Store::add_message( (int) $conv->id, 'lead', 'Lead: ' . $lead['name'] . ' | ' . $lead['email'] . ' | ' . $lead['phone'] . "\n" . $lead['message'] );
		AICBC_Store::update_conversation(
			(int) $conv->id,
			array(
				'lead_name'    => $lead['name'],
				'lead_contact' => trim( $lead['email'] . ' ' . $lead['phone'] ),
				'status'       => 'lead',
			)
		);

		AICBC_Tools::forward_lead( (int) $conv->id, $lead );

		return new WP_REST_Response(
			array( 'message' => __( 'Thanks — we’ve passed your details to the team and will be in touch soon.', 'aicb-chat-widget' ) ),
			200
		);
	}

	/**
	 * POST /human — collect name+email, then connect the visitor to the team
	 * member for live two-way chat inside the widget.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function human( $req ) {
		$conv = $this->conversation_from( $req );
		if ( ! $conv ) {
			return new WP_REST_Response( array( 'error' => 'Invalid or expired chat session — please reopen the chat.' ), 403 );
		}
		if ( ! $this->allow_ip( 'human', 6, HOUR_IN_SECONDS ) ) {
			return new WP_REST_Response( array( 'error' => 'Too many requests — please try again shortly.' ), 429 );
		}

		$name  = sanitize_text_field( mb_substr( (string) $req->get_param( 'name' ), 0, 120 ) );
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		if ( '' === $name ) {
			return new WP_REST_Response( array( 'error' => __( 'Please enter your name.', 'aicb-chat-widget' ) ), 400 );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Please enter a valid email address.', 'aicb-chat-widget' ) ), 400 );
		}

		AICBC_Store::update_conversation(
			(int) $conv->id,
			array(
				'visitor_name'  => $name,
				'visitor_email' => $email,
			)
		);
		$conv = AICBC_Store::get_conversation( (int) $conv->id );

		$live = AICBC_Tools::provision_relay( $conv );
		if ( ! $live ) {
			return new WP_REST_Response( array( 'live' => false ), 200 );
		}

		// Open the line: tell the team member who's waiting + recent context.
		$intro = 'New live chat from ' . $name . ' <' . $email . '> on ' . home_url( '/' );
		$msgs  = AICBC_Store::get_messages( (int) $conv->id, 8 );
		foreach ( $msgs as $m ) {
			if ( 'visitor' === $m->role || 'assistant' === $m->role ) {
				$intro .= "\n" . ( 'visitor' === $m->role ? 'Visitor: ' : 'Bot: ' ) . mb_substr( (string) $m->content, 0, 300 );
			}
		}
		AICBC_Tools::relay_send( $live, $intro );
		if ( AICBC_Settings::get( 'store_conversations' ) ) {
			AICBC_Store::add_message( (int) $conv->id, 'tool', __( 'Live chat connected — visitor talking to the team via AiCB.', 'aicb-chat-widget' ) );
		}

		$first = trim( strtok( $name, ' ' ) );
		return new WP_REST_Response(
			array(
				'live'  => true,
				/* translators: 1: visitor first name, 2: team member name. */
				'greet' => sprintf( __( 'Thanks %1$s — you’re connected. %2$s has been notified; ask away and replies will appear here.', 'aicb-chat-widget' ), $first, ucfirst( AICBC_Settings::handoff_handle() ) ),
			),
			200
		);
	}

	/**
	 * POST /poll — fetch live replies from the team member for a human-mode chat.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function poll( $req ) {
		$conv = $this->conversation_from( $req );
		if ( ! $conv || 'human' !== $conv->mode ) {
			return new WP_REST_Response( array( 'error' => 'Not a live chat.' ), 403 );
		}
		if ( ! $this->allow_ip( 'poll', 240, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_REST_Response( array( 'messages' => array() ), 200 );
		}
		return new WP_REST_Response( array( 'messages' => AICBC_Tools::relay_poll( $conv ) ), 200 );
	}

	/**
	 * HMAC token for a conversation session.
	 *
	 * @param object $conv Conversation row.
	 * @return string
	 */
	private function token( $conv ) {
		return hash_hmac( 'sha256', 'aicbc|' . $conv->id . '|' . $conv->session_key, wp_salt( 'auth' ) );
	}

	/**
	 * Resolve and verify the conversation from a request's threadId.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return object|null
	 */
	private function conversation_from( $req ) {
		if ( ! AICBC_Settings::get( 'enabled' ) || 'local' !== AICBC_Settings::get( 'mode' ) ) {
			return null;
		}
		$thread = (string) $req->get_param( 'threadId' );
		if ( ! preg_match( '/^(\d{1,12})\.([a-f0-9]{64})$/', $thread, $m ) ) {
			return null;
		}
		$conv = AICBC_Store::get_conversation( (int) $m[1] );
		if ( ! $conv ) {
			return null;
		}
		if ( ! hash_equals( $this->token( $conv ), $m[2] ) ) {
			return null;
		}
		return $conv;
	}

	/**
	 * Transient-based per-IP rate limiter.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $max    Max hits per window.
	 * @param int    $window Window in seconds.
	 * @return bool True when the request is allowed.
	 */
	private function allow_ip( $bucket, $max, $window ) {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key  = 'aicbc_rl_' . $bucket . '_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= $max ) {
			return false;
		}
		set_transient( $key, $hits + 1, $window );
		return true;
	}
}

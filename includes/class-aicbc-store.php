<?php
/**
 * Conversation, message and learning storage for AiCB Chat Widget.
 *
 * @package AiCB_Chat_Widget
 */

defined( 'ABSPATH' ) || exit;

/**
 * Custom-table persistence. All reads/writes are deliberate direct queries
 * against plugin-owned tables.
 */
class AICBC_Store {

	/**
	 * Conversations table name.
	 *
	 * @return string
	 */
	public static function conversations_table() {
		global $wpdb;
		return $wpdb->prefix . 'aicbc_conversations';
	}

	/**
	 * Messages table name.
	 *
	 * @return string
	 */
	public static function messages_table() {
		global $wpdb;
		return $wpdb->prefix . 'aicbc_messages';
	}

	/**
	 * Learnings table name.
	 *
	 * @return string
	 */
	public static function learnings_table() {
		global $wpdb;
		return $wpdb->prefix . 'aicbc_learnings';
	}

	/**
	 * Create tables on activation.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$conversations = self::conversations_table();
		$messages      = self::messages_table();
		$learnings     = self::learnings_table();

		dbDelta(
			"CREATE TABLE {$conversations} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				session_key CHAR(64) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'open',
				reviewed TINYINT(1) NOT NULL DEFAULT 0,
				page_url VARCHAR(255) NOT NULL DEFAULT '',
				preview VARCHAR(191) NOT NULL DEFAULT '',
				message_count INT(11) NOT NULL DEFAULT 0,
				lead_name VARCHAR(191) NOT NULL DEFAULT '',
				lead_contact VARCHAR(191) NOT NULL DEFAULT '',
				mode VARCHAR(10) NOT NULL DEFAULT 'ai',
				visitor_name VARCHAR(120) NOT NULL DEFAULT '',
				visitor_email VARCHAR(190) NOT NULL DEFAULT '',
				relay_handle VARCHAR(40) NOT NULL DEFAULT '',
				relay_key VARCHAR(80) NOT NULL DEFAULT '',
				relay_cursor BIGINT(20) NOT NULL DEFAULT 0,
				started_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
				last_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY session_key (session_key),
				KEY last_at (last_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$messages} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				conversation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				role VARCHAR(20) NOT NULL DEFAULT 'visitor',
				content LONGTEXT NULL,
				created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
				PRIMARY KEY  (id),
				KEY conversation_id (conversation_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$learnings} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				conversation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				note TEXT NULL,
				enabled TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
				PRIMARY KEY  (id),
				KEY enabled (enabled)
			) {$charset};"
		);
	}

	/**
	 * Re-run the schema when the plugin was updated in place (activation does
	 * not fire on updates; dbDelta adds any new columns).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( AICBC_VERSION !== get_option( 'aicbc_db_version' ) ) {
			self::install();
			update_option( 'aicbc_db_version', AICBC_VERSION );
		}
	}

	/**
	 * Create a new conversation and return its row.
	 *
	 * @param string $page_url Page the chat started on.
	 * @return object|null
	 */
	public static function create_conversation( $page_url ) {
		global $wpdb;
		$key = wp_generate_password( 32, false, false ) . bin2hex( random_bytes( 16 ) );
		$key = substr( $key, 0, 64 );
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::conversations_table(),
			array(
				'session_key' => $key,
				'status'      => 'open',
				'page_url'    => substr( esc_url_raw( $page_url ), 0, 255 ),
				'started_at'  => $now,
				'last_at'     => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		return self::get_conversation( (int) $wpdb->insert_id );
	}

	/**
	 * Fetch a conversation by id.
	 *
	 * @param int $id Conversation id.
	 * @return object|null
	 */
	public static function get_conversation( $id ) {
		global $wpdb;
		$table = esc_sql( self::conversations_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Fetch a conversation by its session key.
	 *
	 * @param string $key Session key.
	 * @return object|null
	 */
	public static function get_by_key( $key ) {
		global $wpdb;
		$table = esc_sql( self::conversations_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_key = %s", $key ) );
	}

	/**
	 * Append a message to a conversation.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param string $role            visitor|assistant|tool|lead.
	 * @param string $content         Message text.
	 * @return void
	 */
	public static function add_message( $conversation_id, $role, $content ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::messages_table(),
			array(
				'conversation_id' => (int) $conversation_id,
				'role'            => $role,
				'content'         => (string) $content,
				'created_at'      => $now,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		$fields = array(
			'last_at'       => $now,
			'message_count' => self::count_messages( $conversation_id ),
		);
		if ( 'visitor' === $role ) {
			$conv = self::get_conversation( $conversation_id );
			if ( $conv && '' === $conv->preview ) {
				$fields['preview'] = mb_substr( wp_strip_all_tags( (string) $content ), 0, 190 );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( self::conversations_table(), $fields, array( 'id' => (int) $conversation_id ) );
	}

	/**
	 * Count messages in a conversation.
	 *
	 * @param int $conversation_id Conversation id.
	 * @return int
	 */
	public static function count_messages( $conversation_id ) {
		global $wpdb;
		$table = esc_sql( self::messages_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE conversation_id = %d", $conversation_id ) );
	}

	/**
	 * Fetch messages for a conversation, oldest first.
	 *
	 * @param int $conversation_id Conversation id.
	 * @param int $limit           Max rows (0 = all).
	 * @return array
	 */
	public static function get_messages( $conversation_id, $limit = 0 ) {
		global $wpdb;
		$table = esc_sql( self::messages_table() );
		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM (SELECT * FROM {$table} WHERE conversation_id = %d ORDER BY id DESC LIMIT %d) t ORDER BY id ASC", $conversation_id, $limit ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE conversation_id = %d ORDER BY id ASC", $conversation_id ) );
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Update conversation fields.
	 *
	 * @param int   $conversation_id Conversation id.
	 * @param array $fields          Column => value pairs.
	 * @return void
	 */
	public static function update_conversation( $conversation_id, $fields ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( self::conversations_table(), $fields, array( 'id' => (int) $conversation_id ) );
	}

	/**
	 * List conversations, newest activity first.
	 *
	 * @param int $limit  Rows per page.
	 * @param int $offset Offset.
	 * @return array
	 */
	public static function list_conversations( $limit = 20, $offset = 0 ) {
		global $wpdb;
		$table = esc_sql( self::conversations_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY last_at DESC LIMIT %d OFFSET %d", $limit, $offset ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Total conversation count.
	 *
	 * @return int
	 */
	public static function count_conversations() {
		global $wpdb;
		$table = esc_sql( self::conversations_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Delete a conversation and its messages.
	 *
	 * @param int $conversation_id Conversation id.
	 * @return void
	 */
	public static function delete_conversation( $conversation_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::messages_table(), array( 'conversation_id' => (int) $conversation_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::conversations_table(), array( 'id' => (int) $conversation_id ), array( '%d' ) );
	}

	/**
	 * Add a learning note.
	 *
	 * @param string $note            The learning.
	 * @param int    $conversation_id Optional source conversation.
	 * @return void
	 */
	public static function add_learning( $note, $conversation_id = 0 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::learnings_table(),
			array(
				'conversation_id' => (int) $conversation_id,
				'note'            => (string) $note,
				'enabled'         => 1,
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * All learnings, newest first.
	 *
	 * @return array
	 */
	public static function list_learnings() {
		global $wpdb;
		$table = esc_sql( self::learnings_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 500" );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Enabled learning notes for the system prompt.
	 *
	 * @return array Array of note strings.
	 */
	public static function active_learnings() {
		global $wpdb;
		$table = esc_sql( self::learnings_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( "SELECT note FROM {$table} WHERE enabled = 1 ORDER BY id ASC LIMIT 100" );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Toggle or delete a learning.
	 *
	 * @param int    $id     Learning id.
	 * @param string $action enable|disable|delete.
	 * @return void
	 */
	public static function learning_action( $id, $action ) {
		global $wpdb;
		if ( 'delete' === $action ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( self::learnings_table(), array( 'id' => (int) $id ), array( '%d' ) );
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( self::learnings_table(), array( 'enabled' => 'enable' === $action ? 1 : 0 ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
	}
}

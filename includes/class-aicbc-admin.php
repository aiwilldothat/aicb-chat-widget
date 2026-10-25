<?php
/**
 * Admin screens: conversation review and learnings.
 *
 * @package AiCB_Chat_Widget
 */

defined( 'ABSPATH' ) || exit;

/**
 * AiCB Chat admin menu: Conversations (review + learn), Learnings, Settings.
 */
class AICBC_Admin {

	/**
	 * Settings controller (renders the settings page).
	 *
	 * @var AICBC_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param AICBC_Settings $settings Settings controller.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Register the admin menu.
	 *
	 * @return void
	 */
	public function menu() {
		add_menu_page(
			__( 'AiCB Chat', 'aicb-chat-widget' ),
			__( 'AiCB Chat', 'aicb-chat-widget' ),
			'manage_options',
			'aicbc-conversations',
			array( $this, 'render_conversations' ),
			'dashicons-format-chat',
			58
		);
		add_submenu_page( 'aicbc-conversations', __( 'Conversations', 'aicb-chat-widget' ), __( 'Conversations', 'aicb-chat-widget' ), 'manage_options', 'aicbc-conversations', array( $this, 'render_conversations' ) );
		add_submenu_page( 'aicbc-conversations', __( 'Learnings', 'aicb-chat-widget' ), __( 'Learnings', 'aicb-chat-widget' ), 'manage_options', 'aicbc-learnings', array( $this, 'render_learnings' ) );
		add_submenu_page( 'aicbc-conversations', __( 'Settings', 'aicb-chat-widget' ), __( 'Settings', 'aicb-chat-widget' ), 'manage_options', 'aicbc-settings', array( $this->settings, 'render_page' ) );
	}

	/**
	 * Process posted admin actions (review, learn, delete, learning toggles).
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! isset( $_POST['aicbc_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'aicbc_admin' );

		$action  = sanitize_key( wp_unslash( $_POST['aicbc_action'] ) );
		$conv_id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;

		if ( 'mark_reviewed' === $action && $conv_id ) {
			AICBC_Store::update_conversation( $conv_id, array( 'reviewed' => 1 ) );
			$this->redirect( admin_url( 'admin.php?page=aicbc-conversations&conversation=' . $conv_id . '&aicbc_done=reviewed' ) );
		}

		if ( 'add_learning' === $action ) {
			$note = isset( $_POST['learning_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['learning_note'] ) ) : '';
			$note = trim( mb_substr( $note, 0, 1000 ) );
			if ( '' !== $note ) {
				AICBC_Store::add_learning( $note, $conv_id );
			}
			$to = $conv_id ? 'admin.php?page=aicbc-conversations&conversation=' . $conv_id . '&aicbc_done=learned' : 'admin.php?page=aicbc-learnings&aicbc_done=learned';
			$this->redirect( admin_url( $to ) );
		}

		if ( 'delete_conversation' === $action && $conv_id ) {
			AICBC_Store::delete_conversation( $conv_id );
			$this->redirect( admin_url( 'admin.php?page=aicbc-conversations&aicbc_done=deleted' ) );
		}

		if ( in_array( $action, array( 'learning_enable', 'learning_disable', 'learning_delete' ), true ) ) {
			$lid = isset( $_POST['learning_id'] ) ? absint( $_POST['learning_id'] ) : 0;
			if ( $lid ) {
				AICBC_Store::learning_action( $lid, str_replace( 'learning_', '', $action ) );
			}
			$this->redirect( admin_url( 'admin.php?page=aicbc-learnings' ) );
		}
	}

	/**
	 * Redirect helper.
	 *
	 * @param string $url Target URL.
	 * @return void
	 */
	private function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Success notice from the ?aicbc_done flag.
	 *
	 * @return void
	 */
	private function notice() {
		$done = isset( $_GET['aicbc_done'] ) ? sanitize_key( wp_unslash( $_GET['aicbc_done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $done ) {
			return;
		}
		$map = array(
			'reviewed' => __( 'Conversation marked as reviewed.', 'aicb-chat-widget' ),
			'learned'  => __( 'Learning saved — it will be applied to future chats.', 'aicb-chat-widget' ),
			'deleted'  => __( 'Conversation deleted.', 'aicb-chat-widget' ),
		);
		if ( isset( $map[ $done ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $done ] ) . '</p></div>';
		}
	}

	/**
	 * Conversations list or single-conversation view.
	 *
	 * @return void
	 */
	public function render_conversations() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$conv_id = isset( $_GET['conversation'] ) ? absint( $_GET['conversation'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $conv_id ) {
			$this->render_single( $conv_id );
			return;
		}

		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per   = 20;
		$rows  = AICBC_Store::list_conversations( $per, ( $paged - 1 ) * $per );
		$total = AICBC_Store::count_conversations();
		$pages = max( 1, (int) ceil( $total / $per ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Chat conversations', 'aicb-chat-widget' ); ?></h1>
			<?php $this->notice(); ?>
			<p class="description"><?php esc_html_e( 'Review chats, then capture what the assistant should learn — saved learnings are added to its instructions.', 'aicb-chat-widget' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Started', 'aicb-chat-widget' ); ?></th>
						<th><?php esc_html_e( 'First message', 'aicb-chat-widget' ); ?></th>
						<th><?php esc_html_e( 'Messages', 'aicb-chat-widget' ); ?></th>
						<th><?php esc_html_e( 'Lead', 'aicb-chat-widget' ); ?></th>
						<th><?php esc_html_e( 'Reviewed', 'aicb-chat-widget' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No conversations yet.', 'aicb-chat-widget' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $c ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $c->started_at, 'j M Y H:i' ) ); ?></td>
							<td><?php echo esc_html( '' !== $c->preview ? $c->preview : '—' ); ?></td>
							<td><?php echo esc_html( (string) $c->message_count ); ?></td>
							<td><?php echo esc_html( '' !== $c->lead_name ? $c->lead_name . ' (' . $c->lead_contact . ')' : '—' ); ?></td>
							<td><?php echo $c->reviewed ? '✅' : '—'; ?></td>
							<td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=aicbc-conversations&conversation=' . (int) $c->id ) ); ?>"><?php esc_html_e( 'Review', 'aicb-chat-widget' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			<?php if ( $pages > 1 ) : ?>
				<p>
				<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
					<?php if ( $i === $paged ) : ?>
						<strong><?php echo esc_html( (string) $i ); ?></strong>
					<?php else : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=aicbc-conversations&paged=' . $i ) ); ?>"><?php echo esc_html( (string) $i ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Single conversation transcript + review/learn actions.
	 *
	 * @param int $conv_id Conversation id.
	 * @return void
	 */
	private function render_single( $conv_id ) {
		$conv = AICBC_Store::get_conversation( $conv_id );
		if ( ! $conv ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Conversation not found.', 'aicb-chat-widget' ) . '</h1></div>';
			return;
		}
		$msgs   = AICBC_Store::get_messages( $conv_id );
		$labels = array(
			'visitor'   => __( 'Visitor', 'aicb-chat-widget' ),
			'assistant' => __( 'Assistant', 'aicb-chat-widget' ),
			'human'     => __( 'Team (live)', 'aicb-chat-widget' ),
			'tool'      => __( 'Tool', 'aicb-chat-widget' ),
			'lead'      => __( 'Lead', 'aicb-chat-widget' ),
		);
		?>
		<div class="wrap">
			<h1>
				<?php
				/* translators: %d: conversation id. */
				echo esc_html( sprintf( __( 'Conversation #%d', 'aicb-chat-widget' ), $conv_id ) );
				?>
				<?php if ( $conv->reviewed ) : ?>
					<span style="font-size:14px;color:#00a32a">✅ <?php esc_html_e( 'Reviewed', 'aicb-chat-widget' ); ?></span>
				<?php endif; ?>
			</h1>
			<?php $this->notice(); ?>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=aicbc-conversations' ) ); ?>">&larr; <?php esc_html_e( 'All conversations', 'aicb-chat-widget' ); ?></a>
				&nbsp;·&nbsp; <?php echo esc_html( get_date_from_gmt( $conv->started_at, 'j M Y H:i' ) ); ?>
				<?php if ( '' !== $conv->page_url ) : ?>
					&nbsp;·&nbsp; <?php echo esc_html( $conv->page_url ); ?>
				<?php endif; ?>
			</p>

			<div style="max-width:760px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;display:flex;flex-direction:column;gap:8px">
				<?php if ( empty( $msgs ) ) : ?>
					<em><?php esc_html_e( 'No stored messages.', 'aicb-chat-widget' ); ?></em>
				<?php endif; ?>
				<?php foreach ( $msgs as $m ) : ?>
					<?php
					$is_visitor = 'visitor' === $m->role;
					if ( $is_visitor ) {
						$style = 'align-self:flex-end;background:linear-gradient(135deg,#6E56F7,#19C8C0);color:#fff';
					} elseif ( 'assistant' === $m->role ) {
						$style = 'align-self:flex-start;background:#f6f7fb;border:1px solid #e6e8f0';
					} elseif ( 'human' === $m->role ) {
						$style = 'align-self:flex-start;background:#eef6ff;border:1px solid #9ec2e6';
					} else {
						$style = 'align-self:stretch;background:#fffbe5;border:1px dashed #dba617;font-size:12px';
					}
					?>
					<div style="max-width:85%;padding:8px 12px;border-radius:12px;white-space:pre-wrap;word-wrap:break-word;<?php echo esc_attr( $style ); ?>">
						<strong style="font-size:11px;opacity:.75"><?php echo esc_html( isset( $labels[ $m->role ] ) ? $labels[ $m->role ] : $m->role ); ?></strong><br/>
						<?php echo esc_html( (string) $m->content ); ?>
					</div>
				<?php endforeach; ?>
			</div>

			<div style="max-width:760px;margin-top:16px;display:flex;gap:24px;flex-wrap:wrap">
				<form method="post">
					<?php wp_nonce_field( 'aicbc_admin' ); ?>
					<input type="hidden" name="conversation_id" value="<?php echo esc_attr( (string) $conv_id ); ?>"/>
					<input type="hidden" name="aicbc_action" value="mark_reviewed"/>
					<?php submit_button( __( 'Mark reviewed', 'aicb-chat-widget' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this conversation permanently?', 'aicb-chat-widget' ) ); ?>');">
					<?php wp_nonce_field( 'aicbc_admin' ); ?>
					<input type="hidden" name="conversation_id" value="<?php echo esc_attr( (string) $conv_id ); ?>"/>
					<input type="hidden" name="aicbc_action" value="delete_conversation"/>
					<?php submit_button( __( 'Delete', 'aicb-chat-widget' ), 'delete', 'submit', false ); ?>
				</form>
			</div>

			<h2 style="margin-top:24px"><?php esc_html_e( 'Learn from this conversation', 'aicb-chat-widget' ); ?></h2>
			<form method="post" style="max-width:760px">
				<?php wp_nonce_field( 'aicbc_admin' ); ?>
				<input type="hidden" name="conversation_id" value="<?php echo esc_attr( (string) $conv_id ); ?>"/>
				<input type="hidden" name="aicbc_action" value="add_learning"/>
				<textarea name="learning_note" class="large-text" rows="3" placeholder="<?php esc_attr_e( 'e.g. When asked about bulk pricing, mention 10% off orders over $500 and link the wholesale page.', 'aicb-chat-widget' ); ?>"></textarea>
				<p class="description"><?php esc_html_e( 'Write what the assistant should do differently or know. It is added to the assistant’s instructions for all future chats.', 'aicb-chat-widget' ); ?></p>
				<?php submit_button( __( 'Save learning', 'aicb-chat-widget' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Learnings management screen.
	 *
	 * @return void
	 */
	public function render_learnings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rows = AICBC_Store::list_learnings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Chat learnings', 'aicb-chat-widget' ); ?></h1>
			<?php $this->notice(); ?>
			<p class="description"><?php esc_html_e( 'Enabled learnings are appended to the assistant’s instructions on every chat. Add them here or from a conversation review.', 'aicb-chat-widget' ); ?></p>

			<form method="post" style="max-width:760px;margin-bottom:18px">
				<?php wp_nonce_field( 'aicbc_admin' ); ?>
				<input type="hidden" name="aicbc_action" value="add_learning"/>
				<textarea name="learning_note" class="large-text" rows="2" placeholder="<?php esc_attr_e( 'Add a learning…', 'aicb-chat-widget' ); ?>"></textarea>
				<?php submit_button( __( 'Add learning', 'aicb-chat-widget' ), 'primary', 'submit', false ); ?>
			</form>

			<table class="widefat striped" style="max-width:900px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Learning', 'aicb-chat-widget' ); ?></th>
						<th><?php esc_html_e( 'Added', 'aicb-chat-widget' ); ?></th>
						<th><?php esc_html_e( 'Status', 'aicb-chat-widget' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No learnings yet — review a conversation to add one.', 'aicb-chat-widget' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $l ) : ?>
						<tr>
							<td style="white-space:pre-wrap"><?php echo esc_html( (string) $l->note ); ?>
								<?php if ( $l->conversation_id ) : ?>
									<br/><a style="font-size:11px" href="<?php echo esc_url( admin_url( 'admin.php?page=aicbc-conversations&conversation=' . (int) $l->conversation_id ) ); ?>"><?php esc_html_e( 'source conversation', 'aicb-chat-widget' ); ?></a>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( get_date_from_gmt( $l->created_at, 'j M Y' ) ); ?></td>
							<td><?php echo $l->enabled ? '<span style="color:#00a32a">' . esc_html__( 'Active', 'aicb-chat-widget' ) . '</span>' : esc_html__( 'Off', 'aicb-chat-widget' ); ?></td>
							<td>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'aicbc_admin' ); ?>
									<input type="hidden" name="learning_id" value="<?php echo esc_attr( (string) $l->id ); ?>"/>
									<input type="hidden" name="aicbc_action" value="<?php echo $l->enabled ? 'learning_disable' : 'learning_enable'; ?>"/>
									<button class="button button-small"><?php echo $l->enabled ? esc_html__( 'Disable', 'aicb-chat-widget' ) : esc_html__( 'Enable', 'aicb-chat-widget' ); ?></button>
								</form>
								<form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this learning?', 'aicb-chat-widget' ) ); ?>');">
									<?php wp_nonce_field( 'aicbc_admin' ); ?>
									<input type="hidden" name="learning_id" value="<?php echo esc_attr( (string) $l->id ); ?>"/>
									<input type="hidden" name="aicbc_action" value="learning_delete"/>
									<button class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'aicb-chat-widget' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

<?php
/**
 * Plugin Name:       AiCB Chat Widget
 * Plugin URI:        https://aicb.au/
 * Description:       AI chat widget powered by Claude. Answers from your instructions, checks WooCommerce order status, looks up products, stores conversations for review, emails escalations and leads to your team, and can optionally hand off to live @username chat on AiCB/Caimunicate.
 * Version:           1.3.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Demfilz
 * Author URI:        https://demfilz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aicb-chat-widget
 *
 * @package AiCB_Chat_Widget
 */

defined( 'ABSPATH' ) || exit;

define( 'AICBC_VERSION', '1.3.1' );
define( 'AICBC_FILE', __FILE__ );
define( 'AICBC_DIR', plugin_dir_path( __FILE__ ) );
define( 'AICBC_URL', plugin_dir_url( __FILE__ ) );
define( 'AICBC_OPTION', 'aicbc_options' );

require_once AICBC_DIR . 'includes/class-demfilz-license-client.php';
require_once AICBC_DIR . 'includes/class-aicbc-settings.php';
require_once AICBC_DIR . 'includes/class-aicbc-store.php';
require_once AICBC_DIR . 'includes/class-aicbc-tools.php';
require_once AICBC_DIR . 'includes/class-aicbc-ai.php';
require_once AICBC_DIR . 'includes/class-aicbc-rest.php';
require_once AICBC_DIR . 'includes/class-aicbc-admin.php';
require_once AICBC_DIR . 'includes/class-aicbc-plugin.php';

/**
 * Boot the plugin.
 *
 * @return AICBC_Plugin Main plugin controller.
 */
function aicbc() {
	static $plugin = null;
	if ( null === $plugin ) {
		$plugin = new AICBC_Plugin();
	}
	return $plugin;
}

add_action( 'plugins_loaded', 'aicbc' );

/**
 * The AiCB Chat Widget Pro licence client (shared Demfilz licensing service).
 *
 * @return Demfilz_License_Client
 */
function aicbc_license() {
	static $client = null;
	if ( null === $client ) {
		$client = new Demfilz_License_Client(
			array(
				'product'     => 'aicb-chat',
				'item_name'   => 'AiCB Chat Widget Pro',
				'option'      => 'aicbc_license',
				'text_domain' => 'aicb-chat-widget',
				'version'     => AICBC_VERSION,
			)
		);
	}
	return $client;
}

register_activation_hook( __FILE__, array( 'AICBC_Store', 'install' ) );

/**
 * Add a Settings link on the Plugins screen.
 *
 * @param array $links Existing action links.
 * @return array
 */
function aicbc_action_links( $links ) {
	$url = admin_url( 'admin.php?page=aicbc-settings' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'aicb-chat-widget' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'aicbc_action_links' );

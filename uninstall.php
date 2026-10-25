<?php
/**
 * Uninstall cleanup for AiCB Chat Widget.
 *
 * @package AiCB_Chat_Widget
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aicbc_messages" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aicbc_conversations" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aicbc_learnings" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_aicbc_rl_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_aicbc_rl_' ) . '%' ) );
// phpcs:enable

delete_option( 'aicbc_options' );
delete_option( 'aicbc_license' );

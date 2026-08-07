<?php
/**
 * Uninstall cleanup for Show Support.
 *
 * @package ShowSupport
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$show_support_settings = get_option( 'show_support_settings', [] );

if (
	! is_array( $show_support_settings ) ||
	! isset( $show_support_settings['delete_data_on_uninstall'] ) ||
	'yes' !== $show_support_settings['delete_data_on_uninstall']
) {
	return;
}

delete_option( 'show_support_clicks' );
delete_option( 'show_support_stats' );
delete_option( 'show_support_do_activation_redirect' );
delete_option( 'show_support_settings' );

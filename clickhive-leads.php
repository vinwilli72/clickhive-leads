<?php
/**
 * Plugin Name:       Click Hive Leads
 * Description:       Lead inbox for sites managed by Click Hive Marketing. Gravity Forms submissions on the forms you choose become leads that staff work from one list: stage, checkboxes, notes and export.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Click Hive Marketing
 * Author URI:        https://clickhivemarketing.com
 * License:           GPL-2.0-or-later
 * Text Domain:       clickhive-leads
 * Update URI:        https://github.com/vinwilli72/clickhive-leads
 */

defined( 'ABSPATH' ) || exit;

define( 'CHL_VERSION', '0.1.0' );
define( 'CHL_FILE', __FILE__ );
define( 'CHL_DIR', plugin_dir_path( __FILE__ ) );
define( 'CHL_URL', plugin_dir_url( __FILE__ ) );

require_once CHL_DIR . 'includes/settings.php';
require_once CHL_DIR . 'includes/lead.php';
require_once CHL_DIR . 'includes/intake.php';
require_once CHL_DIR . 'includes/source.php';
require_once CHL_DIR . 'includes/gravity-forms.php';
require_once CHL_DIR . 'includes/list.php';
require_once CHL_DIR . 'includes/export.php';
require_once CHL_DIR . 'includes/edit.php';

// Updates: each site checks the GitHub releases for vinwilli72/clickhive-leads and
// offers "Update now" under Plugins. Settings and leads live in the database and
// are untouched by updates.
require_once CHL_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
$chl_updates = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/vinwilli72/clickhive-leads/',
	__FILE__,
	'clickhive-leads'
);
$chl_updates->getVcsApi()->enableReleaseAssets( '/clickhive-leads\.zip$/' );

// Shortcuts to the list and settings on the Plugins screen.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( chl_list_url() ) . '">' . esc_html__( 'Leads', 'clickhive-leads' ) . '</a>',
		'<a href="' . esc_url( admin_url( 'options-general.php?page=chl-settings' ) ) . '">' . esc_html__( 'Settings', 'clickhive-leads' ) . '</a>'
	);
	return $links;
} );

register_activation_hook( __FILE__, 'chl_activate' );
register_deactivation_hook( __FILE__, 'chl_deactivate' );

/**
 * Abilities. Working leads covers viewing the list, opening a lead, changing the
 * stage and checkboxes, and adding notes. Export and delete are separate.
 */
const CHL_CAP_WORK   = 'chl_work_leads';
const CHL_CAP_EXPORT = 'chl_export_leads';
const CHL_CAP_DELETE = 'chl_delete_leads';

/** Lead Manager: front-desk staff who work leads and nothing else. */
function chl_manager_caps() {
	return array(
		'read'       => true,
		CHL_CAP_WORK => true,
	);
}

/** Abilities each role should have. Settings stay with manage_options. */
function chl_role_caps() {
	return array(
		'administrator' => array( CHL_CAP_WORK, CHL_CAP_EXPORT, CHL_CAP_DELETE ),
		'client'        => array( CHL_CAP_WORK, CHL_CAP_EXPORT ),
	);
}

function chl_activate() {
	chl_install_tables();
	remove_role( 'chl_lead_manager' );
	add_role( 'chl_lead_manager', __( 'Lead Manager', 'clickhive-leads' ), chl_manager_caps() );
	chl_grant_role_caps();

	if ( ! wp_next_scheduled( 'chl_daily_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'chl_daily_cleanup' );
	}
}

function chl_deactivate() {
	wp_clear_scheduled_hook( 'chl_daily_cleanup' );
}

/**
 * Gives administrators and Click Hive Utility's "client" role their abilities.
 * Checked on every admin load: updates don't re-run activation, and Click Hive
 * Utility rebuilds the client role from scratch when its settings change, which
 * drops abilities added by other plugins.
 */
function chl_grant_role_caps() {
	foreach ( chl_role_caps() as $role_name => $caps ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}
		foreach ( $caps as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
	}
	if ( ! get_role( 'chl_lead_manager' ) ) {
		add_role( 'chl_lead_manager', __( 'Lead Manager', 'clickhive-leads' ), chl_manager_caps() );
	}
}
add_action( 'admin_init', 'chl_grant_role_caps' );

// Lead Managers land on the lead list when they sign in.
add_filter( 'login_redirect', function ( $redirect, $requested, $user ) {
	if ( $user instanceof WP_User && in_array( 'chl_lead_manager', (array) $user->roles, true ) ) {
		$admin_home = array( '', admin_url(), admin_url( '/' ) );
		return in_array( (string) $requested, $admin_home, true ) ? chl_list_url() : $redirect;
	}
	return $redirect;
}, 10, 3 );

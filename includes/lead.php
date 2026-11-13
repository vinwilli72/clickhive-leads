<?php
/**
 * The lead record: a private post type with one post per person, plus a
 * history table that records every stage change, checkbox change,
 * submission and follow-up step. Reports are built from that history later.
 */

defined( 'ABSPATH' ) || exit;

const CHL_CPT        = 'ch_lead';
const CHL_DB_VERSION = '1';

add_action( 'init', function () {
	register_post_type(
		CHL_CPT,
		array(
			'labels'              => array(
				'name'               => __( 'Leads', 'clickhive-leads' ),
				'singular_name'      => __( 'Lead', 'clickhive-leads' ),
				'menu_name'          => __( 'Leads', 'clickhive-leads' ),
				'all_items'          => __( 'All Leads', 'clickhive-leads' ),
				'edit_item'          => __( 'Lead', 'clickhive-leads' ),
				'search_items'       => __( 'Search Leads', 'clickhive-leads' ),
				'not_found'          => __( 'No leads found', 'clickhive-leads' ),
				'not_found_in_trash' => __( 'No leads in the trash', 'clickhive-leads' ),
				'item_updated'       => __( 'Lead saved.', 'clickhive-leads' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => false,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'rewrite'             => false,
			'query_var'           => false,
			'menu_position'       => 4,
			'menu_icon'           => chl_menu_icon(),
			'supports'            => false, // the edit screen is built from meta boxes
			'map_meta_cap'        => true,
			'capabilities'        => array(
				'edit_posts'             => CHL_CAP_WORK,
				'edit_others_posts'      => CHL_CAP_WORK,
				'edit_published_posts'   => CHL_CAP_WORK,
				'edit_private_posts'     => CHL_CAP_WORK,
				'publish_posts'          => CHL_CAP_WORK,
				'read_private_posts'     => CHL_CAP_WORK,
				'read'                   => 'read',
				'delete_posts'           => CHL_CAP_DELETE,
				'delete_others_posts'    => CHL_CAP_DELETE,
				'delete_published_posts' => CHL_CAP_DELETE,
				'delete_private_posts'   => CHL_CAP_DELETE,
				'create_posts'           => 'do_not_allow', // leads come from forms
			),
		)
	);
} );

/**
 * The Click Hive mark as a one-colour menu icon. WordPress recolours it to
 * match the admin colour scheme, the same as its own icons.
 */
function chl_menu_icon() {
	$svg = file_get_contents( CHL_DIR . 'assets/menu-icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return $svg ? 'data:image/svg+xml;base64,' . base64_encode( $svg ) : 'dashicons-groups';
}

// ---------------------------------------------------------------------------
// History table
// ---------------------------------------------------------------------------

function chl_history_table() {
	global $wpdb;
	return $wpdb->prefix . 'chl_history';
}

function chl_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = chl_history_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) unsigned NOT NULL,
			event varchar(32) NOT NULL,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			value varchar(191) NOT NULL DEFAULT '',
			category varchar(20) NOT NULL DEFAULT '',
			detail longtext NULL,
			PRIMARY KEY  (id),
			KEY lead_id (lead_id),
			KEY event_time (event, created_at)
		) {$charset};"
	);
	update_option( 'chl_db_version', CHL_DB_VERSION, false );
}

// Updates don't re-run activation, and a form can be submitted before anyone
// opens the admin, so check the table on every load (one autoloaded option).
add_action( 'plugins_loaded', function () {
	if ( CHL_DB_VERSION !== get_option( 'chl_db_version' ) ) {
		chl_install_tables();
	}
} );

/**
 * Records one event on a lead.
 *
 * Events: created, submission, followup, stage, checkbox, note, edit.
 * For stage events, value is the new stage key and category its category
 * at the time, so later reports don't depend on today's settings.
 */
function chl_log( $lead_id, $event, $value = '', $category = '', $detail = array() ) {
	global $wpdb;
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		chl_history_table(),
		array(
			'lead_id'    => (int) $lead_id,
			'event'      => substr( (string) $event, 0, 32 ),
			'created_at' => current_time( 'mysql', true ),
			'user_id'    => get_current_user_id(),
			'value'      => substr( (string) $value, 0, 191 ),
			'category'   => substr( (string) $category, 0, 20 ),
			'detail'     => $detail ? wp_json_encode( $detail ) : null,
		),
		array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
	);
}

/** A lead's history, newest first. */
function chl_history( $lead_id ) {
	global $wpdb;
	$table = chl_history_table();
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE lead_id = %d ORDER BY id DESC", $lead_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	foreach ( $rows as &$row ) {
		$row['detail'] = $row['detail'] ? (array) json_decode( $row['detail'], true ) : array();
	}
	return $rows;
}

// History goes with the lead when it is deleted for good.
add_action( 'before_delete_post', function ( $post_id ) {
	if ( CHL_CPT === get_post_type( $post_id ) ) {
		global $wpdb;
		$wpdb->delete( chl_history_table(), array( 'lead_id' => (int) $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
} );

// ---------------------------------------------------------------------------
// Fields
// ---------------------------------------------------------------------------

function chl_get( $lead_id, $name ) {
	return get_post_meta( $lead_id, '_chl_' . $name, true );
}

function chl_set( $lead_id, $name, $value ) {
	update_post_meta( $lead_id, '_chl_' . $name, $value );
}

/**
 * Normalizes a US number to +1XXXXXXXXXX, the same format the phone system
 * stores. Returns an empty string when the number can't be normalized.
 */
function chl_normalize_phone( $raw ) {
	$digits = preg_replace( '/\D+/', '', (string) $raw );
	if ( 10 === strlen( $digits ) ) {
		$digits = '1' . $digits;
	}
	return ( 11 === strlen( $digits ) && '1' === $digits[0] ) ? '+' . $digits : '';
}

function chl_format_phone( $phone ) {
	if ( preg_match( '/^\+1(\d{3})(\d{3})(\d{4})$/', (string) $phone, $m ) ) {
		return "({$m[1]}) {$m[2]}-{$m[3]}";
	}
	return (string) $phone;
}

function chl_lead_name( $lead_id ) {
	$name = trim( chl_get( $lead_id, 'first_name' ) . ' ' . chl_get( $lead_id, 'last_name' ) );
	return '' !== $name ? $name : (string) chl_get( $lead_id, 'email' );
}

/** Finds a lead by one stored field (email, phone or token). */
function chl_find_by( $name, $value ) {
	if ( '' === (string) $value ) {
		return 0;
	}
	$ids = get_posts(
		array(
			'post_type'      => CHL_CPT,
			'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
			'meta_key'       => '_chl_' . $name, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => $value, // phpcs:ignore WordPress.DB.SlowDBQuery
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/** One lead per person: match on email first, then phone. */
function chl_find_person( $email, $phone ) {
	$id = $email ? chl_find_by( 'email', $email ) : 0;
	if ( ! $id && $phone ) {
		$id = chl_find_by( 'phone', $phone );
	}
	return $id;
}

/** Copied form fields: key => array( label, value, form_id, entry_id, at ). */
function chl_fields( $lead_id ) {
	$f = chl_get( $lead_id, 'fields' );
	return is_array( $f ) ? $f : array();
}

/** Stores copied form fields on a lead. The newest submission wins. */
function chl_merge_fields( $lead_id, array $fields, $form_id, $entry_id ) {
	if ( ! $fields ) {
		return;
	}
	$stored = chl_fields( $lead_id );
	$known  = get_option( 'chl_known_fields', array() );
	$known  = is_array( $known ) ? $known : array();
	foreach ( $fields as $key => $f ) {
		$stored[ $key ] = array(
			'label'    => $f['label'],
			'value'    => $f['value'],
			'form_id'  => $form_id,
			'entry_id' => $entry_id,
			'at'       => time(),
		);
		$known[ $key ]  = $f['label'];
	}
	chl_set( $lead_id, 'fields', $stored );
	update_option( 'chl_known_fields', $known, false );
}

// ---------------------------------------------------------------------------
// Stage, checkboxes and notes. Every change is recorded in the history.
// ---------------------------------------------------------------------------

function chl_set_stage( $lead_id, $stage, $reason = '' ) {
	$old = (string) chl_get( $lead_id, 'stage' );
	if ( $old === $stage ) {
		return false;
	}
	chl_set( $lead_id, 'stage', $stage );
	$detail = array(
		'from'       => $old,
		'from_label' => chl_stage_label( $old ),
		'label'      => chl_stage_label( $stage ),
	);
	if ( $reason ) {
		$detail['reason'] = $reason;
	}
	chl_log( $lead_id, 'stage', $stage, chl_stage_category( $stage ), $detail );
	return true;
}

function chl_is_checked( $lead_id, $key ) {
	return '1' === (string) get_post_meta( $lead_id, '_chl_cb_' . $key, true );
}

function chl_set_checkbox( $lead_id, $key, $on, $reason = '' ) {
	$on = (bool) $on;
	if ( chl_is_checked( $lead_id, $key ) === $on ) {
		return false;
	}
	update_post_meta( $lead_id, '_chl_cb_' . $key, $on ? '1' : '0' );
	$boxes  = chl_checkboxes();
	$detail = array( 'label' => isset( $boxes[ $key ] ) ? $boxes[ $key ]['label'] : $key );
	if ( $reason ) {
		$detail['reason'] = $reason;
	}
	chl_log( $lead_id, 'checkbox', $key, $on ? 'on' : 'off', $detail );
	return true;
}

/** Notes, oldest first. Each: text, user, name, at. */
function chl_notes( $lead_id ) {
	$notes = chl_get( $lead_id, 'notes' );
	return is_array( $notes ) ? $notes : array();
}

function chl_add_note( $lead_id, $text ) {
	$text = trim( sanitize_textarea_field( $text ) );
	if ( '' === $text ) {
		return null;
	}
	$user  = wp_get_current_user();
	$note  = array(
		'text' => $text,
		'user' => (int) $user->ID,
		'name' => $user->ID ? $user->display_name : __( 'System', 'clickhive-leads' ),
		'at'   => time(),
	);
	$notes   = chl_notes( $lead_id );
	$notes[] = $note;
	chl_set( $lead_id, 'notes', $notes );
	// Plain copy of every note for search.
	chl_set( $lead_id, 'notes_text', implode( "\n", wp_list_pluck( $notes, 'text' ) ) );
	chl_log( $lead_id, 'note', '', '', array( 'length' => strlen( $text ) ) );
	return $note;
}

function chl_latest_note( $lead_id ) {
	$notes = chl_notes( $lead_id );
	return $notes ? end( $notes ) : null;
}

function chl_format_time( $timestamp, $format = 'M j, Y g:i a' ) {
	return $timestamp ? wp_date( $format, (int) $timestamp ) : '';
}

// ---------------------------------------------------------------------------
// Retention
// ---------------------------------------------------------------------------

add_action( 'chl_daily_cleanup', function () {
	$days = (int) chl_setting( 'retention_days' );
	if ( $days < 30 ) {
		return; // 0 means keep forever
	}
	$before = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	$ids    = get_posts(
		array(
			'post_type'      => CHL_CPT,
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'date_query'     => array( array( 'column' => 'post_date_gmt', 'before' => $before ) ),
		)
	);
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
} );

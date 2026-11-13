<?php
/**
 * Gravity Forms adapter. Reads entries from forms the team switched on and
 * hands plain values to chl_receive(). Also handles the two-step redirect,
 * the follow-up form's private link, and the {ch_lead_link} and
 * {ch_leads_url} merge tags.
 */

defined( 'ABSPATH' ) || exit;

const CHL_GF_SLUG     = 'clickhive-leads';
const CHL_TOKEN_PARAM = 'lead';
const CHL_TOKEN_FIELD = 'chl_token';

add_action( 'gform_loaded', function () {
	if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
		return;
	}
	GFForms::include_addon_framework();
	require_once CHL_DIR . 'includes/class-chl-gf-addon.php';
	GFAddOn::register( 'CHL_GF_AddOn' );
}, 5 );

/** This plugin's settings for a form. */
function chl_gf_settings( $form ) {
	$s = is_array( $form ) ? rgar( $form, CHL_GF_SLUG ) : array();
	return is_array( $s ) ? $s : array();
}

function chl_gf_enabled( $form ) {
	return '1' === (string) rgar( chl_gf_settings( $form ), 'enabled' );
}

function chl_gf_role( $form ) {
	return 'followup' === rgar( chl_gf_settings( $form ), 'role' ) ? 'followup' : 'create';
}

/**
 * A field's label as visitors see it. Gravity Forms swaps in the Admin Field
 * Label on its own screens, so read the property directly: the key made from
 * it must be the same everywhere.
 */
function chl_gf_label( $field ) {
	return (string) $field->label;
}

/** The Admin Field Label when one is set, used as the short list heading. */
function chl_gf_short_label( $field ) {
	$admin = trim( (string) $field->adminLabel );
	return '' !== $admin ? $admin : chl_gf_label( $field );
}

/** Fields checked under "Other fields to copy", as key => GF_Field. */
function chl_gf_copy_fields( $form ) {
	$s    = chl_gf_settings( $form );
	$out  = array();
	$seen = array();
	foreach ( (array) rgar( $form, 'fields' ) as $field ) {
		if ( '1' !== (string) rgar( $s, 'copy_' . $field->id ) || in_array( $field->type, CHL_GF_AddOn::skip_types(), true ) ) {
			continue;
		}
		$key = sanitize_title( chl_gf_label( $field ) );
		$key = $key ? $key : 'field-' . $field->id;
		if ( isset( $seen[ $key ] ) ) {
			$key .= '-' . $field->id;
		}
		$seen[ $key ] = true;
		$out[ $key ]  = $field;
	}
	return $out;
}

/** The token from the follow-up form's hidden input, or from the link. */
function chl_gf_request_token() {
	// phpcs:disable WordPress.Security.NonceVerification -- public form, the token is the credential
	$token = isset( $_POST[ CHL_TOKEN_FIELD ] ) ? wp_unslash( $_POST[ CHL_TOKEN_FIELD ] ) : '';
	if ( '' === $token && isset( $_GET[ CHL_TOKEN_PARAM ] ) ) {
		$token = wp_unslash( $_GET[ CHL_TOKEN_PARAM ] );
	}
	// phpcs:enable
	return preg_replace( '/[^A-Za-z0-9]/', '', is_string( $token ) ? $token : '' );
}

function chl_gf_entry_url( $form_id, $entry_id ) {
	return admin_url( 'admin.php?page=gf_entries&view=entry&id=' . (int) $form_id . '&lid=' . (int) $entry_id );
}

/**
 * Builds the plain submission for chl_receive() from a Gravity Forms entry.
 */
function chl_gf_submission( $entry, $form ) {
	$s     = chl_gf_settings( $form );
	$addon = CHL_GF_AddOn::get_instance();
	$value = function ( $setting ) use ( $s, $addon, $form, $entry ) {
		$id = (string) rgar( $s, 'map_' . $setting );
		return '' === $id ? '' : trim( (string) $addon->get_field_value( $form, $entry, $id ) );
	};

	$first = $value( 'first_name' );
	$last  = $value( 'last_name' );
	$name  = (string) rgar( $s, 'map_name' );
	if ( '' === $first && '' === $last && '' !== $name ) {
		$field = GFFormsModel::get_field( $form, $name );
		if ( $field && 'name' === $field->get_input_type() && (string) (int) $name === $name ) {
			$first = trim( (string) rgar( $entry, $name . '.3' ) );
			$last  = trim( (string) rgar( $entry, $name . '.6' ) );
		} else {
			$parts = preg_split( '/\s+/', $value( 'name' ), 2 );
			$first = (string) $parts[0];
			$last  = isset( $parts[1] ) ? $parts[1] : '';
		}
	}

	$fields = array();
	foreach ( chl_gf_copy_fields( $form ) as $key => $field ) {
		$v = $addon->get_field_value( $form, $entry, (string) $field->id );
		$fields[ $key ] = array(
			'label' => chl_gf_label( $field ),
			'value' => is_array( $v ) ? implode( ', ', $v ) : (string) $v,
		);
	}

	return array(
		'adapter'    => 'gravityforms',
		'role'       => chl_gf_role( $form ),
		'form_id'    => (int) $form['id'],
		'form_title' => (string) rgar( $form, 'title' ),
		'entry_id'   => (int) $entry['id'],
		'entry_url'  => chl_gf_entry_url( $form['id'], $entry['id'] ),
		'first_name' => $first,
		'last_name'  => $last,
		'email'      => $value( 'email' ),
		'phone'      => $value( 'phone' ),
		'fields'     => $fields,
		'source'     => chl_current_source(),
		'token'      => chl_gf_request_token(),
		'want_token' => 'create' === chl_gf_role( $form ) && (int) rgar( $s, 'followup_page' ) > 0,
	);
}

// Create or update the lead as soon as the entry is saved. This runs before
// notifications are sent, so {ch_lead_link} can point at the lead.
add_action( 'gform_entry_created', function ( $entry, $form ) {
	if ( ! chl_gf_enabled( $form ) || 'spam' === rgar( $entry, 'status' ) ) {
		return;
	}
	$result = chl_receive( chl_gf_submission( $entry, $form ) );
	if ( is_wp_error( $result ) ) {
		GFCommon::log_error( 'Click Hive Leads: ' . $result->get_error_message() );
		return;
	}
	gform_update_meta( $entry['id'], 'chl_lead_id', $result['lead_id'], $form['id'] );
	if ( $result['token'] ) {
		gform_update_meta( $entry['id'], 'chl_token', $result['token'], $form['id'] );
	}
}, 10, 2 );

// Step one of a two-step form: send the visitor to the follow-up page with the
// private token in the link.
add_filter( 'gform_confirmation', function ( $confirmation, $form, $entry ) {
	if ( ! chl_gf_enabled( $form ) || 'create' !== chl_gf_role( $form ) ) {
		return $confirmation;
	}
	$page  = (int) rgar( chl_gf_settings( $form ), 'followup_page' );
	$token = $page ? gform_get_meta( $entry['id'], 'chl_token' ) : '';
	$url   = $page ? get_permalink( $page ) : '';
	if ( ! $token || ! $url ) {
		return $confirmation; // the form's own confirmation
	}
	return array( 'redirect' => add_query_arg( CHL_TOKEN_PARAM, $token, $url ) );
}, 10, 3 );

// ---------------------------------------------------------------------------
// Follow-up step
// ---------------------------------------------------------------------------

function chl_gf_is_followup( $form ) {
	return chl_gf_enabled( $form ) && 'followup' === chl_gf_role( $form );
}

function chl_gf_start_url( $form ) {
	$page = (int) rgar( chl_gf_settings( $form ), 'start_page' );
	$url  = $page ? get_permalink( $page ) : '';
	return $url ? $url : home_url( '/' );
}

// Carry the token inside the form so it survives AJAX submission and
// validation errors. No hidden field is needed in the form itself.
add_filter( 'gform_form_tag', function ( $tag, $form ) {
	if ( ! chl_gf_is_followup( $form ) ) {
		return $tag;
	}
	$token = chl_gf_request_token();
	return $tag . sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( CHL_TOKEN_FIELD ), esc_attr( $token ) );
}, 10, 2 );

// Without a valid link the follow-up form has no lead to add to, so visitors
// see a short message pointing to the first step. Signed-in editors still see
// the form so they can work on the page.
add_filter( 'gform_get_form_filter', function ( $markup, $form ) {
	if ( ! chl_gf_is_followup( $form ) || chl_lead_from_token( chl_gf_request_token() ) ) {
		return $markup;
	}
	if ( current_user_can( 'gravityforms_edit_forms' ) || current_user_can( 'edit_pages' ) ) {
		return '<div class="chl-followup-preview"><p><strong>' . esc_html__( 'Editor preview:', 'clickhive-leads' ) . '</strong> ' . esc_html__( 'visitors without the private link from the first step see a message instead of this form.', 'clickhive-leads' ) . '</p></div>' . $markup;
	}
	return '<div class="chl-followup-missing"><p>' . sprintf(
		/* translators: %s: link to the first step */
		esc_html__( 'This form continues an earlier step. Please start %s and you\'ll be brought back here.', 'clickhive-leads' ),
		'<a href="' . esc_url( chl_gf_start_url( $form ) ) . '">' . esc_html__( 'on this page', 'clickhive-leads' ) . '</a>'
	) . '</p></div>';
}, 10, 2 );

add_filter( 'gform_validation', function ( $result ) {
	$form = $result['form'];
	if ( ! chl_gf_is_followup( $form ) || chl_lead_from_token( chl_gf_request_token() ) ) {
		return $result;
	}
	$message = sprintf(
		/* translators: %s: link to the first step */
		__( 'We couldn\'t match this to your earlier details. Please <a href="%s">start again here</a>.', 'clickhive-leads' ),
		esc_url( chl_gf_start_url( $form ) )
	);
	foreach ( $form['fields'] as $field ) {
		if ( ! in_array( $field->type, CHL_GF_AddOn::skip_types(), true ) && 'hidden' !== $field->type && ! $field->is_administrative() ) {
			$field->failed_validation  = true;
			$field->validation_message = $message;
			break;
		}
	}
	$result['is_valid'] = false;
	$result['form']     = $form;
	return $result;
} );

// ---------------------------------------------------------------------------
// Merge tags
// ---------------------------------------------------------------------------

add_filter( 'gform_custom_merge_tags', function ( $tags, $form_id ) {
	$form = $form_id && class_exists( 'GFAPI' ) ? GFAPI::get_form( $form_id ) : null;
	if ( $form && chl_gf_enabled( $form ) ) {
		$tags[] = array(
			'label' => __( 'Lead link', 'clickhive-leads' ),
			'tag'   => '{ch_lead_link}',
		);
	}
	$tags[] = array(
		'label' => __( 'Leads list link', 'clickhive-leads' ),
		'tag'   => '{ch_leads_url}',
	);
	return $tags;
}, 10, 2 );

add_filter( 'gform_replace_merge_tags', function ( $text, $form, $entry ) {
	if ( ! is_string( $text ) || false === strpos( $text, '{ch_lead' ) ) {
		return $text;
	}
	if ( false !== strpos( $text, '{ch_lead_link}' ) ) {
		$lead_id = is_array( $entry ) && ! empty( $entry['id'] ) ? (int) gform_get_meta( $entry['id'], 'chl_lead_id' ) : 0;
		$text    = str_replace( '{ch_lead_link}', $lead_id ? esc_url_raw( chl_lead_url( $lead_id ) ) : '', $text );
	}
	return str_replace( '{ch_leads_url}', esc_url_raw( chl_list_url() ), $text );
}, 10, 3 );

// ---------------------------------------------------------------------------
// Status panel on the settings page
// ---------------------------------------------------------------------------

add_filter( 'chl_connected_forms', function ( $forms ) {
	if ( ! class_exists( 'GFAPI' ) ) {
		return $forms;
	}
	foreach ( GFAPI::get_forms() as $form ) {
		if ( ! chl_gf_enabled( $form ) ) {
			continue;
		}
		$fields = array();
		$short  = array();
		foreach ( chl_gf_copy_fields( $form ) as $key => $field ) {
			$fields[ $key ] = chl_gf_label( $field );
			$short[ $key ]  = chl_gf_short_label( $field );
		}
		$forms[] = array(
			'id'       => (int) $form['id'],
			'title'    => (string) $form['title'],
			'role'     => chl_gf_role( $form ),
			'edit_url' => admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=' . CHL_GF_SLUG . '&id=' . (int) $form['id'] ),
			'fields'   => $fields,
			'short'    => $short,
		);
	}
	return $forms;
} );

/** Form titles by ID, for the list filter and the lead screen. */
function chl_form_titles() {
	$out = array();
	if ( class_exists( 'GFAPI' ) ) {
		foreach ( GFAPI::get_forms( null ) as $form ) {
			$out[ (int) $form['id'] ] = (string) $form['title'];
		}
	}
	return $out;
}

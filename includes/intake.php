<?php
/**
 * Intake: turns one form submission into a lead. Knows nothing about any form
 * plugin. Each form plugin has a small adapter (includes/gravity-forms.php)
 * that reads its own entry and calls chl_receive() with plain values, so a
 * Cornerstone Forms adapter can be added later without touching the lead
 * record, the list or the history.
 */

defined( 'ABSPATH' ) || exit;

/** How long a follow-up link keeps working after the first step. */
const CHL_TOKEN_TTL = 30 * DAY_IN_SECONDS;

/**
 * Receives one submission.
 *
 * @param array $s {
 *     @type string $adapter    Form plugin, for example "gravityforms".
 *     @type string $role       "create" (creates or updates a lead) or "followup".
 *     @type int    $form_id
 *     @type string $form_title
 *     @type int    $entry_id
 *     @type string $entry_url  Admin link to the form entry.
 *     @type string $first_name
 *     @type string $last_name
 *     @type string $email
 *     @type string $phone      As typed. Stored as +1XXXXXXXXXX when possible.
 *     @type array  $fields     Copied fields: key => array( 'label' => ..., 'value' => ... ).
 *     @type array  $source     From chl_current_source(), or empty.
 *     @type string $token      Follow-up steps only: the token from step one.
 *     @type bool   $want_token Creating forms with a follow-up page: issue a token.
 * }
 * @return array|WP_Error array( lead_id, token, created ).
 */
function chl_receive( array $s ) {
	$s = wp_parse_args(
		$s,
		array(
			'adapter'    => '',
			'role'       => 'create',
			'form_id'    => 0,
			'form_title' => '',
			'entry_id'   => 0,
			'entry_url'  => '',
			'first_name' => '',
			'last_name'  => '',
			'email'      => '',
			'phone'      => '',
			'fields'     => array(),
			'source'     => array(),
			'token'      => '',
			'want_token' => false,
		)
	);

	$core = array(
		'first_name' => sanitize_text_field( $s['first_name'] ),
		'last_name'  => sanitize_text_field( $s['last_name'] ),
		'email'      => strtolower( sanitize_email( $s['email'] ) ),
		'phone'      => sanitize_text_field( $s['phone'] ),
	);
	$normalized = chl_normalize_phone( $core['phone'] );
	if ( $normalized ) {
		$core['phone'] = $normalized;
	}

	$fields = array();
	foreach ( (array) $s['fields'] as $key => $f ) {
		$key = sanitize_key( $key );
		if ( $key && isset( $f['label'], $f['value'] ) && '' !== trim( (string) $f['value'] ) ) {
			$fields[ $key ] = array(
				'label' => sanitize_text_field( $f['label'] ),
				'value' => sanitize_textarea_field( $f['value'] ),
			);
		}
	}

	$submission = array(
		'adapter'    => sanitize_key( $s['adapter'] ),
		'form_id'    => (int) $s['form_id'],
		'form_title' => sanitize_text_field( $s['form_title'] ),
		'entry_id'   => (int) $s['entry_id'],
		'entry_url'  => esc_url_raw( $s['entry_url'] ),
	);

	if ( 'followup' === $s['role'] ) {
		$lead_id = chl_lead_from_token( $s['token'] );
		if ( ! $lead_id ) {
			return new WP_Error( 'chl_no_lead', 'The follow-up link is missing or expired.' );
		}
		chl_update_core( $lead_id, $core );
		chl_merge_fields( $lead_id, $fields, $submission['form_id'], $submission['entry_id'] );
		chl_add_form( $lead_id, $submission['form_id'] );
		chl_log( $lead_id, 'followup', (string) $submission['form_id'], '', $submission + array( 'fields' => array_keys( $fields ) ) );
		return array(
			'lead_id' => $lead_id,
			'token'   => '',
			'created' => false,
		);
	}

	$source  = chl_clean_source( $s['source'] );
	$lead_id = chl_find_person( $core['email'], $normalized );
	$created = ! $lead_id;

	if ( $created ) {
		$lead_id = wp_insert_post(
			array(
				'post_type'   => CHL_CPT,
				'post_status' => 'publish',
				'post_title'  => chl_title_from( $core ),
			),
			true
		);
		if ( is_wp_error( $lead_id ) ) {
			return $lead_id;
		}
		chl_set( $lead_id, 'first_received', time() );
		chl_set( $lead_id, 'submissions', 1 );
		chl_set( $lead_id, 'source', $source ? chl_source_label( $source ) : '' );
		chl_set( $lead_id, 'source_data', $source );
		chl_log( $lead_id, 'created', (string) $submission['form_id'], '', $submission );
		chl_set_stage( $lead_id, chl_default_stage() );
	} else {
		chl_handle_returning( $lead_id, $core );
	}

	chl_update_core( $lead_id, $core );
	chl_merge_fields( $lead_id, $fields, $submission['form_id'], $submission['entry_id'] );
	chl_add_form( $lead_id, $submission['form_id'] );
	if ( ! $created && $source && ! chl_get( $lead_id, 'source_data' ) ) {
		chl_set( $lead_id, 'source', chl_source_label( $source ) );
		chl_set( $lead_id, 'source_data', $source );
	}
	chl_log(
		$lead_id,
		'submission',
		(string) $submission['form_id'],
		'',
		$submission + array(
			'number' => (int) chl_get( $lead_id, 'submissions' ),
			'source' => $source ? chl_source_label( $source ) : '',
		)
	);

	$token = '';
	if ( $s['want_token'] ) {
		$token = wp_generate_password( 32, false );
		chl_set( $lead_id, 'token', $token );
		chl_set( $lead_id, 'token_at', time() );
	}

	return array(
		'lead_id' => (int) $lead_id,
		'token'   => $token,
		'created' => $created,
	);
}

/**
 * Someone with an existing lead sent a form again (VEMMA policy).
 *
 * The lead moves to the top of the list, the submission is counted, checkboxes
 * marked "Clear" in settings are unchecked so the "not checked" filter catches
 * the new inquiry, and a lead in a Lost stage goes back to the first Open
 * stage. Won leads stay won.
 */
function chl_handle_returning( $lead_id, array $core ) {
	$count = max( 1, (int) chl_get( $lead_id, 'submissions' ) ) + 1;
	chl_set( $lead_id, 'submissions', $count );
	if ( ! chl_get( $lead_id, 'first_received' ) ) {
		chl_set( $lead_id, 'first_received', get_post_time( 'U', true, $lead_id ) );
	}

	$now    = time();
	$update = array(
		'ID'            => $lead_id,
		'post_date'     => wp_date( 'Y-m-d H:i:s', $now ),
		'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $now ),
		'edit_date'     => true,
	);
	$title  = chl_title_from( $core );
	if ( '' !== $title && ( '' !== $core['first_name'] || '' !== $core['last_name'] ) ) {
		$update['post_title'] = $title;
	}
	wp_update_post( $update );

	$stage = (string) chl_get( $lead_id, 'stage' );
	if ( in_array( $stage, chl_stages_in( 'lost' ), true ) ) {
		chl_set_stage( $lead_id, chl_default_stage(), 'returned' );
	}

	foreach ( chl_checkboxes() as $key => $box ) {
		if ( $box['clears'] ) {
			chl_set_checkbox( $lead_id, $key, false, 'returned' );
		}
	}
}

/** Core fields: a new value replaces the old one, a blank never erases it. */
function chl_update_core( $lead_id, array $core ) {
	$changed = false;
	foreach ( $core as $name => $value ) {
		if ( '' !== $value && chl_get( $lead_id, $name ) !== $value ) {
			chl_set( $lead_id, $name, $value );
			$changed = true;
		}
	}
	if ( $changed ) {
		$title = chl_title_from(
			array(
				'first_name' => chl_get( $lead_id, 'first_name' ),
				'last_name'  => chl_get( $lead_id, 'last_name' ),
				'email'      => chl_get( $lead_id, 'email' ),
				'phone'      => chl_get( $lead_id, 'phone' ),
			)
		);
		if ( get_the_title( $lead_id ) !== $title ) {
			wp_update_post(
				array(
					'ID'         => $lead_id,
					'post_title' => $title,
				)
			);
		}
	}
}

function chl_title_from( array $core ) {
	$name = trim( $core['first_name'] . ' ' . $core['last_name'] );
	if ( '' !== $name ) {
		return $name;
	}
	if ( '' !== $core['email'] ) {
		return $core['email'];
	}
	return '' !== $core['phone'] ? chl_format_phone( $core['phone'] ) : __( '(no name)', 'clickhive-leads' );
}

/** Remembers each form that sent this lead, for the form filter. */
function chl_add_form( $lead_id, $form_id ) {
	$form_id = (int) $form_id;
	if ( $form_id && ! in_array( (string) $form_id, get_post_meta( $lead_id, '_chl_form' ), true ) ) {
		add_post_meta( $lead_id, '_chl_form', (string) $form_id );
	}
}

/** The lead a follow-up token belongs to, or 0 when missing or expired. */
function chl_lead_from_token( $token ) {
	$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
	if ( 32 !== strlen( $token ) ) {
		return 0;
	}
	$id = chl_find_by( 'token', $token );
	if ( ! $id || (int) chl_get( $id, 'token_at' ) < time() - CHL_TOKEN_TTL ) {
		return 0;
	}
	return $id;
}

/** Admin link to a lead. Built by hand so it works while nobody is signed in. */
function chl_lead_url( $lead_id ) {
	return admin_url( 'post.php?post=' . (int) $lead_id . '&action=edit' );
}

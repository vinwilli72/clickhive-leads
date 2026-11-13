<?php
/**
 * CSV export of whatever the list is filtered to, including copied form
 * fields and source.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_chl_export', 'chl_export_csv' );

function chl_export_csv() {
	if ( ! current_user_can( CHL_CAP_EXPORT ) ) {
		wp_die( esc_html__( 'You do not have permission to export leads.', 'clickhive-leads' ), 403 );
	}
	check_admin_referer( 'chl_export' );

	$args = array_merge(
		array(
			'post_type'      => CHL_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		),
		chl_query_args( chl_filters_from( $_GET ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above
	);
	if ( ! empty( $args['chl_search'] ) ) {
		unset( $args['chl_search'] );
	}
	$ids = get_posts( $args );

	$boxes  = chl_checkboxes();
	$titles = chl_form_titles();
	$source = chl_source_labels();

	// Copied fields: every label used by these leads, in the order first seen.
	$field_cols = array();
	foreach ( $ids as $id ) {
		foreach ( chl_fields( $id ) as $key => $f ) {
			if ( ! isset( $field_cols[ $key ] ) ) {
				$field_cols[ $key ] = $f['label'];
			}
		}
	}

	$head = array(
		__( 'Received', 'clickhive-leads' ),
		__( 'First received', 'clickhive-leads' ),
		__( 'Submissions', 'clickhive-leads' ),
		__( 'First name', 'clickhive-leads' ),
		__( 'Last name', 'clickhive-leads' ),
		__( 'Email', 'clickhive-leads' ),
		__( 'Phone', 'clickhive-leads' ),
		__( 'Stage', 'clickhive-leads' ),
		__( 'Stage category', 'clickhive-leads' ),
	);
	foreach ( $boxes as $box ) {
		$head[] = $box['label'];
	}
	$head[] = __( 'Notes', 'clickhive-leads' );
	$head[] = __( 'Forms', 'clickhive-leads' );
	$head[] = __( 'Source', 'clickhive-leads' );
	foreach ( $source as $label ) {
		$head[] = $label;
	}
	foreach ( $field_cols as $label ) {
		$head[] = $label;
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="leads-' . wp_date( 'Y-m-d' ) . '.csv"' );

	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- so Excel reads UTF-8
	fputcsv( $out, array_map( 'chl_csv_cell', $head ), ',', '"', '\\' );

	$categories = chl_categories();
	foreach ( $ids as $id ) {
		$stage = (string) chl_get( $id, 'stage' );
		$row   = array(
			get_the_date( 'Y-m-d g:i a', $id ),
			chl_format_time( chl_get( $id, 'first_received' ), 'Y-m-d g:i a' ),
			(int) chl_get( $id, 'submissions' ),
			chl_get( $id, 'first_name' ),
			chl_get( $id, 'last_name' ),
			chl_get( $id, 'email' ),
			chl_get( $id, 'phone' ),
			chl_stage_label( $stage ),
			$categories[ chl_stage_category( $stage ) ],
		);
		foreach ( array_keys( $boxes ) as $key ) {
			$row[] = chl_is_checked( $id, $key ) ? __( 'Yes', 'clickhive-leads' ) : '';
		}
		$notes = array();
		foreach ( chl_notes( $id ) as $n ) {
			$notes[] = chl_format_time( $n['at'], 'Y-m-d g:i a' ) . ' ' . $n['name'] . ': ' . $n['text'];
		}
		$row[] = implode( "\n", $notes );
		$forms = array();
		foreach ( get_post_meta( $id, '_chl_form' ) as $form_id ) {
			$forms[] = isset( $titles[ (int) $form_id ] ) ? $titles[ (int) $form_id ] : '#' . $form_id;
		}
		$row[] = implode( ', ', $forms );
		$row[] = chl_get( $id, 'source' );
		$data  = chl_get( $id, 'source_data' );
		$data  = is_array( $data ) ? $data : array();
		foreach ( array_keys( $source ) as $key ) {
			$row[] = isset( $data[ $key ] ) ? $data[ $key ] : '';
		}
		$fields = chl_fields( $id );
		foreach ( array_keys( $field_cols ) as $key ) {
			$row[] = isset( $fields[ $key ] ) ? $fields[ $key ]['value'] : '';
		}
		fputcsv( $out, array_map( 'chl_csv_cell', $row ), ',', '"', '\\' );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

/** Stops spreadsheet apps from running a cell that starts like a formula. */
function chl_csv_cell( $value ) {
	$value = (string) $value;
	if ( preg_match( '/^\+\d+$/', $value ) ) {
		return $value; // a phone number like +13605551234
	}
	if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
		$value = "'" . $value;
	}
	return $value;
}

<?php
/**
 * The lead list (Leads in the admin menu). Stage, checkboxes and notes are
 * worked straight from the list through admin-ajax, so staff rarely need to
 * open a lead.
 */

defined( 'ABSPATH' ) || exit;

function chl_is_list_screen() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	return $screen && 'edit-' . CHL_CPT === $screen->id;
}

// ---------------------------------------------------------------------------
// Menu: All Leads, Open leads, and a count of leads still at the first stage
// ---------------------------------------------------------------------------

/*
 * "Open leads" is also needed for access: when a role can see only one item
 * under a menu, WordPress folds the submenu away and then checks the page
 * against the Posts menu, which Lead Managers can't use, and refuses it.
 * Nobody can add leads by hand, so "Add New" goes.
 */
add_action( 'admin_menu', function () {
	$parent = 'edit.php?post_type=' . CHL_CPT;
	remove_submenu_page( $parent, 'post-new.php?post_type=' . CHL_CPT );
	add_submenu_page( $parent, __( 'Open leads', 'clickhive-leads' ), __( 'Open leads', 'clickhive-leads' ), CHL_CAP_WORK, $parent . '&chl_cat=open' );
}, 20 );

// Highlight "Open leads" rather than "All Leads" while that view is showing.
add_filter( 'submenu_file', function ( $submenu_file ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( chl_is_list_screen() && isset( $_GET['chl_cat'] ) && 'open' === $_GET['chl_cat'] && ! isset( $_GET['chl_stage'] ) ) {
		return 'edit.php?post_type=' . CHL_CPT . '&chl_cat=open';
	}
	return $submenu_file;
} );

add_action( 'admin_menu', function () {
	global $menu;
	if ( ! current_user_can( CHL_CAP_WORK ) || ! is_array( $menu ) ) {
		return;
	}
	$count = chl_count( array( array( 'key' => '_chl_stage', 'value' => chl_default_stage() ) ) );
	if ( ! $count ) {
		return;
	}
	foreach ( $menu as $i => $item ) {
		if ( isset( $item[2] ) && 'edit.php?post_type=' . CHL_CPT === $item[2] ) {
			$menu[ $i ][0] .= sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>', $count ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
	}
}, 99 );

function chl_count( $meta_query = array(), $date_query = array() ) {
	$q = new WP_Query(
		array(
			'post_type'      => CHL_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			'date_query'     => $date_query,
		)
	);
	return (int) $q->found_posts;
}

// ---------------------------------------------------------------------------
// Columns
// ---------------------------------------------------------------------------

add_filter( 'manage_' . CHL_CPT . '_posts_columns', function ( $cols ) {
	$out = array(
		'cb'           => $cols['cb'],
		'chl_received' => __( 'Received', 'clickhive-leads' ),
		'title'        => __( 'Name', 'clickhive-leads' ),
		'chl_email'    => __( 'Email', 'clickhive-leads' ),
		'chl_phone'    => __( 'Phone', 'clickhive-leads' ),
		'chl_stage'    => __( 'Stage', 'clickhive-leads' ),
	);
	foreach ( chl_checkboxes() as $key => $box ) {
		$out[ 'chl_cb_' . $key ] = $box['label'];
	}
	foreach ( chl_list_fields() as $key => $f ) {
		$out[ 'chl_f_' . $key ] = $f['short'] === $f['label']
			? esc_html( $f['label'] )
			: sprintf( '<span class="chl-col-label" title="%s">%s</span>', esc_attr( $f['label'] ), esc_html( $f['short'] ) );
	}
	$out['chl_notes'] = __( 'Latest note', 'clickhive-leads' );
	return $out;
} );

add_filter( 'manage_edit-' . CHL_CPT . '_sortable_columns', function ( $cols ) {
	$cols['chl_received'] = array( 'date', true );
	unset( $cols['title'] );
	return $cols;
} );

add_action( 'manage_' . CHL_CPT . '_posts_custom_column', function ( $col, $lead_id ) {
	$can_edit = current_user_can( 'edit_post', $lead_id );

	if ( 0 === strpos( $col, 'chl_cb_' ) ) {
		$key   = substr( $col, 7 );
		$boxes = chl_checkboxes();
		printf(
			'<input type="checkbox" class="chl-inline" data-lead="%d" data-field="%s" aria-label="%s"%s%s>',
			(int) $lead_id,
			esc_attr( 'cb:' . $key ),
			esc_attr( isset( $boxes[ $key ] ) ? $boxes[ $key ]['label'] : $key ),
			checked( chl_is_checked( $lead_id, $key ), true, false ),
			$can_edit ? '' : ' disabled'
		);
		return;
	}

	if ( 0 === strpos( $col, 'chl_f_' ) ) {
		$fields = chl_fields( $lead_id );
		$key    = substr( $col, 6 );
		if ( isset( $fields[ $key ] ) ) {
			echo esc_html( wp_trim_words( $fields[ $key ]['value'], 20 ) );
		}
		return;
	}

	switch ( $col ) {
		case 'chl_received':
			echo esc_html( get_the_date( 'm/d/Y', $lead_id ) ) . '<br><span class="chl-muted">' . esc_html( get_the_time( 'g:i a', $lead_id ) ) . '</span>';
			$times = (int) chl_get( $lead_id, 'submissions' );
			if ( $times > 1 ) {
				printf(
					'<br><span class="chl-returning" title="%s">%s</span>',
					esc_attr( sprintf( __( 'First received %s', 'clickhive-leads' ), chl_format_time( chl_get( $lead_id, 'first_received' ) ) ) ),
					esc_html( sprintf( __( 'Returning (%d)', 'clickhive-leads' ), $times ) )
				);
			}
			break;

		case 'chl_email':
			$email = chl_get( $lead_id, 'email' );
			if ( $email ) {
				printf( '<a href="mailto:%1$s">%2$s</a>', esc_attr( $email ), esc_html( $email ) );
			}
			break;

		case 'chl_phone':
			$phone = chl_get( $lead_id, 'phone' );
			if ( $phone ) {
				printf( '<a href="tel:%s">%s</a>', esc_attr( $phone ), esc_html( chl_format_phone( $phone ) ) );
			}
			break;

		case 'chl_stage':
			echo chl_stage_select( $lead_id, $can_edit ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
			break;

		case 'chl_notes':
			echo '<div class="chl-latest-note">' . chl_note_html( chl_latest_note( $lead_id ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
			if ( $can_edit ) {
				printf(
					'<div class="chl-add-note"><textarea rows="1" class="chl-note-input" data-lead="%1$d" aria-label="%2$s" placeholder="%2$s"></textarea><button type="button" class="button button-small chl-note-save" data-lead="%1$d">%3$s</button></div>',
					(int) $lead_id,
					esc_attr__( 'Add a note', 'clickhive-leads' ),
					esc_html__( 'Add', 'clickhive-leads' )
				);
			}
			break;
	}
}, 10, 2 );

/** The stage dropdown, shared by the list and the lead screen. */
function chl_stage_select( $lead_id, $enabled = true, $name = '' ) {
	$current = (string) chl_get( $lead_id, 'stage' );
	$stages  = chl_stages();
	$html    = sprintf(
		'<select class="chl-inline chl-stage" data-lead="%d" data-field="stage" aria-label="%s"%s%s>',
		(int) $lead_id,
		esc_attr__( 'Stage', 'clickhive-leads' ),
		$name ? ' name="' . esc_attr( $name ) . '"' : '',
		$enabled ? '' : ' disabled'
	);
	if ( '' !== $current && ! isset( $stages[ $current ] ) ) {
		$html .= sprintf( '<option value="%s" selected>%s</option>', esc_attr( $current ), esc_html( chl_stage_label( $current ) ) );
	}
	foreach ( chl_categories() as $cat => $cat_label ) {
		$group = '';
		foreach ( $stages as $key => $stage ) {
			if ( $stage['category'] === $cat ) {
				$group .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $current, $key, false ), esc_html( $stage['label'] ) );
			}
		}
		if ( '' !== $group ) {
			$html .= '<optgroup label="' . esc_attr( $cat_label ) . '">' . $group . '</optgroup>';
		}
	}
	return $html . '</select>';
}

function chl_note_html( $note ) {
	if ( ! $note ) {
		return '';
	}
	return sprintf(
		'<span class="chl-note-text" title="%s">%s</span><span class="chl-note-meta">%s</span>',
		esc_attr( $note['text'] ),
		esc_html( wp_html_excerpt( $note['text'], 140, '…' ) ),
		esc_html( $note['name'] . ', ' . chl_format_time( $note['at'], 'M j g:i a' ) )
	);
}

// No Quick Edit (it would skip the history), no "View" (leads have no public page).
add_filter( 'post_row_actions', function ( $actions, $post ) {
	if ( CHL_CPT !== $post->post_type ) {
		return $actions;
	}
	unset( $actions['inline hide-if-no-js'], $actions['view'] );
	if ( isset( $actions['edit'] ) ) {
		$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( chl_lead_url( $post->ID ) ), esc_html__( 'Open', 'clickhive-leads' ) );
	}
	return $actions;
}, 10, 2 );

add_filter( 'bulk_actions-edit-' . CHL_CPT, function ( $actions ) {
	unset( $actions['edit'] );
	return $actions;
} );

add_filter( 'disable_months_dropdown', function ( $disable, $post_type ) {
	return CHL_CPT === $post_type ? true : $disable;
}, 10, 2 );

// ---------------------------------------------------------------------------
// Filters and search. One function builds the query so the list and the CSV
// export always agree.
// ---------------------------------------------------------------------------

/** Filter values from a request, cleaned. */
function chl_filters_from( array $req ) {
	$get = function ( $k ) use ( $req ) {
		return isset( $req[ $k ] ) && is_string( $req[ $k ] ) ? sanitize_text_field( wp_unslash( $req[ $k ] ) ) : '';
	};
	$date = function ( $v ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
	};
	$f = array(
		'stage'     => sanitize_key( $get( 'chl_stage' ) ),
		'cat'       => sanitize_key( $get( 'chl_cat' ) ),
		'unchecked' => sanitize_key( $get( 'chl_unchecked' ) ),
		'from'      => $date( $get( 'chl_from' ) ),
		'to'        => $date( $get( 'chl_to' ) ),
		'form'      => absint( $get( 'chl_form' ) ),
		's'         => trim( $get( 's' ) ),
	);
	if ( ! isset( chl_categories()[ $f['cat'] ] ) ) {
		$f['cat'] = '';
	}
	if ( ! isset( chl_checkboxes()[ $f['unchecked'] ] ) ) {
		$f['unchecked'] = '';
	}
	return $f;
}

/** WP_Query arguments for a set of filters. */
function chl_query_args( array $f ) {
	$meta = array();
	if ( $f['stage'] ) {
		$meta[] = array( 'key' => '_chl_stage', 'value' => $f['stage'] );
	}
	if ( $f['cat'] ) {
		$keys   = chl_stages_in( $f['cat'] );
		$meta[] = array(
			'key'     => '_chl_stage',
			'value'   => $keys ? $keys : array( '-' ),
			'compare' => 'IN',
		);
	}
	if ( $f['unchecked'] ) {
		$meta[] = array(
			'relation' => 'OR',
			array(
				'key'     => '_chl_cb_' . $f['unchecked'],
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_chl_cb_' . $f['unchecked'],
				'value'   => '1',
				'compare' => '!=',
			),
		);
	}
	if ( $f['form'] ) {
		$meta[] = array( 'key' => '_chl_form', 'value' => (string) $f['form'] );
	}

	$args = array();
	if ( $meta ) {
		$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	$range = array( 'inclusive' => true );
	if ( $f['from'] ) {
		$range['after'] = $f['from'] . ' 00:00:00';
	}
	if ( $f['to'] ) {
		$range['before'] = $f['to'] . ' 23:59:59';
	}
	if ( count( $range ) > 1 ) {
		$args['date_query'] = array( $range );
	}
	if ( '' !== $f['s'] ) {
		$ids              = chl_search_ids( $f['s'] );
		$args['post__in'] = $ids ? $ids : array( 0 );
		$args['chl_search'] = 1;
	}
	return $args;
}

/**
 * Finds leads by name, email, notes, or any run of 4+ digits from a phone
 * number, so "360-555", "(360) 555" and "5551234" all match +13605551234.
 */
function chl_search_ids( $term ) {
	global $wpdb;
	$like   = '%' . $wpdb->esc_like( $term ) . '%';
	$digits = preg_replace( '/\D+/', '', $term );
	$sql    = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			AND m.meta_key IN ( '_chl_first_name', '_chl_last_name', '_chl_email', '_chl_phone', '_chl_notes_text' )
		WHERE p.post_type = %s AND p.post_status <> 'auto-draft'
		AND ( p.post_title LIKE %s OR m.meta_value LIKE %s";
	$args   = array( CHL_CPT, $like, $like );
	if ( strlen( $digits ) >= 4 ) {
		$sql   .= " OR ( m.meta_key = '_chl_phone' AND m.meta_value LIKE %s )";
		$args[] = '%' . $wpdb->esc_like( $digits ) . '%';
	}
	$sql .= ' )';
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB
}

add_action( 'pre_get_posts', function ( $query ) {
	global $pagenow;
	if ( ! is_admin() || ! $query->is_main_query() || 'edit.php' !== $pagenow || CHL_CPT !== $query->get( 'post_type' ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters
	foreach ( chl_query_args( chl_filters_from( $_GET ) ) as $k => $v ) {
		$query->set( $k, $v );
	}
} );

// Our search replaces WordPress's title search. Keeping "s" set keeps the
// "Search results for" heading.
add_filter( 'posts_search', function ( $search, $query ) {
	return $query->get( 'chl_search' ) ? '' : $search;
}, 10, 2 );

// Quick views by stage category.
add_filter( 'views_edit-' . CHL_CPT, function ( $views ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$current = chl_filters_from( $_GET )['cat'];
	$trash   = isset( $views['trash'] ) ? $views['trash'] : '';
	$all     = isset( $_GET['post_status'] ) && 'trash' === $_GET['post_status'] ? '' : ( $current ? '' : 'current' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$out     = array(
		'all' => sprintf( '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>', esc_url( chl_list_url() ), $all, esc_html__( 'All', 'clickhive-leads' ), chl_count() ),
	);
	foreach ( chl_categories() as $cat => $label ) {
		$keys        = chl_stages_in( $cat );
		$n           = $keys ? chl_count( array( array( 'key' => '_chl_stage', 'value' => $keys, 'compare' => 'IN' ) ) ) : 0;
		$out[ $cat ] = sprintf( '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>', esc_url( chl_list_url( array( 'chl_cat' => $cat ) ) ), $current === $cat ? 'current' : '', esc_html( $label ), $n );
	}
	if ( $trash ) {
		$out['trash'] = $trash;
	}
	return $out;
} );

add_action( 'restrict_manage_posts', function ( $post_type, $which = 'top' ) {
	if ( CHL_CPT !== $post_type || 'top' !== $which ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$f = chl_filters_from( $_GET );

	echo '<label class="screen-reader-text" for="chl-f-stage">' . esc_html__( 'Stage', 'clickhive-leads' ) . '</label>';
	echo '<select name="chl_stage" id="chl-f-stage"><option value="">' . esc_html__( 'All stages', 'clickhive-leads' ) . '</option>';
	foreach ( chl_stages() as $key => $stage ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $f['stage'], $key, false ), esc_html( $stage['label'] ) );
	}
	echo '</select>';

	echo '<label class="screen-reader-text" for="chl-f-cat">' . esc_html__( 'Stage category', 'clickhive-leads' ) . '</label>';
	echo '<select name="chl_cat" id="chl-f-cat"><option value="">' . esc_html__( 'Open, Won and Lost', 'clickhive-leads' ) . '</option>';
	foreach ( chl_categories() as $key => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $f['cat'], $key, false ), esc_html( $label ) );
	}
	echo '</select>';

	$boxes = chl_checkboxes();
	if ( $boxes ) {
		echo '<label class="screen-reader-text" for="chl-f-unchecked">' . esc_html__( 'Checkbox', 'clickhive-leads' ) . '</label>';
		echo '<select name="chl_unchecked" id="chl-f-unchecked"><option value="">' . esc_html__( 'All checkboxes', 'clickhive-leads' ) . '</option>';
		foreach ( $boxes as $key => $box ) {
			/* translators: %s: checkbox label */
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $f['unchecked'], $key, false ), esc_html( sprintf( __( 'Not %s', 'clickhive-leads' ), $box['label'] ) ) );
		}
		echo '</select>';
	}

	$titles = chl_form_titles();
	$forms  = array();
	foreach ( chl_connected_forms() as $form ) {
		$forms[ $form['id'] ] = $form['title'];
	}
	if ( $f['form'] && ! isset( $forms[ $f['form'] ] ) && isset( $titles[ $f['form'] ] ) ) {
		$forms[ $f['form'] ] = $titles[ $f['form'] ];
	}
	if ( count( $forms ) > 1 || $f['form'] ) {
		echo '<label class="screen-reader-text" for="chl-f-form">' . esc_html__( 'Form', 'clickhive-leads' ) . '</label>';
		echo '<select name="chl_form" id="chl-f-form"><option value="">' . esc_html__( 'All forms', 'clickhive-leads' ) . '</option>';
		foreach ( $forms as $id => $title ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $f['form'], $id, false ), esc_html( $title ) );
		}
		echo '</select>';
	}

	printf(
		'<span class="chl-dates"><label for="chl-f-from">%s</label> <input type="date" name="chl_from" id="chl-f-from" value="%s"> <label for="chl-f-to">%s</label> <input type="date" name="chl_to" id="chl-f-to" value="%s"></span>',
		esc_html__( 'From', 'clickhive-leads' ),
		esc_attr( $f['from'] ),
		esc_html__( 'to', 'clickhive-leads' ),
		esc_attr( $f['to'] )
	);
}, 10, 2 );

// ---------------------------------------------------------------------------
// Summary and export link above the list
// ---------------------------------------------------------------------------

/** Leads that sent a form in a given month (site time zone). */
function chl_count_month( DateTimeImmutable $month ) {
	return chl_count(
		array(),
		array(
			array(
				'year'  => (int) $month->format( 'Y' ),
				'month' => (int) $month->format( 'n' ),
			),
		)
	);
}

/** Leads moved to a Won stage during a month, from the stage history. */
function chl_count_won( DateTimeImmutable $month ) {
	global $wpdb;
	$start = $month->setTime( 0, 0 );
	$end   = $start->modify( '+1 month' );
	$utc   = new DateTimeZone( 'UTC' );
	$table = chl_history_table();
	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT COUNT( DISTINCT h.lead_id ) FROM {$table} h INNER JOIN {$wpdb->posts} p ON p.ID = h.lead_id AND p.post_status = 'publish'
			WHERE h.event = 'stage' AND h.category = 'won' AND h.created_at >= %s AND h.created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$end->setTimezone( $utc )->format( 'Y-m-d H:i:s' )
		)
	);
}

add_action( 'manage_posts_extra_tablenav', function ( $which ) {
	if ( 'top' !== $which || ! chl_is_list_screen() ) {
		return;
	}
	$this_month = current_datetime()->modify( 'first day of this month' )->setTime( 0, 0 );
	$last_month = $this_month->modify( '-1 month' );

	echo '<div class="alignleft actions chl-summary">';
	printf(
		/* translators: 1: leads this month, 2: won this month, 3: leads last month */
		esc_html__( 'This month: %1$s leads, %2$s won | Last month: %3$s leads', 'clickhive-leads' ),
		'<strong class="chl-sum-this">' . (int) chl_count_month( $this_month ) . '</strong>',
		'<strong class="chl-sum-won">' . (int) chl_count_won( $this_month ) . '</strong>',
		'<strong class="chl-sum-last">' . (int) chl_count_month( $last_month ) . '</strong>'
	);
	echo '</div>';

	if ( current_user_can( CHL_CAP_EXPORT ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$f    = chl_filters_from( $_GET );
		$args = array_filter(
			array(
				'action'        => 'chl_export',
				'chl_stage'     => $f['stage'],
				'chl_cat'       => $f['cat'],
				'chl_unchecked' => $f['unchecked'],
				'chl_from'      => $f['from'],
				'chl_to'        => $f['to'],
				'chl_form'      => $f['form'] ? (string) $f['form'] : '',
				's'             => $f['s'],
			)
		);
		$url  = wp_nonce_url( add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin-post.php' ) ), 'chl_export' );
		printf( '<div class="alignleft actions"><a class="button chl-export" href="%s">%s</a></div>', esc_url( $url ), esc_html__( 'Export CSV', 'clickhive-leads' ) );
	}
} );

// ---------------------------------------------------------------------------
// Inline saving
// ---------------------------------------------------------------------------

add_action( 'admin_enqueue_scripts', function () {
	$screen = get_current_screen();
	if ( ! $screen || CHL_CPT !== $screen->post_type ) {
		return;
	}
	wp_enqueue_style( 'chl-admin', CHL_URL . 'assets/admin.css', array(), CHL_VERSION );
	if ( 'edit-' . CHL_CPT === $screen->id ) {
		wp_enqueue_script( 'chl-list', CHL_URL . 'assets/list.js', array(), CHL_VERSION, true );
		wp_localize_script(
			'chl-list',
			'CHL',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'chl_inline' ),
			)
		);
	}
} );

add_action( 'wp_ajax_chl_inline', function () {
	check_ajax_referer( 'chl_inline', 'nonce' );

	$lead_id = isset( $_POST['lead'] ) ? absint( $_POST['lead'] ) : 0;
	$field   = isset( $_POST['field'] ) ? sanitize_text_field( wp_unslash( $_POST['field'] ) ) : '';
	$value   = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field below
	$value   = is_string( $value ) ? $value : '';

	if ( ! $lead_id || CHL_CPT !== get_post_type( $lead_id ) || ! current_user_can( 'edit_post', $lead_id ) ) {
		wp_send_json_error( 'Not allowed.', 403 );
	}

	if ( 'stage' === $field ) {
		$value = sanitize_key( $value );
		if ( ! isset( chl_stages()[ $value ] ) ) {
			wp_send_json_error( 'Unknown stage.', 400 );
		}
		chl_set_stage( $lead_id, $value );
		wp_send_json_success( array( 'category' => chl_stage_category( $value ) ) );
	}

	if ( 0 === strpos( $field, 'cb:' ) ) {
		$key = sanitize_key( substr( $field, 3 ) );
		if ( ! isset( chl_checkboxes()[ $key ] ) ) {
			wp_send_json_error( 'Unknown checkbox.', 400 );
		}
		chl_set_checkbox( $lead_id, $key, '1' === $value );
		wp_send_json_success();
	}

	if ( 'note' === $field ) {
		$note = chl_add_note( $lead_id, $value );
		if ( ! $note ) {
			wp_send_json_error( 'Empty note.', 400 );
		}
		wp_send_json_success( array( 'html' => chl_note_html( $note ) ) );
	}

	wp_send_json_error( 'Unknown field.', 400 );
} );

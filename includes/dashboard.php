<?php
/**
 * Scorecard above the lead list, and a compact leads summary for Dashboard > Home
 * and the Click Hive Client Dashboard.
 */

defined( 'ABSPATH' ) || exit;

/** First day of a month in the site time zone. $months_back 0 = this month. */
function chl_month_start( $months_back = 0 ) {
	$month = current_datetime()->modify( 'first day of this month' )->setTime( 0, 0 );
	return $months_back ? $month->modify( '-' . absint( $months_back ) . ' month' ) : $month;
}

/** Leads moved to a Lost stage during a month, from the stage history. */
function chl_count_lost( DateTimeImmutable $month ) {
	return chl_count_moved_to( $month, 'lost' );
}

/** Numbers for the scorecard and the dashboard summary. */
function chl_scorecard_numbers() {
	$this_month = chl_month_start( 0 );
	$open       = chl_stages_in( 'open' );
	return array(
		'this_month' => chl_count_month( $this_month ),
		'won'        => chl_count_won( $this_month ),
		'lost'       => chl_count_lost( $this_month ),
		'last_month' => chl_count_month( chl_month_start( 1 ) ),
		'open'       => $open ? chl_count( array( array( 'key' => '_chl_stage', 'value' => $open, 'compare' => 'IN' ) ) ) : 0,
	);
}

/** One scorecard tile. */
function chl_tile( $label, $value, $url = '' ) {
	$tag  = $url ? 'a' : 'div';
	$href = $url ? ' href="' . esc_url( $url ) . '"' : '';
	return sprintf(
		'<%1$s class="chl-tile"%2$s><span class="chl-tile-value">%3$s</span><span class="chl-tile-label">%4$s</span></%1$s>',
		$tag,
		$href,
		esc_html( number_format_i18n( (int) $value ) ),
		esc_html( $label )
	);
}

/** The four-tile scorecard shown above the lead list. */
function chl_render_scorecard() {
	$n     = chl_scorecard_numbers();
	$first = chl_month_start( 0 );
	$last  = chl_month_start( 1 );

	echo '<div class="chl-scorecard" role="group" aria-label="' . esc_attr__( 'Lead summary', 'clickhive-leads' ) . '">';
	// phpcs:disable WordPress.Security.EscapeOutput -- escaped in chl_tile()
	echo chl_tile( __( 'Total leads this month', 'clickhive-leads' ), $n['this_month'], chl_list_url( array( 'chl_from' => $first->format( 'Y-m-d' ) ) ) );
	echo chl_tile( __( 'Leads won this month', 'clickhive-leads' ), $n['won'] );
	echo chl_tile( __( 'Leads lost this month', 'clickhive-leads' ), $n['lost'] );
	echo chl_tile(
		__( 'Total leads last month', 'clickhive-leads' ),
		$n['last_month'],
		chl_list_url(
			array(
				'chl_from' => $last->format( 'Y-m-d' ),
				'chl_to'   => $first->modify( '-1 day' )->format( 'Y-m-d' ),
			)
		)
	);
	// phpcs:enable
	echo '</div>';
}

// Above the quick views on the lead list. Printing from this filter places the
// scorecard right under the page title.
add_filter( 'views_edit-' . CHL_CPT, function ( $views ) {
	chl_render_scorecard();
	return $views;
}, 1 );

// ---------------------------------------------------------------------------
// Dashboard summary
// ---------------------------------------------------------------------------

function chl_dashboard_enabled() {
	return (bool) chl_setting( 'dashboard_card' ) && current_user_can( CHL_CAP_WORK );
}

/** Compact summary: leads this month, open leads, latest lead. */
function chl_render_dashboard_card() {
	$n = chl_scorecard_numbers();

	echo '<div class="chl-dash">';
	echo '<div class="chl-scorecard chl-scorecard-compact">';
	// phpcs:disable WordPress.Security.EscapeOutput -- escaped in chl_tile()
	echo chl_tile( __( 'Leads this month', 'clickhive-leads' ), $n['this_month'], chl_list_url( array( 'chl_from' => chl_month_start( 0 )->format( 'Y-m-d' ) ) ) );
	echo chl_tile( __( 'Open leads', 'clickhive-leads' ), $n['open'], chl_list_url( array( 'chl_cat' => 'open' ) ) );
	// phpcs:enable
	echo '</div>';

	$ids = get_posts(
		array(
			'post_type'      => CHL_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
	if ( $ids ) {
		$id    = (int) $ids[0];
		$stage = (string) chl_get( $id, 'stage' );
		$name  = chl_lead_name( $id );
		echo '<p class="chl-dash-latest"><span class="chl-dash-latest-label">' . esc_html__( 'Latest lead', 'clickhive-leads' ) . '</span> ';
		echo '<a href="' . esc_url( chl_lead_url( $id ) ) . '"><strong>' . esc_html( '' !== $name ? $name : __( 'No name', 'clickhive-leads' ) ) . '</strong></a>';
		echo ' &middot; ' . esc_html( sprintf( __( '%s ago', 'clickhive-leads' ), human_time_diff( (int) get_post_time( 'U', true, $id ) ) ) );
		if ( '' !== $stage ) {
			echo ' <span class="chl-dash-stage chl-cat-' . esc_attr( chl_stage_category( $stage ) ) . '">' . esc_html( chl_stage_label( $stage ) ) . '</span>';
		}
		echo '</p>';
	} else {
		echo '<p class="chl-dash-latest">' . esc_html__( 'No leads yet.', 'clickhive-leads' ) . '</p>';
	}

	echo '<p class="chl-dash-actions"><a class="button" href="' . esc_url( chl_list_url() ) . '">' . esc_html__( 'Open Leads', 'clickhive-leads' ) . '</a></p>';
	echo '</div>';
}

// Dashboard > Home widget.
add_action( 'wp_dashboard_setup', function () {
	if ( chl_dashboard_enabled() ) {
		wp_add_dashboard_widget( 'chl_leads_summary', __( 'Leads', 'clickhive-leads' ), 'chl_render_dashboard_card' );
	}
} );

// Click Hive Utility's full Client Dashboard page (Home already has the widget above).
add_action( 'chu_client_dashboard_after_links', function ( $full_page = true ) {
	if ( $full_page && chl_dashboard_enabled() ) {
		echo '<h2>' . esc_html__( 'Leads', 'clickhive-leads' ) . '</h2><section class="chm-card chl-dash-card">';
		chl_render_dashboard_card();
		echo '</section>';
	}
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	$dashboards = array( 'index.php', 'dashboard_page_chm-client-dashboard' );
	$screen     = get_current_screen();
	if ( ( $screen && 'edit-' . CHL_CPT === $screen->id ) || ( in_array( $hook, $dashboards, true ) && chl_dashboard_enabled() ) ) {
		wp_enqueue_style( 'chl-scorecard', CHL_URL . 'assets/scorecard.css', array(), CHL_VERSION );
	}
} );

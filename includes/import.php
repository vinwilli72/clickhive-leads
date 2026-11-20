<?php
/**
 * One-time import of past Gravity Forms entries.
 *
 * Settings > Click Hive Leads > Import past entries runs a connected form's
 * existing entries through the same intake as a live submission, oldest
 * first, in small batches so large forms don't time out. Each entry keeps its
 * original date. Entries already linked to a lead (live or imported) are
 * skipped, so running it again only picks up what was missed.
 */

defined( 'ABSPATH' ) || exit;

/** Entries per batch. Each batch is one page load. */
const CHL_IMPORT_BATCH = 25;

/** Seconds a batch may run before handing off to the next page load. */
const CHL_IMPORT_SECONDS = 20;

function chl_import_job_key() {
	return 'chl_import_' . get_current_user_id();
}

function chl_import_job() {
	$job = get_transient( chl_import_job_key() );
	return is_array( $job ) ? $job : null;
}

function chl_import_save_job( array $job ) {
	set_transient( chl_import_job_key(), $job, DAY_IN_SECONDS );
}

function chl_import_settings_url( $args = array() ) {
	return add_query_arg( $args, admin_url( 'options-general.php?page=chl-settings' ) );
}

/**
 * Raw URL for the next batch. Built with add_query_arg rather than
 * wp_nonce_url, which escapes & as &amp; for HTML and breaks redirects.
 */
function chl_import_step_url() {
	return add_query_arg(
		array(
			'action'   => 'chl_import_step',
			'_wpnonce' => wp_create_nonce( 'chl_import_step' ),
		),
		admin_url( 'admin-post.php' )
	);
}

/** Search criteria for a job: active entries, optionally from a start date. */
function chl_import_criteria( array $job ) {
	$criteria = array( 'status' => 'active' );
	if ( ! empty( $job['from'] ) ) {
		$criteria['start_date'] = $job['from'];
	}
	return $criteria;
}

/** How many of a form's active entries are already linked to a lead. */
function chl_import_linked_count( $form_id ) {
	global $wpdb;
	if ( ! class_exists( 'GFFormsModel' ) ) {
		return 0;
	}
	$meta  = GFFormsModel::get_entry_meta_table_name();
	$entry = GFFormsModel::get_entry_table_name();
	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT COUNT( DISTINCT m.entry_id ) FROM {$meta} m INNER JOIN {$entry} e ON e.id = m.entry_id
			WHERE m.meta_key = 'chl_lead_id' AND m.form_id = %d AND e.status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$form_id
		)
	);
}

/**
 * Source for an old entry. The visitor's first-visit cookie is long gone, but
 * Gravity Forms saved the page the form was sent from. When that address
 * carries UTM tags or a Google click ID, they are kept; otherwise the source
 * is left empty rather than guessed.
 */
function chl_import_source( $entry ) {
	$url = (string) rgar( $entry, 'source_url' );
	$q   = array();
	wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	$data = array();
	foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid' ) as $key ) {
		if ( ! empty( $q[ $key ] ) && is_string( $q[ $key ] ) ) {
			$data[ $key ] = $q[ $key ];
		}
	}
	if ( ! $data ) {
		return array();
	}
	$data['landing'] = $url;
	return chl_clean_source( $data );
}

/**
 * Imports one entry. Returns created, updated, skipped or no_match.
 */
function chl_import_entry( array $entry, array $form, $stage ) {
	if ( gform_get_meta( $entry['id'], 'chl_lead_id' ) ) {
		return 'skipped';
	}

	$s               = chl_gf_submission( $entry, $form );
	$s['source']     = chl_import_source( $entry );
	$s['token']      = '';
	$s['want_token'] = false;
	$s['imported']   = true;

	// A follow-up step can't be tied to its first step by the private link,
	// which didn't exist yet. Match by email or phone instead.
	if ( 'followup' === $s['role'] ) {
		$lead_id = chl_find_person( strtolower( sanitize_email( $s['email'] ) ), chl_normalize_phone( $s['phone'] ) );
		if ( ! $lead_id ) {
			return 'no_match';
		}
		$s['lead_id'] = $lead_id;
	}

	$created_at = strtotime( (string) rgar( $entry, 'date_created' ) . ' UTC' );
	chl_now( $created_at ? min( $created_at, time() ) : 0 );
	try {
		$result = chl_receive( $s );
		if ( ! is_wp_error( $result ) && $result['created'] && $stage && chl_default_stage() !== $stage ) {
			chl_set_stage( $result['lead_id'], $stage, 'imported' );
		}
	} finally {
		chl_now( 0 );
	}

	if ( is_wp_error( $result ) ) {
		GFCommon::log_error( 'Click Hive Leads import, entry ' . (int) $entry['id'] . ': ' . $result->get_error_message() );
		return 'error';
	}
	gform_update_meta( $entry['id'], 'chl_lead_id', $result['lead_id'], $form['id'] );
	return $result['created'] ? 'created' : 'updated';
}

// Start: from the form on the settings page.
add_action( 'admin_post_chl_import_start', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'clickhive-leads' ), 403 );
	}
	check_admin_referer( 'chl_import_start' );
	if ( ! class_exists( 'GFAPI' ) ) {
		wp_safe_redirect( chl_import_settings_url() );
		exit;
	}

	$form_id = isset( $_POST['chl_import_form'] ) ? absint( $_POST['chl_import_form'] ) : 0;
	$form    = $form_id ? GFAPI::get_form( $form_id ) : false;
	if ( ! $form || ! chl_gf_enabled( $form ) ) {
		wp_safe_redirect( chl_import_settings_url( array( 'chl_import_error' => 'form' ) ) );
		exit;
	}

	$from = isset( $_POST['chl_import_from'] ) ? sanitize_text_field( wp_unslash( $_POST['chl_import_from'] ) ) : '';
	$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : '';

	$stage = isset( $_POST['chl_import_stage'] ) ? sanitize_key( wp_unslash( $_POST['chl_import_stage'] ) ) : '';
	$stage = isset( chl_stages()[ $stage ] ) ? $stage : chl_default_stage();

	$job          = array(
		'form_id'    => $form_id,
		'form_title' => (string) $form['title'],
		'from'       => $from,
		'stage'      => $stage,
		'offset'     => 0,
		'done'       => false,
		'counts'     => array(
			'created'  => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'no_match' => 0,
			'error'    => 0,
		),
	);
	$job['total'] = (int) GFAPI::count_entries( $form_id, chl_import_criteria( $job ) );
	chl_import_save_job( $job );

	wp_safe_redirect( chl_import_step_url() );
	exit;
} );

// One batch. Hands back to the settings page, which loads the next batch
// until the job is done.
add_action( 'admin_post_chl_import_step', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'clickhive-leads' ), 403 );
	}
	check_admin_referer( 'chl_import_step' );
	$job = chl_import_job();
	if ( ! $job || $job['done'] || ! class_exists( 'GFAPI' ) ) {
		wp_safe_redirect( chl_import_settings_url() );
		exit;
	}

	$form  = GFAPI::get_form( $job['form_id'] );
	$start = microtime( true );
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( CHL_IMPORT_SECONDS + 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	$entries = $form ? GFAPI::get_entries(
		$job['form_id'],
		chl_import_criteria( $job ),
		array(
			'key'        => 'id',
			'direction'  => 'ASC',
			'is_numeric' => true,
		),
		array(
			'offset'    => (int) $job['offset'],
			'page_size' => CHL_IMPORT_BATCH,
		)
	) : array();
	$entries = is_wp_error( $entries ) ? array() : $entries;

	foreach ( $entries as $entry ) {
		$outcome = chl_import_entry( $entry, $form, $job['stage'] );
		$job['counts'][ $outcome ]++;
		$job['offset']++;
		if ( microtime( true ) - $start > CHL_IMPORT_SECONDS ) {
			break;
		}
	}

	if ( ! $entries || $job['offset'] >= $job['total'] ) {
		$job['done'] = true;
	}
	chl_import_save_job( $job );

	wp_safe_redirect( chl_import_settings_url( array( 'chl_import' => $job['done'] ? 'done' : 'running' ) ) );
	exit;
} );

add_action( 'admin_post_chl_import_cancel', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'clickhive-leads' ), 403 );
	}
	check_admin_referer( 'chl_import_cancel' );
	delete_transient( chl_import_job_key() );
	wp_safe_redirect( chl_import_settings_url() );
	exit;
} );

/** Progress or results at the top of the settings page. */
function chl_import_render_status() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['chl_import_error'] ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Choose a connected form to import.', 'clickhive-leads' ) . '</p></div>';
	}
	$job = chl_import_job();
	if ( ! $job ) {
		return;
	}
	$c = $job['counts'];

	if ( ! $job['done'] ) {
		$step = chl_import_step_url();
		printf(
			'<div class="notice notice-info"><p><strong>%s</strong> %s</p><p><a href="%s">%s</a> &middot; <a href="%s">%s</a></p></div>',
			esc_html( sprintf( __( 'Importing past entries from %s.', 'clickhive-leads' ), $job['form_title'] ) ),
			/* translators: 1: entries worked through, 2: total entries */
			esc_html( sprintf( __( '%1$s of %2$s entries checked. Keep this page open until it finishes.', 'clickhive-leads' ), number_format_i18n( $job['offset'] ), number_format_i18n( $job['total'] ) ) ),
			esc_url( $step ),
			esc_html__( 'Continue', 'clickhive-leads' ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=chl_import_cancel' ), 'chl_import_cancel' ) ),
			esc_html__( 'Stop', 'clickhive-leads' )
		);
		// Loads the next batch on its own; the Continue link is the fallback.
		echo '<script>setTimeout(function(){location.replace(' . wp_json_encode( $step ) . ');},300);</script>';
		return;
	}

	$lines = array(
		/* translators: 1: number, 2: plural name for leads, lowercase */
		sprintf( __( '%1$s new %2$s created.', 'clickhive-leads' ), number_format_i18n( $c['created'] ), chl_label( 'plural_lc' ) ),
		/* translators: 1: number, 2: plural name for leads, lowercase */
		sprintf( __( '%1$s entries added to existing %2$s (the same person sent the form more than once).', 'clickhive-leads' ), number_format_i18n( $c['updated'] ), chl_label( 'plural_lc' ) ),
		/* translators: %s: number */
		sprintf( __( '%s entries were already in the list and were skipped.', 'clickhive-leads' ), number_format_i18n( $c['skipped'] ) ),
	);
	if ( $c['no_match'] ) {
		/* translators: 1: number, 2: singular name for a lead, lowercase */
		$lines[] = sprintf( __( '%1$s follow-up entries had no matching %2$s by email or phone and were left out.', 'clickhive-leads' ), number_format_i18n( $c['no_match'] ), chl_label( 'singular_lc' ) );
	}
	if ( $c['error'] ) {
		/* translators: %s: number */
		$lines[] = sprintf( __( '%s entries could not be imported. Details are in the Gravity Forms log.', 'clickhive-leads' ), number_format_i18n( $c['error'] ) );
	}
	echo '<div class="notice notice-success"><p><strong>' . esc_html( sprintf( __( 'Import from %s finished.', 'clickhive-leads' ), $job['form_title'] ) ) . '</strong></p>';
	foreach ( $lines as $line ) {
		echo '<p>' . esc_html( $line ) . '</p>';
	}
	echo '</div>';
	delete_transient( chl_import_job_key() );
}

/** The "Import past entries" section of the settings page. */
function chl_import_render_section() {
	if ( ! class_exists( 'GFAPI' ) ) {
		return;
	}
	$forms = chl_connected_forms();
	if ( ! $forms ) {
		return;
	}
	$plural_lc = chl_label( 'plural_lc' );
	$retention = (int) chl_setting( 'retention_days' );
	?>
	<h2><?php esc_html_e( 'Import past entries', 'clickhive-leads' ); ?></h2>
	<p style="max-width:720px"><?php echo esc_html( sprintf( __( 'Brings entries a form received before it was connected into the list. They keep their original dates, so they don\'t count toward this month\'s numbers. Entries already in the list are skipped, so running it again is safe. Someone who sent the form more than once becomes one %s with each submission counted.', 'clickhive-leads' ), chl_label( 'singular_lc' ) ) ); ?></p>
	<?php if ( $retention ) : ?>
		<div class="notice notice-warning inline" style="max-width:720px"><p><?php echo esc_html( sprintf( __( 'This site keeps %1$s for %2$d days. Imported %1$s older than that will be deleted at the next daily cleanup. Set that to 0 above first, or pick a start date inside that window.', 'clickhive-leads' ), $plural_lc, $retention ) ); ?></p></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="chl_import_start">
		<?php wp_nonce_field( 'chl_import_start' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="chl_import_form"><?php esc_html_e( 'Form', 'clickhive-leads' ); ?></label></th>
				<td>
					<select id="chl_import_form" name="chl_import_form">
						<?php foreach ( $forms as $f ) : ?>
							<?php
							$total  = (int) GFAPI::count_entries( $f['id'], array( 'status' => 'active' ) );
							$linked = min( $total, chl_import_linked_count( $f['id'] ) );
							/* translators: 1: form name, 2: entries already linked, 3: total entries */
							$label = sprintf( __( '%1$s (%2$s of %3$s entries already in the list)', 'clickhive-leads' ), $f['title'], number_format_i18n( $linked ), number_format_i18n( $total ) );
							?>
							<option value="<?php echo (int) $f['id']; ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php echo esc_html( sprintf( __( 'Follow-up step forms are matched to existing %s by email or phone. Run the first-step form before its follow-up form.', 'clickhive-leads' ), $plural_lc ) ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="chl_import_from"><?php esc_html_e( 'Entries from', 'clickhive-leads' ); ?></label></th>
				<td>
					<input type="date" id="chl_import_from" name="chl_import_from">
					<p class="description"><?php esc_html_e( 'Optional. Leave empty to import every entry the form has.', 'clickhive-leads' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="chl_import_stage"><?php esc_html_e( 'Stage', 'clickhive-leads' ); ?></label></th>
				<td>
					<select id="chl_import_stage" name="chl_import_stage">
						<?php foreach ( chl_stages() as $key => $stage ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( chl_default_stage(), $key ); ?>><?php echo esc_html( $stage['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php echo esc_html( sprintf( __( 'The stage newly created %s start in. Older requests have usually been handled already, so a Won or Lost stage keeps them out of the open list. This doesn\'t count toward the won and lost numbers on the scorecard.', 'clickhive-leads' ), $plural_lc ) ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Import past entries', 'clickhive-leads' ), 'secondary' ); ?>
	</form>
	<?php
}

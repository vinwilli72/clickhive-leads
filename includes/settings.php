<?php
/**
 * Settings: stages, checkboxes, extra list columns and retention.
 * Settings > Click Hive Leads (administrators only).
 */

defined( 'ABSPATH' ) || exit;

const CHL_OPTION = 'chl_settings';

function chl_defaults() {
	return array(
		'stages'         => "New\nContacted\nQuoted\nCustomer | Won\nNot a fit | Lost",
		'checkboxes'     => "Followed up | Clear\nAdded to CRM",
		'list_fields'    => array(),
		'retention_days' => 0,
	);
}

function chl_settings() {
	return wp_parse_args( get_option( CHL_OPTION, array() ), chl_defaults() );
}

function chl_setting( $key ) {
	$s = chl_settings();
	return isset( $s[ $key ] ) ? $s[ $key ] : null;
}

/** The three fixed stage categories. Reports group by these. */
function chl_categories() {
	return array(
		'open' => __( 'Open', 'clickhive-leads' ),
		'won'  => __( 'Won', 'clickhive-leads' ),
		'lost' => __( 'Lost', 'clickhive-leads' ),
	);
}

/**
 * Reads a one-per-line setting. Each line is a label, optionally followed by
 * "| word" parts: a category (Open, Won, Lost), "Clear", or anything else as
 * a fixed key. The key otherwise comes from the label, so changing only
 * capitals or punctuation keeps existing leads matched; removing a line
 * retires it without touching stored data.
 *
 * @return array key => array( 'label' => string, 'flags' => string[] )
 */
function chl_parse_lines( $text ) {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		$label = array_shift( $parts );
		if ( '' === $label ) {
			continue;
		}
		$key   = '';
		$flags = array();
		foreach ( $parts as $p ) {
			$word = strtolower( $p );
			if ( '' === $word ) {
				continue;
			}
			if ( in_array( $word, array( 'open', 'won', 'lost', 'clear' ), true ) ) {
				$flags[] = $word;
			} elseif ( '' === $key ) {
				$key = sanitize_key( $p );
			}
		}
		$key = $key ? $key : sanitize_title( $label );
		if ( $key && ! isset( $out[ $key ] ) ) {
			$out[ $key ] = array(
				'label' => $label,
				'flags' => $flags,
			);
		}
	}
	return $out;
}

/** @return array key => array( 'label' => string, 'category' => open|won|lost ) */
function chl_stages() {
	static $cache = array();
	$text = (string) chl_setting( 'stages' );
	if ( isset( $cache[ $text ] ) ) {
		return $cache[ $text ];
	}
	$out = array();
	foreach ( chl_parse_lines( $text ) as $key => $row ) {
		$category = 'open';
		foreach ( array( 'won', 'lost', 'open' ) as $c ) {
			if ( in_array( $c, $row['flags'], true ) ) {
				$category = $c;
				break;
			}
		}
		$out[ $key ] = array(
			'label'    => $row['label'],
			'category' => $category,
		);
	}
	if ( ! $out ) {
		$out['new'] = array(
			'label'    => __( 'New', 'clickhive-leads' ),
			'category' => 'open',
		);
	}
	$cache[ $text ] = $out;
	return $out;
}

/** The stage new leads get: the first Open stage. */
function chl_default_stage() {
	foreach ( chl_stages() as $key => $stage ) {
		if ( 'open' === $stage['category'] ) {
			return $key;
		}
	}
	return (string) array_key_first( chl_stages() );
}

/** Stage keys in a category, for filtering. */
function chl_stages_in( $category ) {
	$keys = array();
	foreach ( chl_stages() as $key => $stage ) {
		if ( $stage['category'] === $category ) {
			$keys[] = $key;
		}
	}
	return $keys;
}

/** Label for a stored stage key, including stages since retired. */
function chl_stage_label( $key ) {
	if ( '' === (string) $key ) {
		return '';
	}
	$stages = chl_stages();
	return isset( $stages[ $key ] ) ? $stages[ $key ]['label'] : ucwords( str_replace( array( '-', '_' ), ' ', $key ) );
}

/** Category of a stage key. Retired stages count as Open. */
function chl_stage_category( $key ) {
	$stages = chl_stages();
	return isset( $stages[ $key ] ) ? $stages[ $key ]['category'] : 'open';
}

/** @return array key => array( 'label' => string, 'clears' => bool ) */
function chl_checkboxes() {
	$out = array();
	foreach ( chl_parse_lines( chl_setting( 'checkboxes' ) ) as $key => $row ) {
		$out[ $key ] = array(
			'label'  => $row['label'],
			'clears' => in_array( 'clear', $row['flags'], true ),
		);
	}
	return $out;
}

function chl_list_url( $args = array() ) {
	return add_query_arg( $args, admin_url( 'edit.php?post_type=' . CHL_CPT ) );
}

/**
 * Forms connected to leads, from every intake adapter.
 *
 * @return array[] Each: id, title, role, edit_url, fields (key => label),
 *                 short (key => Admin Field Label or label).
 */
function chl_connected_forms() {
	return (array) apply_filters( 'chl_connected_forms', array() );
}

/** Every field label that can appear on a lead: from connected forms and from stored leads. */
function chl_known_fields() {
	$known = get_option( 'chl_known_fields', array() );
	$known = is_array( $known ) ? $known : array();
	foreach ( chl_connected_forms() as $form ) {
		foreach ( (array) $form['fields'] as $key => $label ) {
			if ( ! isset( $known[ $key ] ) ) {
				$known[ $key ] = $label;
			}
		}
	}
	asort( $known, SORT_NATURAL | SORT_FLAG_CASE );
	return $known;
}

/**
 * Mapped fields the administrator chose to show as list columns.
 *
 * The heading is the field's Admin Field Label from Gravity Forms when it has
 * one, so long questions can have a short column heading.
 *
 * @return array key => array( 'label' => full label, 'short' => column heading ).
 */
function chl_list_fields() {
	$known = chl_known_fields();
	$short = array();
	foreach ( chl_connected_forms() as $form ) {
		$short += isset( $form['short'] ) ? (array) $form['short'] : array();
	}
	$out = array();
	foreach ( (array) chl_setting( 'list_fields' ) as $key ) {
		if ( isset( $known[ $key ] ) ) {
			$heading     = isset( $short[ $key ] ) && $short[ $key ] !== $known[ $key ] ? $short[ $key ] : $known[ $key ];
			$out[ $key ] = array(
				'label' => $known[ $key ],
				'short' => $heading,
			);
		}
	}
	return $out;
}

// ---------------------------------------------------------------------------
// Settings page
// ---------------------------------------------------------------------------

add_action( 'admin_menu', function () {
	add_options_page(
		__( 'Click Hive Leads', 'clickhive-leads' ),
		__( 'Click Hive Leads', 'clickhive-leads' ),
		'manage_options',
		'chl-settings',
		'chl_render_settings'
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'chl_settings_group', CHL_OPTION, array( 'sanitize_callback' => 'chl_sanitize_settings' ) );
} );

function chl_sanitize_settings( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$out = chl_defaults();

	$stages = sanitize_textarea_field( isset( $in['stages'] ) ? $in['stages'] : '' );
	if ( '' !== trim( $stages ) ) {
		$out['stages'] = $stages;
	}
	$parsed = chl_parse_lines( $out['stages'] );
	$open   = array_filter(
		$parsed,
		function ( $row ) {
			return ! array_intersect( array( 'won', 'lost' ), $row['flags'] );
		}
	);
	if ( ! $open ) {
		add_settings_error( CHL_OPTION, 'chl_open', __( 'Add at least one Open stage. New leads are given the first Open stage.', 'clickhive-leads' ) );
	}

	$out['checkboxes']     = sanitize_textarea_field( isset( $in['checkboxes'] ) ? $in['checkboxes'] : '' );
	$out['list_fields']    = array_values( array_filter( array_map( 'sanitize_key', (array) ( isset( $in['list_fields'] ) ? $in['list_fields'] : array() ) ) ) );
	$out['retention_days'] = min( 3650, absint( isset( $in['retention_days'] ) ? $in['retention_days'] : 0 ) );
	if ( $out['retention_days'] && $out['retention_days'] < 30 ) {
		$out['retention_days'] = 30;
	}
	return $out;
}

function chl_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s     = chl_settings();
	$forms = chl_connected_forms();
	$known = chl_known_fields();
	$name  = CHL_OPTION;
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Click Hive Leads', 'clickhive-leads' ); ?> <span class="chl-version" style="font-size:13px;font-weight:400;color:#646970;"><?php echo esc_html( sprintf( __( 'Version %s', 'clickhive-leads' ), CHL_VERSION ) ); ?></span></h1>
		<?php settings_errors( CHL_OPTION ); ?>

		<h2><?php esc_html_e( 'Connected forms', 'clickhive-leads' ); ?></h2>
		<?php if ( ! class_exists( 'GFForms' ) ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Gravity Forms is not active. Leads are created from Gravity Forms submissions.', 'clickhive-leads' ); ?></p></div>
		<?php elseif ( ! $forms ) : ?>
			<p><?php esc_html_e( 'No forms are connected yet. Open a form in Gravity Forms, go to Settings > Click Hive Leads, and turn on leads.', 'clickhive-leads' ); ?></p>
		<?php else : ?>
			<table class="widefat striped chl-forms" style="max-width:720px">
				<thead><tr><th><?php esc_html_e( 'Form', 'clickhive-leads' ); ?></th><th><?php esc_html_e( 'Role', 'clickhive-leads' ); ?></th><th><?php esc_html_e( 'Copied fields', 'clickhive-leads' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $forms as $f ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $f['edit_url'] ); ?>"><?php echo esc_html( $f['title'] ); ?></a></td>
						<td><?php echo esc_html( 'followup' === $f['role'] ? __( 'Follow-up step', 'clickhive-leads' ) : __( 'Creates or updates a lead', 'clickhive-leads' ) ); ?></td>
						<td><?php echo esc_html( $f['fields'] ? implode( ', ', $f['fields'] ) : __( 'None', 'clickhive-leads' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'chl_settings_group' ); ?>
			<h2><?php esc_html_e( 'Lead list', 'clickhive-leads' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="chl_stages"><?php esc_html_e( 'Stages', 'clickhive-leads' ); ?></label></th>
					<td>
						<textarea id="chl_stages" name="<?php echo esc_attr( $name ); ?>[stages]" rows="7" class="regular-text"><?php echo esc_textarea( $s['stages'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One stage per line, in order. Add "| Won" or "| Lost" after a stage that ends the lead; other stages count as Open. New leads get the first Open stage.', 'clickhive-leads' ); ?></p>
						<p class="description"><?php esc_html_e( 'Removing a line retires it: leads keep it, but it can\'t be picked again.', 'clickhive-leads' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="chl_checkboxes"><?php esc_html_e( 'Checkboxes', 'clickhive-leads' ); ?></label></th>
					<td>
						<textarea id="chl_checkboxes" name="<?php echo esc_attr( $name ); ?>[checkboxes]" rows="4" class="regular-text"><?php echo esc_textarea( $s['checkboxes'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One checkbox per line, shown as a column on the list. Add "| Clear" to uncheck it when the same person sends a form again.', 'clickhive-leads' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Form fields as columns', 'clickhive-leads' ); ?></th>
					<td>
						<?php if ( ! $known ) : ?>
							<p class="description"><?php esc_html_e( 'Fields copied from connected forms can be shown as list columns. None are set up yet.', 'clickhive-leads' ); ?></p>
						<?php else : ?>
							<fieldset>
								<?php foreach ( $known as $key => $label ) : ?>
									<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[list_fields][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $s['list_fields'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Optional. Every copied field always shows on the lead itself and in the export.', 'clickhive-leads' ); ?></p>
							<p class="description"><?php esc_html_e( 'For a long question, give the field an Admin Field Label in Gravity Forms (the field\'s Advanced tab). The column uses it as the heading, and hovering shows the full question.', 'clickhive-leads' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="chl_retention"><?php esc_html_e( 'Keep leads for', 'clickhive-leads' ); ?></label></th>
					<td>
						<input type="number" min="0" max="3650" id="chl_retention" name="<?php echo esc_attr( $name ); ?>[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>" class="small-text"> <?php esc_html_e( 'days', 'clickhive-leads' ); ?>
						<p class="description"><?php esc_html_e( '0 keeps leads forever. Otherwise leads with no new submission in this many days are deleted (30 days minimum).', 'clickhive-leads' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

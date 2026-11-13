<?php
/**
 * The lead screen, built from native meta boxes because checkboxes and copied
 * fields differ per site and come from settings.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'add_meta_boxes_' . CHL_CPT, function ( $post ) {
	remove_meta_box( 'submitdiv', CHL_CPT, 'side' );
	remove_meta_box( 'slugdiv', CHL_CPT, 'normal' );
	add_meta_box( 'chl_lead', __( 'Lead', 'clickhive-leads' ), 'chl_box_lead', CHL_CPT, 'normal', 'high' );
	add_meta_box( 'chl_notes', __( 'Notes', 'clickhive-leads' ), 'chl_box_notes', CHL_CPT, 'normal', 'high' );
	add_meta_box( 'chl_fields', __( 'Form answers', 'clickhive-leads' ), 'chl_box_fields', CHL_CPT, 'normal', 'default' );
	add_meta_box( 'chl_history', __( 'History', 'clickhive-leads' ), 'chl_box_history', CHL_CPT, 'normal', 'low' );
	add_meta_box( 'chl_save', __( 'Save', 'clickhive-leads' ), 'chl_box_save', CHL_CPT, 'side', 'high' );
	add_meta_box( 'chl_source', __( 'Source', 'clickhive-leads' ), 'chl_box_source', CHL_CPT, 'side', 'default' );
} );

// The lead's name as the screen heading.
add_action( 'edit_form_top', function ( $post ) {
	if ( CHL_CPT !== $post->post_type ) {
		return;
	}
	printf(
		'<h2 class="chl-lead-name">%s</h2><p><a href="%s">%s</a></p>',
		esc_html( get_the_title( $post ) ),
		esc_url( chl_list_url() ),
		esc_html__( 'Back to leads', 'clickhive-leads' )
	);
} );

function chl_box_save( $post ) {
	wp_nonce_field( 'chl_edit_' . $post->ID, 'chl_edit_nonce' );
	echo '<input type="hidden" name="post_status" value="publish">';
	echo '<div class="chl-save">';
	submit_button( __( 'Save', 'clickhive-leads' ), 'primary', 'save', false );
	if ( current_user_can( 'delete_post', $post->ID ) ) {
		printf( ' <a class="submitdelete chl-delete" href="%s">%s</a>', esc_url( get_delete_post_link( $post->ID ) ), esc_html__( 'Move to Trash', 'clickhive-leads' ) );
	}
	echo '</div>';
}

function chl_box_lead( $post ) {
	$id     = $post->ID;
	$fields = array(
		'first_name' => __( 'First name', 'clickhive-leads' ),
		'last_name'  => __( 'Last name', 'clickhive-leads' ),
		'email'      => __( 'Email', 'clickhive-leads' ),
		'phone'      => __( 'Phone', 'clickhive-leads' ),
	);
	?>
	<div class="chl-grid">
		<p>
			<label for="chl-stage"><strong><?php esc_html_e( 'Stage', 'clickhive-leads' ); ?></strong></label><br>
			<?php echo chl_stage_select( $id, true, 'chl[stage]' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
		</p>
		<?php if ( chl_checkboxes() ) : ?>
			<fieldset class="chl-boxes">
				<legend class="screen-reader-text"><?php esc_html_e( 'Checkboxes', 'clickhive-leads' ); ?></legend>
				<?php foreach ( chl_checkboxes() as $key => $box ) : ?>
					<label><input type="checkbox" name="chl[cb][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( chl_is_checked( $id, $key ) ); ?>> <?php echo esc_html( $box['label'] ); ?></label>
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>
	</div>
	<table class="form-table chl-contact" role="presentation">
		<?php foreach ( $fields as $key => $label ) : ?>
			<tr>
				<th scope="row"><label for="chl-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
				<td>
					<input type="<?php echo 'email' === $key ? 'email' : ( 'phone' === $key ? 'tel' : 'text' ); ?>" class="regular-text" id="chl-<?php echo esc_attr( $key ); ?>" name="chl[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( 'phone' === $key ? chl_format_phone( chl_get( $id, $key ) ) : chl_get( $id, $key ) ); ?>">
					<?php if ( 'email' === $key && chl_get( $id, 'email' ) ) : ?>
						<a href="mailto:<?php echo esc_attr( chl_get( $id, 'email' ) ); ?>"><?php esc_html_e( 'Email', 'clickhive-leads' ); ?></a>
					<?php elseif ( 'phone' === $key && chl_get( $id, 'phone' ) ) : ?>
						<a href="tel:<?php echo esc_attr( chl_get( $id, 'phone' ) ); ?>"><?php esc_html_e( 'Call', 'clickhive-leads' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Received', 'clickhive-leads' ); ?></th>
			<td><?php echo esc_html( get_the_date( 'M j, Y', $post ) . ' ' . get_the_time( 'g:i a', $post ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'First received', 'clickhive-leads' ); ?></th>
			<td><?php echo esc_html( chl_format_time( chl_get( $id, 'first_received' ) ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Submissions', 'clickhive-leads' ); ?></th>
			<td><?php echo esc_html( (int) chl_get( $id, 'submissions' ) ); ?></td>
		</tr>
	</table>
	<?php
}

function chl_box_notes( $post ) {
	?>
	<p>
		<label for="chl-note" class="screen-reader-text"><?php esc_html_e( 'Add a note', 'clickhive-leads' ); ?></label>
		<textarea id="chl-note" name="chl[note]" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Add a note. It is saved with your name when you press Save.', 'clickhive-leads' ); ?>"></textarea>
	</p>
	<?php
	$notes = array_reverse( chl_notes( $post->ID ) );
	if ( ! $notes ) {
		echo '<p class="chl-muted">' . esc_html__( 'No notes yet.', 'clickhive-leads' ) . '</p>';
		return;
	}
	echo '<ul class="chl-notes">';
	foreach ( $notes as $n ) {
		printf(
			'<li><div class="chl-note-meta">%s</div><div class="chl-note-body">%s</div></li>',
			esc_html( $n['name'] . ', ' . chl_format_time( $n['at'] ) ),
			nl2br( esc_html( $n['text'] ) )
		);
	}
	echo '</ul>';
}

function chl_box_fields( $post ) {
	$fields = chl_fields( $post->ID );
	if ( ! $fields ) {
		echo '<p class="chl-muted">' . esc_html__( 'No form answers are copied to this lead. Choose fields under each form\'s Settings > Click Hive Leads.', 'clickhive-leads' ) . '</p>';
		return;
	}
	$titles = chl_form_titles();
	echo '<table class="widefat striped chl-answers"><tbody>';
	foreach ( $fields as $f ) {
		$form = isset( $titles[ (int) $f['form_id'] ] ) ? $titles[ (int) $f['form_id'] ] : '';
		printf(
			'<tr><th scope="row">%s</th><td>%s<div class="chl-muted">%s</div></td></tr>',
			esc_html( $f['label'] ),
			nl2br( esc_html( $f['value'] ) ),
			esc_html( trim( $form . ', ' . chl_format_time( $f['at'] ), ', ' ) )
		);
	}
	echo '</tbody></table>';
}

function chl_box_source( $post ) {
	$data = chl_get( $post->ID, 'source_data' );
	$data = is_array( $data ) ? $data : array();
	if ( ! $data ) {
		echo '<p class="chl-muted">' . esc_html__( 'Not recorded.', 'clickhive-leads' ) . '</p>';
		return;
	}
	echo '<p><strong>' . esc_html( chl_get( $post->ID, 'source' ) ) . '</strong></p><dl class="chl-source">';
	foreach ( chl_source_labels() as $key => $label ) {
		if ( ! empty( $data[ $key ] ) ) {
			printf( '<dt>%s</dt><dd>%s</dd>', esc_html( $label ), esc_html( $data[ $key ] ) );
		}
	}
	if ( ! empty( $data['at'] ) ) {
		printf( '<dt>%s</dt><dd>%s</dd>', esc_html__( 'First visit', 'clickhive-leads' ), esc_html( chl_format_time( $data['at'] ) ) );
	}
	echo '</dl>';
}

function chl_box_history( $post ) {
	$rows = chl_history( $post->ID );
	if ( ! $rows ) {
		echo '<p class="chl-muted">' . esc_html__( 'No history yet.', 'clickhive-leads' ) . '</p>';
		return;
	}
	$names = array();
	echo '<ul class="chl-history">';
	foreach ( $rows as $r ) {
		$uid = (int) $r['user_id'];
		if ( ! isset( $names[ $uid ] ) ) {
			$u             = $uid ? get_userdata( $uid ) : null;
			$names[ $uid ] = $u ? $u->display_name : '';
		}
		$when = chl_format_time( strtotime( $r['created_at'] . ' UTC' ) );
		printf(
			'<li data-event="%s"><span class="chl-muted">%s</span> %s%s</li>',
			esc_attr( $r['event'] ),
			esc_html( $when ),
			chl_history_text( $r ), // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
			$names[ $uid ] ? ' <span class="chl-muted">(' . esc_html( $names[ $uid ] ) . ')</span>' : ''
		);
	}
	echo '</ul>';
}

/** One history line as HTML. */
function chl_history_text( array $r ) {
	$d     = $r['detail'];
	$entry = function () use ( $d ) {
		if ( empty( $d['entry_id'] ) ) {
			return '';
		}
		/* translators: %d: form entry number */
		$label = sprintf( __( 'entry %d', 'clickhive-leads' ), (int) $d['entry_id'] );
		if ( ! empty( $d['entry_url'] ) && current_user_can( 'gravityforms_view_entries' ) ) {
			return ' (<a href="' . esc_url( $d['entry_url'] ) . '">' . esc_html( $label ) . '</a>)';
		}
		return ' (' . esc_html( $label ) . ')';
	};
	$form  = isset( $d['form_title'] ) ? $d['form_title'] : '';
	$why   = isset( $d['reason'] ) && 'returned' === $d['reason'] ? ' ' . esc_html__( 'because they sent a form again', 'clickhive-leads' ) : '';

	switch ( $r['event'] ) {
		case 'created':
			/* translators: %s: form name */
			return esc_html( sprintf( __( 'Lead created from %s', 'clickhive-leads' ), $form ) );
		case 'submission':
			$text = sprintf( __( 'Sent %s', 'clickhive-leads' ), $form );
			if ( ! empty( $d['source'] ) ) {
				$text .= ' ' . sprintf( __( 'from %s', 'clickhive-leads' ), $d['source'] );
			}
			return esc_html( $text ) . $entry();
		case 'followup':
			return esc_html( sprintf( __( 'Follow-up step: %s', 'clickhive-leads' ), $form ) ) . $entry();
		case 'stage':
			if ( empty( $d['from'] ) ) {
				return esc_html( sprintf( __( 'Stage set to %s', 'clickhive-leads' ), $d['label'] ) );
			}
			return esc_html( sprintf( __( 'Stage changed from %1$s to %2$s', 'clickhive-leads' ), $d['from_label'], $d['label'] ) ) . $why;
		case 'checkbox':
			$label = isset( $d['label'] ) ? $d['label'] : $r['value'];
			return esc_html( 'on' === $r['category'] ? sprintf( __( 'Checked %s', 'clickhive-leads' ), $label ) : sprintf( __( 'Unchecked %s', 'clickhive-leads' ), $label ) ) . $why;
		case 'note':
			return esc_html__( 'Added a note', 'clickhive-leads' );
		case 'edit':
			return esc_html( sprintf( __( 'Edited %s', 'clickhive-leads' ), implode( ', ', isset( $d['fields'] ) ? (array) $d['fields'] : array() ) ) );
	}
	return esc_html( $r['event'] );
}

// Saving the lead screen. Every change goes through the same functions as
// the list, so it lands in the history.
add_action( 'save_post_' . CHL_CPT, function ( $post_id ) {
	if ( ! isset( $_POST['chl_edit_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['chl_edit_nonce'] ), 'chl_edit_' . $post_id ) ) {
		return;
	}
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$in = isset( $_POST['chl'] ) && is_array( $_POST['chl'] ) ? wp_unslash( $_POST['chl'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field below

	$stage = isset( $in['stage'] ) ? sanitize_key( $in['stage'] ) : '';
	if ( $stage && isset( chl_stages()[ $stage ] ) ) {
		chl_set_stage( $post_id, $stage );
	}

	$checked = isset( $in['cb'] ) && is_array( $in['cb'] ) ? $in['cb'] : array();
	foreach ( array_keys( chl_checkboxes() ) as $key ) {
		chl_set_checkbox( $post_id, $key, ! empty( $checked[ $key ] ) );
	}

	$labels  = array(
		'first_name' => __( 'first name', 'clickhive-leads' ),
		'last_name'  => __( 'last name', 'clickhive-leads' ),
		'email'      => __( 'email', 'clickhive-leads' ),
		'phone'      => __( 'phone', 'clickhive-leads' ),
	);
	$changed = array();
	foreach ( $labels as $key => $label ) {
		if ( ! isset( $in[ $key ] ) || ! is_string( $in[ $key ] ) ) {
			continue;
		}
		$value = 'email' === $key ? strtolower( sanitize_email( $in[ $key ] ) ) : sanitize_text_field( $in[ $key ] );
		if ( 'phone' === $key ) {
			$value = chl_normalize_phone( $value ) ? chl_normalize_phone( $value ) : $value;
		}
		if ( (string) chl_get( $post_id, $key ) !== $value ) {
			chl_set( $post_id, $key, $value );
			$changed[] = $label;
		}
	}
	if ( $changed ) {
		chl_log( $post_id, 'edit', '', '', array( 'fields' => $changed ) );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_title' => chl_title_from( array( 'first_name' => chl_get( $post_id, 'first_name' ), 'last_name' => chl_get( $post_id, 'last_name' ), 'email' => chl_get( $post_id, 'email' ), 'phone' => chl_get( $post_id, 'phone' ) ) ) ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $post_id );
	}

	if ( isset( $in['note'] ) && is_string( $in['note'] ) ) {
		chl_add_note( $post_id, $in['note'] );
	}
} );

// After saving, come back to the same lead with a plain "Lead saved." notice.
add_filter( 'redirect_post_location', function ( $location, $post_id ) {
	return CHL_CPT === get_post_type( $post_id ) ? add_query_arg( 'message', 1, chl_lead_url( $post_id ) ) : $location;
}, 10, 2 );

add_filter( 'post_updated_messages', function ( $messages ) {
	$messages[ CHL_CPT ] = array_fill( 0, 11, __( 'Lead saved.', 'clickhive-leads' ) );
	$messages[ CHL_CPT ][0] = '';
	return $messages;
} );

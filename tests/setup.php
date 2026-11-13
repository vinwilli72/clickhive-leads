<?php
/**
 * Test fixture for tests/e2e.js. Run with wp-cli against a throwaway site:
 *   wp eval-file tests/setup.php
 *
 * Creates four Gravity Forms forms, a page for each, three users and a
 * must-use plugin that catches outgoing email and marks test spam. Safe to
 * run again: it removes what it made last time first.
 */

if ( ! class_exists( 'GFAPI' ) ) {
	WP_CLI::error( 'Gravity Forms must be active.' );
}

// Start clean: forms, pages, leads, history, settings.
foreach ( GFAPI::get_forms( null ) as $f ) {
	GFAPI::delete_form( $f['id'] );
}
foreach ( get_posts( array( 'post_type' => array( 'page', CHL_CPT ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}
global $wpdb;
$wpdb->query( 'TRUNCATE TABLE ' . chl_history_table() ); // phpcs:ignore
delete_option( CHL_OPTION );
delete_option( 'chl_known_fields' );
delete_option( 'chl_test_mails' );
update_option( 'gform_pending_installation', false ); // skip the Gravity Forms setup wizard

// Must-use plugin: catch mail, and treat any entry with "spammer@" as spam.
$mu = WP_CONTENT_DIR . '/mu-plugins';
wp_mkdir_p( $mu );
file_put_contents(
	$mu . '/chl-test-helpers.php',
	'<?php
// Test helpers for Click Hive Leads end-to-end tests. Not for real sites.
add_filter( "pre_wp_mail", function ( $null, $atts ) {
	$mails   = get_option( "chl_test_mails", array() );
	$mails[] = array( "to" => $atts["to"], "subject" => $atts["subject"], "message" => $atts["message"] );
	update_option( "chl_test_mails", $mails, false );
	return true;
}, 10, 2 );
add_filter( "gform_entry_is_spam", function ( $is_spam, $form, $entry ) {
	return false !== strpos( wp_json_encode( $entry ), "spammer@" ) ? true : $is_spam;
}, 10, 3 );
'
);

function chl_test_page( $title, $slug, $content ) {
	return wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => $content ) );
}

function chl_test_form( $title, $fields, $notification = '' ) {
	$form = array(
		'title'          => $title,
		'fields'         => $fields,
		'button'         => array( 'type' => 'text', 'text' => 'Submit' ),
		'confirmations'  => array(
			'c1' => array( 'id' => 'c1', 'name' => 'Default', 'isDefault' => true, 'type' => 'message', 'message' => 'Thanks, we got it.' ),
		),
		'notifications'  => array(),
		'labelPlacement' => 'top_label',
	);
	if ( $notification ) {
		$form['notifications']['n1'] = array(
			'id'       => 'n1',
			'name'     => 'Admin',
			'isActive' => true,
			'event'    => 'form_submission',
			'to'       => 'office@example.com',
			'toType'   => 'email',
			'subject'  => 'New lead',
			'message'  => $notification,
		);
	}
	$id = GFAPI::add_form( $form );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id );
	}
	return (int) $id;
}

$name_field = array(
	'id'         => 1,
	'type'       => 'name',
	'label'      => 'Name',
	'nameFormat' => 'advanced',
	'isRequired' => true,
	'inputs'     => array(
		array( 'id' => '1.2', 'label' => 'Prefix', 'isHidden' => true ),
		array( 'id' => '1.3', 'label' => 'First' ),
		array( 'id' => '1.4', 'label' => 'Middle', 'isHidden' => true ),
		array( 'id' => '1.6', 'label' => 'Last' ),
		array( 'id' => '1.8', 'label' => 'Suffix', 'isHidden' => true ),
	),
);

// 1. Contact: creates leads, then sends people to the Details page.
$contact = chl_test_form(
	'Contact',
	array(
		$name_field,
		array( 'id' => 2, 'type' => 'email', 'label' => 'Email' ),
		array( 'id' => 3, 'type' => 'phone', 'label' => 'Phone', 'phoneFormat' => 'standard' ),
		array( 'id' => 4, 'type' => 'select', 'label' => 'Service', 'choices' => array( array( 'text' => 'Repair', 'value' => 'Repair' ), array( 'text' => 'Install', 'value' => 'Install' ) ) ),
		array( 'id' => 5, 'type' => 'textarea', 'label' => 'Message' ),
	),
	'Lead: {ch_lead_link} All: {ch_leads_url}'
);

// 2. Details: the follow-up step.
$details = chl_test_form(
	'Details',
	array(
		array( 'id' => 1, 'type' => 'text', 'label' => 'Budget' ),
		array( 'id' => 2, 'type' => 'select', 'label' => 'Timeline', 'choices' => array( array( 'text' => 'This month', 'value' => 'This month' ), array( 'text' => 'Later', 'value' => 'Later' ) ) ),
		array( 'id' => 3, 'type' => 'text', 'label' => 'Do you have previous musical experience?' ),
		array( 'id' => 4, 'type' => 'text', 'label' => 'Will you be the student, or is this for someone else?', 'adminLabel' => 'Student' ),
	),
	'Follow-up for {ch_lead_link}'
);

// 3. Quote: creates leads, single full-name field, email optional.
$quote = chl_test_form(
	'Quote',
	array(
		array( 'id' => 1, 'type' => 'text', 'label' => 'Full name' ),
		array( 'id' => 2, 'type' => 'email', 'label' => 'Email' ),
		array( 'id' => 3, 'type' => 'phone', 'label' => 'Phone', 'phoneFormat' => 'standard' ),
		array( 'id' => 4, 'type' => 'text', 'label' => 'Service' ),
	)
);

// 4. Newsletter: not connected to leads.
$news = chl_test_form( 'Newsletter', array( array( 'id' => 1, 'type' => 'email', 'label' => 'Email' ) ) );

$p_contact = chl_test_page( 'Contact', 'contact', '[gravityform id="' . $contact . '" title="false" ajax="false"]' );
$p_details = chl_test_page( 'Details', 'details', '[gravityform id="' . $details . '" title="false" ajax="false"]' );
$p_quote   = chl_test_page( 'Quote', 'quote', '[gravityform id="' . $quote . '" title="false" ajax="true"]' );
$p_news    = chl_test_page( 'Newsletter', 'newsletter', '[gravityform id="' . $news . '" title="false" ajax="false"]' );

$settings = array(
	$contact => array( 'enabled' => '1', 'role' => 'create', 'map_first_name' => '1.3', 'map_last_name' => '1.6', 'map_email' => '2', 'map_phone' => '3', 'copy_4' => '1', 'followup_page' => (string) $p_details ),
	$details => array( 'enabled' => '1', 'role' => 'followup', 'copy_1' => '1', 'copy_2' => '1', 'copy_3' => '1', 'copy_4' => '1', 'start_page' => (string) $p_contact ),
	$quote   => array( 'enabled' => '1', 'role' => 'create', 'map_name' => '1', 'map_email' => '2', 'map_phone' => '3', 'copy_4' => '1' ),
);
foreach ( $settings as $form_id => $s ) {
	$form                    = GFAPI::get_form( $form_id );
	$form['clickhive-leads'] = $s;
	GFAPI::update_form( $form );
}

// Users. The "client" role stands in for Click Hive Utility's Client Access
// role, created with no lead abilities so the plugin has to grant them.
remove_role( 'client' );
add_role( 'client', 'Client', array( 'read' => true ) );
foreach (
	array(
		'lisa' => 'chl_lead_manager',
		'carl' => 'client',
		'sam'  => 'subscriber',
	) as $login => $role
) {
	$user = get_user_by( 'login', $login );
	if ( $user ) {
		wp_delete_user( $user->ID );
	}
	wp_insert_user( array( 'user_login' => $login, 'user_pass' => 'testpass123', 'user_email' => $login . '@example.com', 'display_name' => ucfirst( $login ), 'role' => $role ) );
}

echo wp_json_encode(
	array(
		'forms' => array( 'contact' => $contact, 'details' => $details, 'quote' => $quote, 'news' => $news ),
		'pages' => array( 'contact' => $p_contact, 'details' => $p_details, 'quote' => $p_quote, 'news' => $p_news ),
	)
);

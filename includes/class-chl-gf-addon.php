<?php
/**
 * The "Click Hive Leads" tab in each Gravity Forms form's settings, built on
 * the Gravity Forms add-on framework. Settings are stored on the form itself
 * under $form['clickhive-leads'].
 */

defined( 'ABSPATH' ) || exit;

class CHL_GF_AddOn extends GFAddOn {

	protected $_version                  = CHL_VERSION;
	protected $_min_gravityforms_version = '2.5';
	protected $_slug                     = 'clickhive-leads';
	protected $_path                     = 'clickhive-leads/clickhive-leads.php';
	protected $_full_path                = CHL_FILE;
	protected $_title                    = 'Click Hive Leads';
	protected $_short_title              = 'Click Hive Leads';

	private static $_instance = null;

	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function get_menu_icon() {
		$svg = file_get_contents( CHL_DIR . 'assets/menu-icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $svg ? $svg : 'gform-icon--cog';
	}

	/** Fields that hold no answer and can't be copied. */
	public static function skip_types() {
		return array( 'html', 'section', 'page', 'captcha', 'honeypot', 'password' );
	}

	public function form_settings_fields( $form ) {
		$enabled = array(
			'live'   => true,
			'fields' => array( array( 'field' => 'enabled' ) ),
		);

		$role_is = function ( $role ) {
			return array(
				'live'   => true,
				'fields' => array(
					array( 'field' => 'enabled' ),
					array(
						'field'  => 'role',
						'values' => array( $role ),
					),
				),
			);
		};

		$copy = array();
		foreach ( (array) rgar( $form, 'fields' ) as $field ) {
			if ( in_array( $field->type, self::skip_types(), true ) ) {
				continue;
			}
			$copy[] = array(
				'name'  => 'copy_' . $field->id,
				'label' => GFCommon::get_label( $field ),
			);
		}

		$pages = array(
			array(
				'value' => '',
				'label' => __( 'None', 'clickhive-leads' ),
			),
		);
		foreach ( get_pages( array( 'post_status' => 'publish' ) ) as $page ) {
			$pages[] = array(
				'value' => (string) $page->ID,
				'label' => $page->post_title ? $page->post_title : '#' . $page->ID,
			);
		}

		$person = array();
		foreach (
			array(
				'name'       => array( __( 'Name', 'clickhive-leads' ), __( 'A Name field, or one text field with the full name. Not needed when first and last name are mapped.', 'clickhive-leads' ) ),
				'first_name' => array( __( 'First name', 'clickhive-leads' ), '' ),
				'last_name'  => array( __( 'Last name', 'clickhive-leads' ), '' ),
				'email'      => array( __( 'Email', 'clickhive-leads' ), __( 'Used to recognize someone who sends a form again.', 'clickhive-leads' ) ),
				'phone'      => array( __( 'Phone', 'clickhive-leads' ), __( 'Used to recognize someone when there is no email.', 'clickhive-leads' ) ),
			) as $key => $text
		) {
			$person[] = array(
				'name'         => 'map_' . $key,
				'label'        => $text[0],
				'type'         => 'field_select',
				'description'  => $text[1],
				'auto_mapping' => 'name' !== $key,
				'args'         => array( 'append_choices' => array() ),
			);
		}

		return array(
			array(
				'title'  => __( 'Click Hive Leads', 'clickhive-leads' ),
				'fields' => array(
					array(
						'name'  => 'enabled',
						'label' => __( 'Save submissions from this form as leads', 'clickhive-leads' ),
						'type'  => 'toggle',
					),
					array(
						'name'          => 'role',
						'label'         => __( 'Form role', 'clickhive-leads' ),
						'type'          => 'radio',
						'default_value' => 'create',
						'horizontal'    => false,
						'dependency'    => $enabled,
						'choices'       => array(
							array(
								'value' => 'create',
								'label' => __( 'Creates or updates a lead', 'clickhive-leads' ),
							),
							array(
								'value' => 'followup',
								'label' => __( 'Follow-up step (adds to the lead from an earlier form)', 'clickhive-leads' ),
							),
						),
					),
				),
			),
			array(
				'title'       => __( 'Contact fields', 'clickhive-leads' ),
				'description' => __( 'Pick the field that holds each detail.', 'clickhive-leads' ),
				'dependency'  => $enabled,
				'fields'      => $person,
			),
			array(
				'title'       => __( 'Other fields to copy', 'clickhive-leads' ),
				'description' => __( 'Checked fields show on the lead under their labels here. For healthcare clients, leave free-text fields unchecked.', 'clickhive-leads' ),
				'dependency'  => $enabled,
				'fields'      => array(
					$copy ? array(
						'name'    => 'copy',
						'label'   => __( 'Copy to the lead', 'clickhive-leads' ),
						'type'    => 'checkbox',
						'choices' => $copy,
					) : array(
						'name' => 'copy_none',
						'type' => 'html',
						'html' => esc_html__( 'This form has no fields to copy yet.', 'clickhive-leads' ),
					),
				),
			),
			array(
				'title'      => __( 'Two-step forms', 'clickhive-leads' ),
				'dependency' => $enabled,
				'fields'     => array(
					array(
						'name'        => 'followup_page',
						'label'       => __( 'Follow-up page', 'clickhive-leads' ),
						'type'        => 'select',
						'choices'     => $pages,
						'dependency'  => $role_is( 'create' ),
						'description' => __( 'Optional. After submitting, send the visitor to this page, which holds the follow-up form. A private link connects the two.', 'clickhive-leads' ),
					),
					array(
						'name'        => 'start_page',
						'label'       => __( 'First step page', 'clickhive-leads' ),
						'type'        => 'select',
						'choices'     => $pages,
						'dependency'  => $role_is( 'followup' ),
						'description' => __( 'Visitors who arrive without the private link are pointed back to this page.', 'clickhive-leads' ),
					),
				),
			),
		);
	}
}

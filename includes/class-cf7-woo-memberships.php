<?php
/**
 * Contact Form 7 → WooCommerce Memberships
 *
 * @package         CF7_Woo_Memberships
 * @author          AndrewRMinion Design
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contact Form 7 WooCommerce Memberships
 *
 * Adds Contact Form 7 metabox to set the plan ID and field mappings, and then creates users (if necessary) and grants them membership
 *
 * @package         CF7_Woo_Memberships
 */
class CF7_Woo_Memberships {
	/**
	 * Data fields
	 *
	 * @var array
	 */
	public $data_fields = array(
		'first-name'    => 'First Name',
		'last-name'     => 'Last Name',
		'email-address' => 'Email Address',
		'membership-id' => 'Membership Plan ID',
	);

	/**
	 * Form settings
	 *
	 * @var array
	 */
	private $form_settings = array();

	/**
	 * Kick things off
	 *
	 * @private
	 */
	function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load' ) );
	}

	/**
	 * Check if required plugins are active and if so, load everything
	 */
	function load() {
		if ( class_exists( 'WPCF7' ) || class_exists( 'WC_Memberships' ) ) {
			add_action( 'wpcf7_add_meta_boxes', array( $this, 'register_metabox' ) );
			add_filter( 'wpcf7_editor_panels', array( $this, 'register_cf7_panel' ) );

			add_action( 'wpcf7_save_contact_form', array( $this, 'save_contact_form' ), 10, 3 );
			add_action( 'wpcf7_before_send_mail', array( $this, 'generate_membership' ), 10, 1 );

			add_action( 'plugins_loaded', array( $this, 'load_plugin' ) );
		} else {
			add_action( 'admin_notices', array( $this, 'add_dependencies_notice' ) );
		}
	}

	/**
	 * Add plugin dependencies notice
	 */
	public function add_dependencies_notice() {
		echo '<div class="notice notice-error">
			<p><strong>Contact Form 7 → WooCommerce Memberships</strong> cannot run because Contact Form 7 and/or WooCommerce Memberships is not active. Please activate both plugins to dismiss this notice.</p>
		</div>';
	}

	/**
	 * Register CF7 metabox
	 */
	public function register_metabox() {
		add_meta_box(
			'cf7s-subject',
			'WooCommerce Memberships',
			array( $this, 'print_metabox' ),
			null,
			'form',
			'low'
		);
	}

	/**
	 * Add CF7 settings panel
	 *
	 * @param array $panels All registered CF7 settings panels.
	 */
	public function register_cf7_panel( $panels ) {
		$form    = WPCF7_ContactForm::get_current();
		$post_id = $form->id();

		$panels['cf7-woo-panel'] = array(
			'title'    => 'WooCommerce Memberships',
			'callback' => array( $this, 'print_metabox' ),
		);

		return $panels;
	}

	/**
	 * Display settings panel content
	 *
	 * @param object $contact_form WPCF7_ContactForm object for the current form.
	 */
	public function print_metabox( $contact_form ) {
		if ( empty( $this->form_settings ) ) {
			$this->get_form_settings( $contact_form->id() );
		}

		$rows = array();

		// Get all WPCF7 fields.
		$wpcf7_form_tags       = WPCF7_FormTagsManager::get_instance();
		$field_types_to_ignore = array( 'recaptcha', 'clear', 'submit' );
		$form_fields           = array();
		foreach ( $wpcf7_form_tags->get_scanned_tags() as $this_field ) {
			if ( ! in_array( $this_field['type'], $field_types_to_ignore ) ) {
				$form_fields[] = $this_field['name'];
			}
		}

		// Add one row for the membership ID.
		$rows[] = sprintf(
			'<tr class="cf7-woocommerce-memberships-field-%1$s">
				<th>
					<label for="cf7-woocommerce-memberships[%1$s]">%2$s</label><br/>
				</th>
				<td><select name="cf7-woocommerce-memberships[%1$s]">%3$s</select></td>
			</tr>',
			'membership-id',
			'Membership Plan ID',
			$this->get_membership_plans_options()
		);

		// Add a row for each data field.
		foreach ( $form_fields as $field ) {
			$rows[] = sprintf(
				'<tr class="cf7-woocommerce-memberships-field-%1$s">
					<th>
						<label for="cf7-woocommerce-memberships[fields][%1$s]"><code>%1$s</code> field</label><br/>
					</th>
					<td><select name="cf7-woocommerce-memberships[fields][%1$s]">%2$s</select></td>
				</tr>',
				$field,
				$this->get_data_fields_options( $field )
			);
		}

		// Output fields list.
		printf(
			'<p class="cf7-woocommerce-memberships-message"></p>
			<p>Choose which fields contain user information:</p>
			<table class="form-table cf7-woocommerce-memberships-table">
				%1$s
			</table>',
			implode( '', $rows )
		);

	}

	/**
	 * Save custom form settings
	 *
	 * @param  object $contact_form WPCF7_ContactForm object.
	 * @param  array  $args         contact form args.
	 * @param  string $context      save/update?.
	 * @return boolean Whether post meta was updated or not.
	 */
	public function save_contact_form( $contact_form, $args, $context ) {
		if ( ! isset( $_POST ) || ! array_key_exists( 'cf7-woocommerce-memberships', $_POST ) ) {
			return;
		}

		$post_id = $contact_form->id();

		if ( ! $post_id ) {
			return;
		}

		if ( isset( $_POST['cf7-woocommerce-memberships'] ) ) {
			$form_settings = array();

			foreach ( $_POST['cf7-woocommerce-memberships']['fields'] as $data_field => $cf7_field ) {
				if ( in_array( $cf7_field, array_keys( $this->data_fields ) ) ) {
					if ( 'membership-id' === $data_field ) {
						$form_settings['membership-id'] = esc_attr( $cf7_field );
					} else {
						$form_settings['fields'][ $data_field ] = esc_attr( $cf7_field );
					}
				}
			}

			// Set Membership ID if not set by a specific field.
			if ( ! isset( $form_settings['membership-id'] ) ) {
				$form_settings['membership-id'] = esc_attr( $_POST['cf7-woocommerce-memberships']['membership-id'] );
			}

			return update_post_meta( $post_id, '_cf7_woo_memberships', maybe_serialize( $form_settings ) );
		}
	}

	/**
	 * Get this form’s settings
	 *
	 * @param  integer $form_id WP post ID.
	 */
	private function get_form_settings( $form_id ) {
		$this->form_settings = maybe_unserialize( get_post_meta( $form_id, '_cf7_woo_memberships', true ) );
		return $this->form_settings;
	}

	/**
	 * Build <options> list of all membership plans
	 *
	 * @return string HTML <options>
	 */
	private function get_membership_plans_options() {
		$plans_options = '<option value="">&mdash; Select One or Choose a Field Below&mdash;</option>';

		$membership_plans = new WC_Memberships_Membership_Plans();
		foreach ( $membership_plans->get_membership_plans() as $plan ) {
			$plans_options .= '<option value="' . $plan->id . '"' . selected( $this->form_settings['membership-id'], $plan->id, false ) . '>' . $plan->name . '</option>';
		}

		return $plans_options;
	}

	/**
	 * Build <options> list of available data fields
	 *
	 * @param  string $field Field name.
	 * @return string HTML <options>
	 */
	private function get_data_fields_options( $field ) {
		$fields_options = '<option value="ignore">Ignore This Field</option>';
		foreach ( $this->data_fields as $key => $value ) {
			$fields_options .= '<option value="' . $key . '"' . selected( $this->form_settings['fields'][ $field ], $key, false ) . '>' . $value . '</option>';
		}

		return $fields_options;
	}

	/**
	 * Create user membership
	 *
	 * @return object WC_Memberships_User_Membership
	 */
	public function generate_membership() {
		$submission = WPCF7_Submission::get_instance();
		if ( $submission ) {
			$posted_data = $submission->get_posted_data();
		}
		$form_settings = $this->get_form_settings( $posted_data['_wpcf7'] );

		if ( ! empty( $form_settings ) ) {
			// Get user data.
			if ( is_user_logged_in() ) {
				$user_id = get_current_user_id();
			} else {
				$get_by_email = get_user_by( 'email', $posted_data[ $form_settings['fields']['email-address'] ] );
				if ( ! empty( $get_by_email ) ) {
					$user_id = $get_by_email->ID;
				} else {
					foreach ( $form_settings['fields'] as $user_field => $submission_field ) {
						$user_data[ $user_field ] = esc_attr( $posted_data[ $submission_field ] );
					}

					$user_args = array(
						'user_login' => $user_data['email-address'],
						'first_name' => $user_data['first-name'],
						'last_name'  => $user_data['last-name'],
						'user_email' => $user_data['email-address'],
					);

					$user_id = wp_insert_user( $user_args );
				}
			}

			$membership_data = array(
				'plan_id' => $form_settings['membership-id'],
				'user_id' => $user_id,
			);

			$existing_membership = wc_memberships_get_user_membership( $membership_data['user_id'], $membership_data['plan_id'] );

			// Check for an existing membership.
			if ( isset( $existing_membership->id ) ) {
				return true;
			} else {
				return wc_memberships_create_user_membership( $membership_data );
			}
		}
	}

}

new CF7_Woo_Memberships();

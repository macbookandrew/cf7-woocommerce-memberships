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
	 * Plugin version
	 *
	 * @var string
	 */
	private $version = '1.3.0';

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
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load' ) );
	}

	/**
	 * Check if required plugins are active and if so, load everything
	 */
	public function load() {
		if ( class_exists( 'WPCF7' ) || class_exists( 'WC_Memberships' ) ) {
			add_action( 'wpcf7_add_meta_boxes', array( $this, 'register_metabox' ) );
			add_filter( 'wpcf7_editor_panels', array( $this, 'register_cf7_panel' ) );

			add_action( 'wpcf7_save_contact_form', array( $this, 'save_contact_form' ), 10, 3 );
			add_action( 'wpcf7_before_send_mail', array( $this, 'generate_membership' ), 10, 1 );

			add_action( 'plugins_loaded', array( $this, 'load_plugin' ) );

			add_action( 'admin_enqueue_scripts', array( $this, 'register_admin_assets' ) );
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
	 * Register admin assets
	 */
	public function register_admin_assets() {
		wp_register_script( 'cf7-woocommerce-memberships', plugin_dir_url( __FILE__ ) . '../dist/js/backend.min.js', array( 'jquery' ), $this->version, true );
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
		wp_enqueue_script( 'cf7-woocommerce-memberships' );

		if ( empty( $this->form_settings ) ) {
			$this->get_form_settings( $contact_form->id() );
		}

		$rows = array();

		$common_attributes = array(
			'class' => array(),
		);

		$input_attributes = array(
			'checked' => array( 'checked' ),
			'class'   => array(),
			'name'    => array(),
			'type'    => array( 'checkbox', 'text', 'value' ),
		);

		$form_elements = array(
			'p'      => array(),
			'table'  => array(),
			'tr'     => $common_attributes,
			'th'     => array( 'colspan' => array() ),
			'td'     => array( 'colspan' => array() ),
			'label'  => array( 'for' ),
			'input'  => $input_attributes,
			'select' => $input_attributes,
			'option' => array(
				'selected' => array(),
				'value'    => array(),
			),
		);

		// Get all WPCF7 fields.
		$wpcf7_form_tags       = WPCF7_FormTagsManager::get_instance();
		$field_types_to_ignore = array( 'recaptcha', 'clear', 'submit' );
		$form_fields           = array();
		foreach ( $wpcf7_form_tags->get_scanned_tags() as $this_field ) {
			if ( ! in_array( $this_field['type'], $field_types_to_ignore, true ) ) {
				$form_fields[] = $this_field['name'];
			}
		}

		if ( array_key_exists( 'ignore-form', $this->form_settings ) ) {
			$ignore_form = $this->form_settings['ignore-form'];
		} else {
			$ignore_form = null;
		}

		// Add one row for an ignore checkbox.
		$rows[] = sprintf(
			'<tr class="cf7-woocommerce-memberships-field-%1$s">
				<th>
					<label for="cf7-woocommerce-memberships[%1$s]">%2$s</label><br/>
				</th>
				<td><input type="checkbox" name="cf7-woocommerce-memberships[%1$s]" value="true" ' . checked( $ignore_form, true, false ) . '></td>
			</tr>',
			'ignore-form',
			'Ignore this form'
		);

		// Add one row for instructions.
		$rows[] = '<tr class="cf7-woocommerce-memberships-field-instructions">
				<td colspan="2">Choose a membership plan and mode, and assign the fields which contain user information:</td>
			</tr>';

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

		// Add one row for the membership mode.
		$rows[] = sprintf(
			'<tr class="cf7-woocommerce-memberships-field-%1$s">
				<th>
					<label for="cf7-woocommerce-memberships[%1$s]">%2$s</label><br/>
				</th>
					<td><select name="cf7-woocommerce-memberships[%1$s]">%3$s</select></td>
			</tr>',
			'membership-mode',
			'Membership Plan Mode',
			$this->get_membership_plans_mode()
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
		printf( // WPCS: XSS ok.
			'<p class="cf7-woocommerce-memberships-message"></p>
			<table class="form-table cf7-woocommerce-memberships-table">
				%1$s
			</table>%2$s',
			wp_kses( implode( '', $rows ), $form_elements ),
			wp_nonce_field( '_cf7wcm_data', 'cf7-woocommerce-memberships-nonce', true, false )
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
		if ( ! isset( $_POST ) || ! wp_verify_nonce( $_POST['cf7-woocommerce-memberships-nonce'], '_cf7wcm_data' ) || ! array_key_exists( 'cf7-woocommerce-memberships', $_POST ) ) { // WPCS: input var ok.
			return;
		}

		$post_id = $contact_form->id();

		if ( ! $post_id ) {
			return;
		}

		if ( isset( $_POST['cf7-woocommerce-memberships'] ) ) { // WPCS: input var ok.
			$form_settings = array();

			// Set ignore option.
			if ( in_array( esc_attr( $_POST['cf7-woocommerce-memberships']['ignore-form'] ), array( 'on', 'true' ), true ) ) { // WPCS: XSS ok.
				$form_settings['ignore-form'] = true;
			} else {
				foreach ( $_POST['cf7-woocommerce-memberships']['fields'] as $data_field => $cf7_field ) {
					if ( in_array( $cf7_field, array_keys( $this->data_fields ), true ) ) {
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

				// Set membership mode.
				$form_settings['membership-mode'] = esc_attr( $_POST['cf7-woocommerce-memberships']['membership-mode'] );
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

		if ( array_key_exists( 'membership-id', $this->form_settings ) ) {
			$membership_id = $this->form_settings['membership-id'];
		} else {
			$membership_id = null;
		}

		$membership_plans = new WC_Memberships_Membership_Plans();
		foreach ( $membership_plans->get_membership_plans() as $plan ) {
			$plans_options .= '<option value="' . $plan->id . '"' . selected( $membership_id, $plan->id, false ) . '>' . $plan->name . '</option>';
		}

		return $plans_options;
	}

	/**
	 * Build <options> list of available membership modes
	 *
	 * @since  1.3.0
	 * @return string HTML <options>
	 */
	private function get_membership_plans_mode() {
		$plan_mode = '';

		if ( array_key_exists( 'membership-mode', $this->form_settings ) ) {
			$membership_mode = $this->form_settings['membership-mode'];
		} else {
			$membership_mode = null;
		}

		$memberships = new WC_Memberships_User_Memberships();
		foreach ( $memberships->get_user_membership_statuses() as $key => $value ) {
			$plan_mode .= '<option value="' . $key . '"' . selected( $membership_mode, $key, false ) . '>' . $value['label'] . '</option>';
		}

		return $plan_mode;
	}

	/**
	 * Build <options> list of available data fields
	 *
	 * @param  string $field Field name.
	 * @return string HTML <options>
	 */
	private function get_data_fields_options( $field ) {
		if ( array_key_exists( 'fields', $this->form_settings ) && array_key_exists( $field, $this->form_settings['fields'] ) ) {
			$form_field = $this->form_settings['fields'][ $field ];
		} else {
			$form_field = null;
		}

		$fields_options = '<option value="ignore">Ignore This Field</option>';
		foreach ( $this->data_fields as $key => $value ) {
			$fields_options .= '<option value="' . $key . '"' . selected( $form_field, $key, false ) . '>' . $value . '</option>';
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

		// Continue if we have CF7-WCM settings.
		if ( ! empty( $form_settings ) && true !== $form_settings['ignore-form'] && ! empty( $form_settings['membership-id'] ) ) {
			// Check to see if we need to create a user.
			if ( is_user_logged_in() ) {
				// User is logged in; we can use their account.
				// FUTURE: update account with newly-submitted info?
				$user_id = get_current_user_id();
			} else {
				// User is logged out; we should check to see if they have an account.
				$form_fields  = array_flip( $form_settings['fields'] );
				$get_by_email = get_user_by( 'email', $posted_data[ $form_fields['email-address'] ] );
				if ( ! empty( $get_by_email ) ) {
					// User has an account; we’ll use that account for the membership.
					// FUTURE: update account with newly-submitted info?
					$user_id = $get_by_email->ID;
				} else {
					// User does not have an account; we need to create one.
					foreach ( $form_settings['fields'] as $submission_field => $user_field ) {
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

			// Set up membership data.
			$membership_data = array(
				'plan_id' => $form_settings['membership-id'],
				'user_id' => $user_id,
			);

			// Check for an existing membership.
			$existing_membership = wc_memberships_get_user_membership( $membership_data['user_id'], $membership_data['plan_id'] );
			if ( isset( $existing_membership->id ) ) {
				return true;
			} else {
				// Set the membership status.
				add_filter( 'wc_memberships_new_membership_data', array( $this, 'set_membership_status' ) );

				// Create the new membership.
				return wc_memberships_create_user_membership( $membership_data );
			}
		}
	}

	/**
	 * Set new membership status.
	 *
	 * @param  array $new_membership_data WP post arguments.
	 * @return array Modified post arguments.
	 * @since  1.3.0
	 */
	public function set_membership_status( array $new_membership_data ) {
		// Get form settings.
		$submission = WPCF7_Submission::get_instance();
		if ( $submission ) {
			$posted_data = $submission->get_posted_data();
		}
		$form_settings = $this->get_form_settings( $posted_data['_wpcf7'] );

		// Set membership mode.
		$new_membership_data['post_status'] = $form_settings['membership-mode'];
		return $new_membership_data;
	}

}

new CF7_Woo_Memberships();

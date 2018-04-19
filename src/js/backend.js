/**
 * Backend interactivity
 *
 * @package CF7_Woo_Memberships
 */

( function( $ ) {
	$( document ).ready( function() {
		// Hide all fields if form ignored.
		$( 'input[name="cf7-woocommerce-memberships[ignore-form]"]' ).on( 'change', function() {
			hideOtherFields();
		});
		hideOtherFields();

		// Add message to CF7 WooCommerce tab if main content is changed.
		$( '#wpcf7-form' ).on( 'change', function() {
			$( '.cf7-woocommerce-memberships-message' ).html( 'It looks like you&rsquo;ve changed the form content; please save the form first before changing any settings.' );
			$( '[name^="cf7-woocommerce-memberships"]' ).attr( 'disabled', true );
			$( '.cf7-woocommerce-memberships-table' ).hide();
		});
	});

	/**
	 * Hide other fields if this form is ignored
	 */
	function hideOtherFields() {
		var $checkbox    = $( 'input[name="cf7-woocommerce-memberships[ignore-form]"]' ),
			$otherFields = $( 'tr[class^="cf7-woocommerce-memberships"]:not(.cf7-woocommerce-memberships-field-ignore-form)' );

		if ( $checkbox.attr( 'checked' ) ) {
			$otherFields.hide();
		} else {
			$otherFields.show();
		}
	}
})( jQuery );

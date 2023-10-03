( function( window, document, $, undefined ) {
	'use strict';

	var ppp = {

		init : function () {
			$('.woocommerce-price-ppp').each( ppp.replacePriceHTML );
			$('.ppp-pricedrop').each( ppp.replacePriceDropHTML );
		},

		/**
		 * Fetch new price HTML via AJAX and replace on success.
		 *
		 * @since 0.1.0
		 *
		 * @param integer index Found elements array index.
		 * @param object  el    Found element.
		 */
		replacePriceHTML : function( index, el ) {

			var $el = $(el);
			$.post(
				woocommerce_params.ajax_url,
				{
					action:  'wps_get_ppp_price',
					product_id: $el.attr('data-product-id')
				},
				function( response ) {

					if ( true === response.success ) {
						$el.replaceWith( response.data.output );
					}
				}
			);
		},

		/**
		 * Fetch new pricedrop HTML via AJAX and replace on success.
		 *
		 * @since 0.2.0
		 *
		 * @param integer index Found elements array index.
		 * @param object  el    Found element.
		 */
		replacePriceDropHTML: function( index, el ) {
			var $el = $(el);
			$.post(
				woocommerce_params.ajax_url,
				{
					action:  'wps_get_ppp_pricedrop',
					regular: $el.attr('data-regular'),
					discount: $el.attr('data-discount')
				},
				function( response ) {
					if ( true === response.success ) {
						$el.replaceWith( response.data.output );
					}
				}
			);
		}
	};

	$(document).on( 'ready', ppp.init );

})( window, document, jQuery );

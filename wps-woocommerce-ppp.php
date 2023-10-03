<?php
/**
 * Plugin Name: Purchase-Power Parity for WooCommerce
 * Plugin URI:  https://WPSessions.com
 * Description: Converts all product prices to an equivalent rate for visitors based on their local purchasing-power parity.
 * Author:      Brian Richards
 * Author URI:  https://WPSessions.com
 * Text Domain: wps-woocommerce-ppp
 * Domain Path: /languages
 * Version:     0.2.1
 */

/**
 * Filter WooCommerce price output to use PPP prices.
 *
 * @since  0.1.0
 *
 * @param  float $price Original WC price.
 * @return float        Adjusted PPP price.
 */
function wps_get_ppp_price( $price = 0.00 ) {
	return wps_get_ppp_price_for_country( $price );
}
add_filter( 'woocommerce_product_get_price', 'wps_get_ppp_price' );
add_filter( 'woocommerce_product_variation_get_price', 'wps_get_ppp_price' );
add_filter( 'woocommerce_variation_prices_price', 'wps_get_ppp_price' );

/**
 * Get product's adjusted PPP price.
 *
 * This uses the Big Mac index and customer currency exchange rate
 * to determine an adjusted price. The Big Mac index was invented
 * by The Economist in 1986 as a lighthearted guide to whether
 * currencies are at their “correct” level. It is based on the
 * theory of purchasing-power parity (PPP).
 *
 * @see https://en.wikipedia.org/wiki/Big_Mac_Index
 * @see https://en.wikipedia.org/wiki/Purchasing_power_parity
 *
 * @since  0.1.0
 *
 * @param  float  $price   Product price.
 * @param  string $country Country to use for PPP.
 * @return float           Adjusted product price.
 */
function wps_get_ppp_price_for_country( $price = 0.00, $country = '' ) {

	if ( empty( $country ) ) {
		$geolocation = WC_Geolocation::geolocate_ip();
		$country = $geolocation['country'];
	}

	$ppp_rate = wps_get_ppp_rate( $country );

	return ceil( floatval( $price ) * $ppp_rate );
}

/**
 * Get PPP rate by dividing PPP by currency exchange rate.
 *
 * Rate will never be greater than 1 (original price) nor smaller than 0.1 (10% of original price).
 *
 * @since  0.1.0
 *
 * @param  string $alpha2 Alpha2 country code.
 * @return float          Country's PPP rate.
 */
function wps_get_ppp_rate( $alpha2 = 'US' ) {

	// Ensure alpha2 is never empty
	if ( empty( $alpha2 ) ) {
		$alpha2 = 'US';
	}

	// Bail early for local visitors
	$base_location = wc_get_base_location();
	if ( $alpha2 === $base_location['country'] ) {
		return 1;
	}

	// Get data from cache
	$transient_key = 'wps_ppp_rate_' . $alpha2;
	$ppp_rate = get_transient( $transient_key );

	if ( false === $ppp_rate ) {
		// Get fresh data
		$country_data = wps_get_country_data( $alpha2 );
		$ppp = $country_data ? wps_get_ppp( $country_data['alpha3'] ) : 1;
		$exchange_rate = $country_data ? wps_get_exchange_rate( $country_data['currency'] ) : 1;
		$ppp_rate = $ppp / $exchange_rate;

		set_transient( $transient_key, $ppp_rate, MONTH_IN_SECONDS );
	}

	// Ensure rate is never below 0.1 (10%) nor greater than 1 (100%)
	return min( max( 0.1, $ppp_rate ), 1 );
}

/**
 * Get purchase-power parity for a given country.
 *
 * @since  0.1.0
 *
 * @param  string $country_code_alpha3 Alpha3 Country Code.
 * @return float                       Country's PPP.
 */
function wps_get_ppp( $country_code_alpha3 = 'USA' ) {

	$api_base_url = 'https://www.quandl.com/api/v3/datasets/ODA/';
	$api_key = 'Xe8sqyjygMjzn-sjzYHs';
	$current_year = date( 'Y' );
	$current_month_day = date( '-m-d' );
	$last_year = absint( $current_year ) - 1;

	$request_url = add_query_arg(
		[
			'api_key'    => $api_key,
			'start_date' => $last_year . $current_month_day,
			'end_date'   => $current_year . $current_month_day,
		],
		$api_base_url . $country_code_alpha3 . '_PPPEX.json'
	);

	$response = wp_remote_get( $request_url );
	$data = json_decode( wp_remote_retrieve_body( $response ) );

	return isset( $data->dataset->data[0][1] )
		? $data->dataset->data[0][1]
		: 1;
}

/**
 * Get currency exchange rate against base currency.
 *
 * @since  0.1.0
 *
 * @param  string $currency_code Foreign currency code.
 * @return float                 Exchange rate.
 */
function wps_get_exchange_rate( $currency_code = 'USD' ) {

	// Bail early for local visitors
	$base_currency = get_woocommerce_currency();
	if ( $currency_code === $base_currency ) {
		return 1;
	}

	$api_base_url = 'https://api.exchangerate.host/latest';
	$request_url = add_query_arg(
		[
			'base' => $base_currency,
			'symbols' => $currency_code,
		],
		$api_base_url
	);

	$response = wp_remote_get( $request_url );
	$data = json_decode( wp_remote_retrieve_body( $response ) );

	return isset( $data->rates->$currency_code )
		? $data->rates->$currency_code
		: 1;
}

/**
 * Wrap WooCommerce price in additional HTML for easy AJAX updates.
 *
 * @since  0.1.0
 *
 * @param  string $price   HTML markup.
 * @param  object $product WooCommerce product.
 * @return string          HTML markup.
 */
function wps_wrap_price_html( $price, $product ) {
	wp_enqueue_script( 'wps-ppp-js', plugin_dir_url( __FILE__ ) . 'assets/ppp-ajax.js', [ 'woocommerce' ], '0.2.1', true );
	return sprintf(
		'<span class="woocommerce-price-ppp" data-product-id="%1$s">%2$s</span>',
		$product->get_id(),
		$price
	);
}
add_filter( 'woocommerce_get_price_html', 'wps_wrap_price_html', 10, 2 );

/**
 * Return new WooCommerce price HTML via AJAX.
 *
 * @since  0.1.0
 *
 * @return object JSON success or error object.
 */
function wps_get_ppp_price_html_via_ajax() {
	$product_id = isset( $_REQUEST['product_id'] ) ? absint( $_REQUEST['product_id'] ) : 0;

	$product = wc_get_product( $product_id );

	if ( ! is_object( $product ) ) {
		wp_send_json_error( $product_id );
	}

	remove_filter( 'woocommerce_product_get_price', 'wps_get_ppp_price' );
	remove_filter( 'woocommerce_product_variation_get_price', 'wps_get_ppp_price' );
	remove_filter( 'woocommerce_variation_prices_price', 'wps_get_ppp_price' );

	$geolocation = WC_Geolocation::geolocate_ip();
	$country = $geolocation['country'];

	if ( 'variable' === $product->get_type() ) {

		$prices = $product->get_variation_prices( true );

		if ( empty( $prices['price'] ) ) {
			$price = apply_filters( 'woocommerce_variable_empty_price_html', '', $product );
		} else {
			$min_price     = wps_get_ppp_price_for_country( current( $prices['price'] ), $country );
			$max_price     = wps_get_ppp_price_for_country( end( $prices['price'] ), $country );
			$min_reg_price = wps_get_ppp_price_for_country( current( $prices['regular_price'] ), $country );
			$max_reg_price = wps_get_ppp_price_for_country( end( $prices['regular_price'] ), $country );

			if ( $min_price !== $max_price ) {
				$output = wc_format_price_range( $min_price, $max_price );
			} elseif ( $product->is_on_sale() && $min_reg_price === $max_reg_price ) {
				$output = wc_format_sale_price( wc_price( $max_reg_price ), wc_price( $min_price ) );
			} else {
				$output = wc_price( $min_price );
			}
		}

	} else {

		$ppp_price = wps_get_ppp_price_for_country( $product->get_price(), $country );

		if ( '' === $product->get_price() ) {
			$output = apply_filters( 'woocommerce_empty_price_html', '', $product );
		} elseif ( $product->is_on_sale() ) {
			$output = wc_format_sale_price( wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) ), $ppp_price ) . $product->get_price_suffix();
		} else {
			$output = wc_price( $ppp_price ) . $product->get_price_suffix();
		}

	}

	wp_send_json_success( [ 'output' => $output, 'country' => $country, 'geoip_external' => WC_Geolocation::geolocate_ip( WC_Geolocation::get_external_ip_address() )['country'], 'geoip_internal' => WC_Geolocation::geolocate_ip( WC_Geolocation::get_ip_address() )['country'], 'ip' => WC_Geolocation::get_ip_address(), 'external_ip' => WC_Geolocation::get_external_ip_address() ] );
}
add_filter( 'wp_ajax_wps_get_ppp_price', 'wps_get_ppp_price_html_via_ajax' );
add_filter( 'wp_ajax_nopriv_wps_get_ppp_price', 'wps_get_ppp_price_html_via_ajax' );

/**
 * Shortcode to output PPP-filtered price drops.
 *
 * @since  0.2.0
 *
 * @param  array  $atts    Shortcode attributes.
 * @return string          HTML markup.
 */
function wps_ppp_pricedrop_shortcode( $atts = [] ) {

	$atts = shortcode_atts(
		[
			'regular' => 0,
			'discount' => 0,
		],
		$atts,
		'ppp_pricedrop'
	);

	wp_enqueue_script( 'wps-ppp-js', plugin_dir_url( __FILE__ ) . 'assets/ppp-ajax.js', [ 'woocommerce' ], '0.2.1', true );

	return wps_get_ppp_pricedrop_html( $atts['regular'], $atts['discount'] );
}
add_shortcode( 'ppp_pricedrop', 'wps_ppp_pricedrop_shortcode' );

/**
 * Format pricedrop HTML.
 *
 * @since  0.2.0
 *
 * @param  float  $regular  Regular price.
 * @param  float  $discount Discounted price.
 * @return string           HTML markup.
 */
function wps_get_ppp_pricedrop_html( $regular = 0.00, $discount = 0.00 ) {

	$output = ( $regular === $discount )
		? '<span class="ppp-pricedrop" data-regular="%1$s" data-discount="%2$s">%1$s</span>'
		: '<span class="ppp-pricedrop" data-regular="%1$s" data-discount="%2$s"><del class="ppp-pricedrop-regular">$%1$s</del> <em><strong class="ppp-pricedrop-discount">$%2$s</strong> <span class="ppp-pricedrop-savings">(save $%3$s)</span></em></span>';

	return sprintf(
		$output,
		floatval( $regular ),
		floatval( $discount ),
		( floatval( $regular ) - floatval( $discount ) )
	);
}

/**
 * Return new discount price HTML via AJAX.
 *
 * @since  0.2.0
 *
 * @return object JSON success or error object.
 */
function wps_get_ppp_pricedrop_html_via_ajax() {

	// Grab GeoIP data
	$geolocation = WC_Geolocation::geolocate_ip();
	$country = $geolocation['country'];

	// Grab price data
	$regular = isset( $_REQUEST['regular'] ) ? $_REQUEST['regular'] : 0.00;
	$discount = isset( $_REQUEST['discount'] ) ? $_REQUEST['discount'] : 0.00;
	$ppp_price = wps_get_ppp_price_for_country( $discount, $country );

	// If regular and discount are the same we want to pass the PPP price through
	// as regular price so output doesn't actually display a pricedrop.
	if ( $regular === $discount ) {
		$regular = $ppp_price;
	}

	$output = wps_get_ppp_pricedrop_html( $regular, $ppp_price );

	// Add'l Debug Data
	// $alpha2 = $country;
	// $transient_key = 'wps_ppp_rate_' . $alpha2;
	// $cached_rate = get_transient( $transient_key );
	// $country_data = wps_get_country_data( $alpha2 );
	// $ppp = wps_get_ppp( $country_data['alpha3'] );
	// $exchange_rate = wps_get_exchange_rate( $country_data['currency'] );
	// $ppp_rate = $ppp / $exchange_rate;
	// $debug = [ 'alpha2' => $alpha2, 'transient_key' => $transient_key, 'cached_rate' => $cached_rate, 'country_data' => $country_data, 'ppp' => $ppp, 'exchange_rate' => $exchange_rate, 'ppp_rate' => $ppp_rate ];

	wp_send_json_success( [ 'output' => $output, 'country' => $country, 'geoip_external' => WC_Geolocation::geolocate_ip( WC_Geolocation::get_external_ip_address() )['country'], 'geoip_internal' => WC_Geolocation::geolocate_ip( WC_Geolocation::get_ip_address() )['country'], 'ip' => WC_Geolocation::get_ip_address(), 'external_ip' => WC_Geolocation::get_external_ip_address() ] );
}
add_filter( 'wp_ajax_wps_get_ppp_pricedrop', 'wps_get_ppp_pricedrop_html_via_ajax' );
add_filter( 'wp_ajax_nopriv_wps_get_ppp_pricedrop', 'wps_get_ppp_pricedrop_html_via_ajax' );

/**
 * Get alpha-2, alpha-3, and currency data for a given country.
 *
 * This used to be an API call and is now hard-coded to save the
 * extra look-up. The tradeoff is if countries change it will
 * require a manual update.
 *
 * @source http://country.io/data/
 *
 * @param string $alpha2 Country alpha-2 code (e.g. 'US')
 * @return array|false   {
 * 		Data for a single country if $alpha2 exists,
 * 		otherwise false.
 *
 * 		@type string $name		Country full name.
 * 		@type string $alpha2	ISO alpha-2 code.
 *		@type string $alpha3	ISO alpha-3 code.
 *		@type string $currency	Three-letter currency code.
 * }
 */
function wps_get_country_data( $alpha2 = '' ) {
	$countries = [
		'AD' => [ 'name' => 'Andorra', 'alpha2' => 'AD', 'alpha3' => 'AND', 'currency' => 'EUR', ],
		'AE' => [ 'name' => 'United Arab Emirates', 'alpha2' => 'AE', 'alpha3' => 'ARE', 'currency' => 'AED', ],
		'AF' => [ 'name' => 'Afghanistan', 'alpha2' => 'AF', 'alpha3' => 'AFG', 'currency' => 'AFN', ],
		'AG' => [ 'name' => 'Antigua and Barbuda', 'alpha2' => 'AG', 'alpha3' => 'ATG', 'currency' => 'XCD', ],
		'AI' => [ 'name' => 'Anguilla', 'alpha2' => 'AI', 'alpha3' => 'AIA', 'currency' => 'XCD', ],
		'AL' => [ 'name' => 'Albania', 'alpha2' => 'AL', 'alpha3' => 'ALB', 'currency' => 'ALL', ],
		'AM' => [ 'name' => 'Armenia', 'alpha2' => 'AM', 'alpha3' => 'ARM', 'currency' => 'AMD', ],
		'AO' => [ 'name' => 'Angola', 'alpha2' => 'AO', 'alpha3' => 'AGO', 'currency' => 'AOA', ],
		'AQ' => [ 'name' => 'Antarctica', 'alpha2' => 'AQ', 'alpha3' => 'ATA', 'currency' => '', ],
		'AR' => [ 'name' => 'Argentina', 'alpha2' => 'AR', 'alpha3' => 'ARG', 'currency' => 'ARS', ],
		'AS' => [ 'name' => 'American Samoa', 'alpha2' => 'AS', 'alpha3' => 'ASM', 'currency' => 'USD', ],
		'AT' => [ 'name' => 'Austria', 'alpha2' => 'AT', 'alpha3' => 'AUT', 'currency' => 'EUR', ],
		'AU' => [ 'name' => 'Australia', 'alpha2' => 'AU', 'alpha3' => 'AUS', 'currency' => 'AUD', ],
		'AW' => [ 'name' => 'Aruba', 'alpha2' => 'AW', 'alpha3' => 'ABW', 'currency' => 'AWG', ],
		'AX' => [ 'name' => 'Åland Islands', 'alpha2' => 'AX', 'alpha3' => 'ALA', 'currency' => 'EUR', ],
		'AZ' => [ 'name' => 'Azerbaijan', 'alpha2' => 'AZ', 'alpha3' => 'AZE', 'currency' => 'AZN', ],
		'BA' => [ 'name' => 'Bosnia and Herzegovina', 'alpha2' => 'BA', 'alpha3' => 'BIH', 'currency' => 'BAM', ],
		'BB' => [ 'name' => 'Barbados', 'alpha2' => 'BB', 'alpha3' => 'BRB', 'currency' => 'BBD', ],
		'BD' => [ 'name' => 'Bangladesh', 'alpha2' => 'BD', 'alpha3' => 'BGD', 'currency' => 'BDT', ],
		'BE' => [ 'name' => 'Belgium', 'alpha2' => 'BE', 'alpha3' => 'BEL', 'currency' => 'EUR', ],
		'BF' => [ 'name' => 'Burkina Faso', 'alpha2' => 'BF', 'alpha3' => 'BFA', 'currency' => 'XOF', ],
		'BG' => [ 'name' => 'Bulgaria', 'alpha2' => 'BG', 'alpha3' => 'BGR', 'currency' => 'BGN', ],
		'BH' => [ 'name' => 'Bahrain', 'alpha2' => 'BH', 'alpha3' => 'BHR', 'currency' => 'BHD', ],
		'BI' => [ 'name' => 'Burundi', 'alpha2' => 'BI', 'alpha3' => 'BDI', 'currency' => 'BIF', ],
		'BJ' => [ 'name' => 'Benin', 'alpha2' => 'BJ', 'alpha3' => 'BEN', 'currency' => 'XOF', ],
		'BL' => [ 'name' => 'Saint Barthélemy', 'alpha2' => 'BL', 'alpha3' => 'BLM', 'currency' => 'EUR', ],
		'BM' => [ 'name' => 'Bermuda', 'alpha2' => 'BM', 'alpha3' => 'BMU', 'currency' => 'BMD', ],
		'BN' => [ 'name' => 'Brunei Darussalam', 'alpha2' => 'BN', 'alpha3' => 'BRN', 'currency' => 'BND', ],
		'BO' => [ 'name' => 'Bolivia (Plurinational State of)', 'alpha2' => 'BO', 'alpha3' => 'BOL', 'currency' => 'BOB', ],
		'BQ' => [ 'name' => 'Bonaire, Sint Eustatius and Saba', 'alpha2' => 'BQ', 'alpha3' => 'BES', 'currency' => 'USD', ],
		'BR' => [ 'name' => 'Brazil', 'alpha2' => 'BR', 'alpha3' => 'BRA', 'currency' => 'BRL', ],
		'BS' => [ 'name' => 'Bahamas', 'alpha2' => 'BS', 'alpha3' => 'BHS', 'currency' => 'BSD', ],
		'BT' => [ 'name' => 'Bhutan', 'alpha2' => 'BT', 'alpha3' => 'BTN', 'currency' => 'BTN', ],
		'BV' => [ 'name' => 'Bouvet Island', 'alpha2' => 'BV', 'alpha3' => 'BVT', 'currency' => 'NOK', ],
		'BW' => [ 'name' => 'Botswana', 'alpha2' => 'BW', 'alpha3' => 'BWA', 'currency' => 'BWP', ],
		'BY' => [ 'name' => 'Belarus', 'alpha2' => 'BY', 'alpha3' => 'BLR', 'currency' => 'BYR', ],
		'BZ' => [ 'name' => 'Belize', 'alpha2' => 'BZ', 'alpha3' => 'BLZ', 'currency' => 'BZD', ],
		'CA' => [ 'name' => 'Canada', 'alpha2' => 'CA', 'alpha3' => 'CAN', 'currency' => 'CAD', ],
		'CC' => [ 'name' => 'Cocos (Keeling) Islands', 'alpha2' => 'CC', 'alpha3' => 'CCK', 'currency' => 'AUD', ],
		'CD' => [ 'name' => 'Congo, Democratic Republic of the', 'alpha2' => 'CD', 'alpha3' => 'COD', 'currency' => 'CDF', ],
		'CF' => [ 'name' => 'Central African Republic', 'alpha2' => 'CF', 'alpha3' => 'CAF', 'currency' => 'XAF', ],
		'CG' => [ 'name' => 'Congo', 'alpha2' => 'CG', 'alpha3' => 'COG', 'currency' => 'XAF', ],
		'CH' => [ 'name' => 'Switzerland', 'alpha2' => 'CH', 'alpha3' => 'CHE', 'currency' => 'CHF', ],
		'CI' => [ 'name' => 'Côte d\'Ivoire', 'alpha2' => 'CI', 'alpha3' => 'CIV', 'currency' => 'XOF', ],
		'CK' => [ 'name' => 'Cook Islands', 'alpha2' => 'CK', 'alpha3' => 'COK', 'currency' => 'NZD', ],
		'CL' => [ 'name' => 'Chile', 'alpha2' => 'CL', 'alpha3' => 'CHL', 'currency' => 'CLP', ],
		'CM' => [ 'name' => 'Cameroon', 'alpha2' => 'CM', 'alpha3' => 'CMR', 'currency' => 'XAF', ],
		'CN' => [ 'name' => 'China', 'alpha2' => 'CN', 'alpha3' => 'CHN', 'currency' => 'CNY', ],
		'CO' => [ 'name' => 'Colombia', 'alpha2' => 'CO', 'alpha3' => 'COL', 'currency' => 'COP', ],
		'CR' => [ 'name' => 'Costa Rica', 'alpha2' => 'CR', 'alpha3' => 'CRI', 'currency' => 'CRC', ],
		'CU' => [ 'name' => 'Cuba', 'alpha2' => 'CU', 'alpha3' => 'CUB', 'currency' => 'CUP', ],
		'CV' => [ 'name' => 'Cabo Verde', 'alpha2' => 'CV', 'alpha3' => 'CPV', 'currency' => 'CVE', ],
		'CW' => [ 'name' => 'Curaçao', 'alpha2' => 'CW', 'alpha3' => 'CUW', 'currency' => 'ANG', ],
		'CX' => [ 'name' => 'Christmas Island', 'alpha2' => 'CX', 'alpha3' => 'CXR', 'currency' => 'AUD', ],
		'CY' => [ 'name' => 'Cyprus', 'alpha2' => 'CY', 'alpha3' => 'CYP', 'currency' => 'EUR', ],
		'CZ' => [ 'name' => 'Czechia', 'alpha2' => 'CZ', 'alpha3' => 'CZE', 'currency' => 'CZK', ],
		'DE' => [ 'name' => 'Germany', 'alpha2' => 'DE', 'alpha3' => 'DEU', 'currency' => 'EUR', ],
		'DJ' => [ 'name' => 'Djibouti', 'alpha2' => 'DJ', 'alpha3' => 'DJI', 'currency' => 'DJF', ],
		'DK' => [ 'name' => 'Denmark', 'alpha2' => 'DK', 'alpha3' => 'DNK', 'currency' => 'DKK', ],
		'DM' => [ 'name' => 'Dominica', 'alpha2' => 'DM', 'alpha3' => 'DMA', 'currency' => 'XCD', ],
		'DO' => [ 'name' => 'Dominican Republic', 'alpha2' => 'DO', 'alpha3' => 'DOM', 'currency' => 'DOP', ],
		'DZ' => [ 'name' => 'Algeria', 'alpha2' => 'DZ', 'alpha3' => 'DZA', 'currency' => 'DZD', ],
		'EC' => [ 'name' => 'Ecuador', 'alpha2' => 'EC', 'alpha3' => 'ECU', 'currency' => 'USD', ],
		'EE' => [ 'name' => 'Estonia', 'alpha2' => 'EE', 'alpha3' => 'EST', 'currency' => 'EUR', ],
		'EG' => [ 'name' => 'Egypt', 'alpha2' => 'EG', 'alpha3' => 'EGY', 'currency' => 'EGP', ],
		'EH' => [ 'name' => 'Western Sahara', 'alpha2' => 'EH', 'alpha3' => 'ESH', 'currency' => 'MAD', ],
		'ER' => [ 'name' => 'Eritrea', 'alpha2' => 'ER', 'alpha3' => 'ERI', 'currency' => 'ERN', ],
		'ES' => [ 'name' => 'Spain', 'alpha2' => 'ES', 'alpha3' => 'ESP', 'currency' => 'EUR', ],
		'ET' => [ 'name' => 'Ethiopia', 'alpha2' => 'ET', 'alpha3' => 'ETH', 'currency' => 'ETB', ],
		'FI' => [ 'name' => 'Finland', 'alpha2' => 'FI', 'alpha3' => 'FIN', 'currency' => 'EUR', ],
		'FJ' => [ 'name' => 'Fiji', 'alpha2' => 'FJ', 'alpha3' => 'FJI', 'currency' => 'FJD', ],
		'FK' => [ 'name' => 'Falkland Islands (Malvinas)', 'alpha2' => 'FK', 'alpha3' => 'FLK', 'currency' => 'FKP', ],
		'FM' => [ 'name' => 'Micronesia (Federated States of)', 'alpha2' => 'FM', 'alpha3' => 'FSM', 'currency' => 'USD', ],
		'FO' => [ 'name' => 'Faroe Islands', 'alpha2' => 'FO', 'alpha3' => 'FRO', 'currency' => 'DKK', ],
		'FR' => [ 'name' => 'France', 'alpha2' => 'FR', 'alpha3' => 'FRA', 'currency' => 'EUR', ],
		'GA' => [ 'name' => 'Gabon', 'alpha2' => 'GA', 'alpha3' => 'GAB', 'currency' => 'XAF', ],
		'GB' => [ 'name' => 'United Kingdom of Great Britain and Northern Ireland', 'alpha2' => 'GB', 'alpha3' => 'GBR', 'currency' => 'GBP', ],
		'GD' => [ 'name' => 'Grenada', 'alpha2' => 'GD', 'alpha3' => 'GRD', 'currency' => 'XCD', ],
		'GE' => [ 'name' => 'Georgia', 'alpha2' => 'GE', 'alpha3' => 'GEO', 'currency' => 'GEL', ],
		'GF' => [ 'name' => 'French Guiana', 'alpha2' => 'GF', 'alpha3' => 'GUF', 'currency' => 'EUR', ],
		'GG' => [ 'name' => 'Guernsey', 'alpha2' => 'GG', 'alpha3' => 'GGY', 'currency' => 'GBP', ],
		'GH' => [ 'name' => 'Ghana', 'alpha2' => 'GH', 'alpha3' => 'GHA', 'currency' => 'GHS', ],
		'GI' => [ 'name' => 'Gibraltar', 'alpha2' => 'GI', 'alpha3' => 'GIB', 'currency' => 'GIP', ],
		'GL' => [ 'name' => 'Greenland', 'alpha2' => 'GL', 'alpha3' => 'GRL', 'currency' => 'DKK', ],
		'GM' => [ 'name' => 'Gambia', 'alpha2' => 'GM', 'alpha3' => 'GMB', 'currency' => 'GMD', ],
		'GN' => [ 'name' => 'Guinea', 'alpha2' => 'GN', 'alpha3' => 'GIN', 'currency' => 'GNF', ],
		'GP' => [ 'name' => 'Guadeloupe', 'alpha2' => 'GP', 'alpha3' => 'GLP', 'currency' => 'EUR', ],
		'GQ' => [ 'name' => 'Equatorial Guinea', 'alpha2' => 'GQ', 'alpha3' => 'GNQ', 'currency' => 'XAF', ],
		'GR' => [ 'name' => 'Greece', 'alpha2' => 'GR', 'alpha3' => 'GRC', 'currency' => 'EUR', ],
		'GS' => [ 'name' => 'South Georgia and the South Sandwich Islands', 'alpha2' => 'GS', 'alpha3' => 'SGS', 'currency' => 'GBP', ],
		'GT' => [ 'name' => 'Guatemala', 'alpha2' => 'GT', 'alpha3' => 'GTM', 'currency' => 'GTQ', ],
		'GU' => [ 'name' => 'Guam', 'alpha2' => 'GU', 'alpha3' => 'GUM', 'currency' => 'USD', ],
		'GW' => [ 'name' => 'Guinea-Bissau', 'alpha2' => 'GW', 'alpha3' => 'GNB', 'currency' => 'XOF', ],
		'GY' => [ 'name' => 'Guyana', 'alpha2' => 'GY', 'alpha3' => 'GUY', 'currency' => 'GYD', ],
		'HK' => [ 'name' => 'Hong Kong', 'alpha2' => 'HK', 'alpha3' => 'HKG', 'currency' => 'HKD', ],
		'HM' => [ 'name' => 'Heard Island and McDonald Islands', 'alpha2' => 'HM', 'alpha3' => 'HMD', 'currency' => 'AUD', ],
		'HN' => [ 'name' => 'Honduras', 'alpha2' => 'HN', 'alpha3' => 'HND', 'currency' => 'HNL', ],
		'HR' => [ 'name' => 'Croatia', 'alpha2' => 'HR', 'alpha3' => 'HRV', 'currency' => 'HRK', ],
		'HT' => [ 'name' => 'Haiti', 'alpha2' => 'HT', 'alpha3' => 'HTI', 'currency' => 'HTG', ],
		'HU' => [ 'name' => 'Hungary', 'alpha2' => 'HU', 'alpha3' => 'HUN', 'currency' => 'HUF', ],
		'ID' => [ 'name' => 'Indonesia', 'alpha2' => 'ID', 'alpha3' => 'IDN', 'currency' => 'IDR', ],
		'IE' => [ 'name' => 'Ireland', 'alpha2' => 'IE', 'alpha3' => 'IRL', 'currency' => 'EUR', ],
		'IL' => [ 'name' => 'Israel', 'alpha2' => 'IL', 'alpha3' => 'ISR', 'currency' => 'ILS', ],
		'IM' => [ 'name' => 'Isle of Man', 'alpha2' => 'IM', 'alpha3' => 'IMN', 'currency' => 'GBP', ],
		'IN' => [ 'name' => 'India', 'alpha2' => 'IN', 'alpha3' => 'IND', 'currency' => 'INR', ],
		'IO' => [ 'name' => 'British Indian Ocean Territory', 'alpha2' => 'IO', 'alpha3' => 'IOT', 'currency' => 'USD', ],
		'IQ' => [ 'name' => 'Iraq', 'alpha2' => 'IQ', 'alpha3' => 'IRQ', 'currency' => 'IQD', ],
		'IR' => [ 'name' => 'Iran (Islamic Republic of)', 'alpha2' => 'IR', 'alpha3' => 'IRN', 'currency' => 'IRR', ],
		'IS' => [ 'name' => 'Iceland', 'alpha2' => 'IS', 'alpha3' => 'ISL', 'currency' => 'ISK', ],
		'IT' => [ 'name' => 'Italy', 'alpha2' => 'IT', 'alpha3' => 'ITA', 'currency' => 'EUR', ],
		'JE' => [ 'name' => 'Jersey', 'alpha2' => 'JE', 'alpha3' => 'JEY', 'currency' => 'GBP', ],
		'JM' => [ 'name' => 'Jamaica', 'alpha2' => 'JM', 'alpha3' => 'JAM', 'currency' => 'JMD', ],
		'JO' => [ 'name' => 'Jordan', 'alpha2' => 'JO', 'alpha3' => 'JOR', 'currency' => 'JOD', ],
		'JP' => [ 'name' => 'Japan', 'alpha2' => 'JP', 'alpha3' => 'JPN', 'currency' => 'JPY', ],
		'KE' => [ 'name' => 'Kenya', 'alpha2' => 'KE', 'alpha3' => 'KEN', 'currency' => 'KES', ],
		'KG' => [ 'name' => 'Kyrgyzstan', 'alpha2' => 'KG', 'alpha3' => 'KGZ', 'currency' => 'KGS', ],
		'KH' => [ 'name' => 'Cambodia', 'alpha2' => 'KH', 'alpha3' => 'KHM', 'currency' => 'KHR', ],
		'KI' => [ 'name' => 'Kiribati', 'alpha2' => 'KI', 'alpha3' => 'KIR', 'currency' => 'AUD', ],
		'KM' => [ 'name' => 'Comoros', 'alpha2' => 'KM', 'alpha3' => 'COM', 'currency' => 'KMF', ],
		'KN' => [ 'name' => 'Saint Kitts and Nevis', 'alpha2' => 'KN', 'alpha3' => 'KNA', 'currency' => 'XCD', ],
		'KP' => [ 'name' => 'Korea (Democratic People\'s Republic of)', 'alpha2' => 'KP', 'alpha3' => 'PRK', 'currency' => 'KPW', ],
		'KR' => [ 'name' => 'Korea, Republic of', 'alpha2' => 'KR', 'alpha3' => 'KOR', 'currency' => 'KRW', ],
		'KW' => [ 'name' => 'Kuwait', 'alpha2' => 'KW', 'alpha3' => 'KWT', 'currency' => 'KWD', ],
		'KY' => [ 'name' => 'Cayman Islands', 'alpha2' => 'KY', 'alpha3' => 'CYM', 'currency' => 'KYD', ],
		'KZ' => [ 'name' => 'Kazakhstan', 'alpha2' => 'KZ', 'alpha3' => 'KAZ', 'currency' => 'KZT', ],
		'LA' => [ 'name' => 'Lao People\'s Democratic Republic', 'alpha2' => 'LA', 'alpha3' => 'LAO', 'currency' => 'LAK', ],
		'LB' => [ 'name' => 'Lebanon', 'alpha2' => 'LB', 'alpha3' => 'LBN', 'currency' => 'LBP', ],
		'LC' => [ 'name' => 'Saint Lucia', 'alpha2' => 'LC', 'alpha3' => 'LCA', 'currency' => 'XCD', ],
		'LI' => [ 'name' => 'Liechtenstein', 'alpha2' => 'LI', 'alpha3' => 'LIE', 'currency' => 'CHF', ],
		'LK' => [ 'name' => 'Sri Lanka', 'alpha2' => 'LK', 'alpha3' => 'LKA', 'currency' => 'LKR', ],
		'LR' => [ 'name' => 'Liberia', 'alpha2' => 'LR', 'alpha3' => 'LBR', 'currency' => 'LRD', ],
		'LS' => [ 'name' => 'Lesotho', 'alpha2' => 'LS', 'alpha3' => 'LSO', 'currency' => 'LSL', ],
		'LT' => [ 'name' => 'Lithuania', 'alpha2' => 'LT', 'alpha3' => 'LTU', 'currency' => 'LTL', ],
		'LU' => [ 'name' => 'Luxembourg', 'alpha2' => 'LU', 'alpha3' => 'LUX', 'currency' => 'EUR', ],
		'LV' => [ 'name' => 'Latvia', 'alpha2' => 'LV', 'alpha3' => 'LVA', 'currency' => 'EUR', ],
		'LY' => [ 'name' => 'Libya', 'alpha2' => 'LY', 'alpha3' => 'LBY', 'currency' => 'LYD', ],
		'MA' => [ 'name' => 'Morocco', 'alpha2' => 'MA', 'alpha3' => 'MAR', 'currency' => 'MAD', ],
		'MC' => [ 'name' => 'Monaco', 'alpha2' => 'MC', 'alpha3' => 'MCO', 'currency' => 'EUR', ],
		'MD' => [ 'name' => 'Moldova, Republic of', 'alpha2' => 'MD', 'alpha3' => 'MDA', 'currency' => 'MDL', ],
		'ME' => [ 'name' => 'Montenegro', 'alpha2' => 'ME', 'alpha3' => 'MNE', 'currency' => 'EUR', ],
		'MF' => [ 'name' => 'Saint Martin (French part)', 'alpha2' => 'MF', 'alpha3' => 'MAF', 'currency' => 'EUR', ],
		'MG' => [ 'name' => 'Madagascar', 'alpha2' => 'MG', 'alpha3' => 'MDG', 'currency' => 'MGA', ],
		'MH' => [ 'name' => 'Marshall Islands', 'alpha2' => 'MH', 'alpha3' => 'MHL', 'currency' => 'USD', ],
		'MK' => [ 'name' => 'North Macedonia', 'alpha2' => 'MK', 'alpha3' => 'MKD', 'currency' => 'MKD', ],
		'ML' => [ 'name' => 'Mali', 'alpha2' => 'ML', 'alpha3' => 'MLI', 'currency' => 'XOF', ],
		'MM' => [ 'name' => 'Myanmar', 'alpha2' => 'MM', 'alpha3' => 'MMR', 'currency' => 'MMK', ],
		'MN' => [ 'name' => 'Mongolia', 'alpha2' => 'MN', 'alpha3' => 'MNG', 'currency' => 'MNT', ],
		'MO' => [ 'name' => 'Macao', 'alpha2' => 'MO', 'alpha3' => 'MAC', 'currency' => 'MOP', ],
		'MP' => [ 'name' => 'Northern Mariana Islands', 'alpha2' => 'MP', 'alpha3' => 'MNP', 'currency' => 'USD', ],
		'MQ' => [ 'name' => 'Martinique', 'alpha2' => 'MQ', 'alpha3' => 'MTQ', 'currency' => 'EUR', ],
		'MR' => [ 'name' => 'Mauritania', 'alpha2' => 'MR', 'alpha3' => 'MRT', 'currency' => 'MRO', ],
		'MS' => [ 'name' => 'Montserrat', 'alpha2' => 'MS', 'alpha3' => 'MSR', 'currency' => 'XCD', ],
		'MT' => [ 'name' => 'Malta', 'alpha2' => 'MT', 'alpha3' => 'MLT', 'currency' => 'EUR', ],
		'MU' => [ 'name' => 'Mauritius', 'alpha2' => 'MU', 'alpha3' => 'MUS', 'currency' => 'MUR', ],
		'MV' => [ 'name' => 'Maldives', 'alpha2' => 'MV', 'alpha3' => 'MDV', 'currency' => 'MVR', ],
		'MW' => [ 'name' => 'Malawi', 'alpha2' => 'MW', 'alpha3' => 'MWI', 'currency' => 'MWK', ],
		'MX' => [ 'name' => 'Mexico', 'alpha2' => 'MX', 'alpha3' => 'MEX', 'currency' => 'MXN', ],
		'MY' => [ 'name' => 'Malaysia', 'alpha2' => 'MY', 'alpha3' => 'MYS', 'currency' => 'MYR', ],
		'MZ' => [ 'name' => 'Mozambique', 'alpha2' => 'MZ', 'alpha3' => 'MOZ', 'currency' => 'MZN', ],
		'NA' => [ 'name' => 'Namibia', 'alpha2' => 'NA', 'alpha3' => 'NAM', 'currency' => 'NAD', ],
		'NC' => [ 'name' => 'New Caledonia', 'alpha2' => 'NC', 'alpha3' => 'NCL', 'currency' => 'XPF', ],
		'NE' => [ 'name' => 'Niger', 'alpha2' => 'NE', 'alpha3' => 'NER', 'currency' => 'XOF', ],
		'NF' => [ 'name' => 'Norfolk Island', 'alpha2' => 'NF', 'alpha3' => 'NFK', 'currency' => 'AUD', ],
		'NG' => [ 'name' => 'Nigeria', 'alpha2' => 'NG', 'alpha3' => 'NGA', 'currency' => 'NGN', ],
		'NI' => [ 'name' => 'Nicaragua', 'alpha2' => 'NI', 'alpha3' => 'NIC', 'currency' => 'NIO', ],
		'NL' => [ 'name' => 'Netherlands', 'alpha2' => 'NL', 'alpha3' => 'NLD', 'currency' => 'EUR', ],
		'NO' => [ 'name' => 'Norway', 'alpha2' => 'NO', 'alpha3' => 'NOR', 'currency' => 'NOK', ],
		'NP' => [ 'name' => 'Nepal', 'alpha2' => 'NP', 'alpha3' => 'NPL', 'currency' => 'NPR', ],
		'NR' => [ 'name' => 'Nauru', 'alpha2' => 'NR', 'alpha3' => 'NRU', 'currency' => 'AUD', ],
		'NU' => [ 'name' => 'Niue', 'alpha2' => 'NU', 'alpha3' => 'NIU', 'currency' => 'NZD', ],
		'NZ' => [ 'name' => 'New Zealand', 'alpha2' => 'NZ', 'alpha3' => 'NZL', 'currency' => 'NZD', ],
		'OM' => [ 'name' => 'Oman', 'alpha2' => 'OM', 'alpha3' => 'OMN', 'currency' => 'OMR', ],
		'PA' => [ 'name' => 'Panama', 'alpha2' => 'PA', 'alpha3' => 'PAN', 'currency' => 'PAB', ],
		'PE' => [ 'name' => 'Peru', 'alpha2' => 'PE', 'alpha3' => 'PER', 'currency' => 'PEN', ],
		'PF' => [ 'name' => 'French Polynesia', 'alpha2' => 'PF', 'alpha3' => 'PYF', 'currency' => 'XPF', ],
		'PG' => [ 'name' => 'Papua New Guinea', 'alpha2' => 'PG', 'alpha3' => 'PNG', 'currency' => 'PGK', ],
		'PH' => [ 'name' => 'Philippines', 'alpha2' => 'PH', 'alpha3' => 'PHL', 'currency' => 'PHP', ],
		'PK' => [ 'name' => 'Pakistan', 'alpha2' => 'PK', 'alpha3' => 'PAK', 'currency' => 'PKR', ],
		'PL' => [ 'name' => 'Poland', 'alpha2' => 'PL', 'alpha3' => 'POL', 'currency' => 'PLN', ],
		'PM' => [ 'name' => 'Saint Pierre and Miquelon', 'alpha2' => 'PM', 'alpha3' => 'SPM', 'currency' => 'EUR', ],
		'PN' => [ 'name' => 'Pitcairn', 'alpha2' => 'PN', 'alpha3' => 'PCN', 'currency' => 'NZD', ],
		'PR' => [ 'name' => 'Puerto Rico', 'alpha2' => 'PR', 'alpha3' => 'PRI', 'currency' => 'USD', ],
		'PS' => [ 'name' => 'Palestine, State of', 'alpha2' => 'PS', 'alpha3' => 'PSE', 'currency' => 'ILS', ],
		'PT' => [ 'name' => 'Portugal', 'alpha2' => 'PT', 'alpha3' => 'PRT', 'currency' => 'EUR', ],
		'PW' => [ 'name' => 'Palau', 'alpha2' => 'PW', 'alpha3' => 'PLW', 'currency' => 'USD', ],
		'PY' => [ 'name' => 'Paraguay', 'alpha2' => 'PY', 'alpha3' => 'PRY', 'currency' => 'PYG', ],
		'QA' => [ 'name' => 'Qatar', 'alpha2' => 'QA', 'alpha3' => 'QAT', 'currency' => 'QAR', ],
		'RE' => [ 'name' => 'Réunion', 'alpha2' => 'RE', 'alpha3' => 'REU', 'currency' => 'EUR', ],
		'RO' => [ 'name' => 'Romania', 'alpha2' => 'RO', 'alpha3' => 'ROU', 'currency' => 'RON', ],
		'RS' => [ 'name' => 'Serbia', 'alpha2' => 'RS', 'alpha3' => 'SRB', 'currency' => 'RSD', ],
		'RU' => [ 'name' => 'Russian Federation', 'alpha2' => 'RU', 'alpha3' => 'RUS', 'currency' => 'RUB', ],
		'RW' => [ 'name' => 'Rwanda', 'alpha2' => 'RW', 'alpha3' => 'RWA', 'currency' => 'RWF', ],
		'SA' => [ 'name' => 'Saudi Arabia', 'alpha2' => 'SA', 'alpha3' => 'SAU', 'currency' => 'SAR', ],
		'SB' => [ 'name' => 'Solomon Islands', 'alpha2' => 'SB', 'alpha3' => 'SLB', 'currency' => 'SBD', ],
		'SC' => [ 'name' => 'Seychelles', 'alpha2' => 'SC', 'alpha3' => 'SYC', 'currency' => 'SCR', ],
		'SD' => [ 'name' => 'Sudan', 'alpha2' => 'SD', 'alpha3' => 'SDN', 'currency' => 'SDG', ],
		'SE' => [ 'name' => 'Sweden', 'alpha2' => 'SE', 'alpha3' => 'SWE', 'currency' => 'SEK', ],
		'SG' => [ 'name' => 'Singapore', 'alpha2' => 'SG', 'alpha3' => 'SGP', 'currency' => 'SGD', ],
		'SH' => [ 'name' => 'Saint Helena, Ascension and Tristan da Cunha', 'alpha2' => 'SH', 'alpha3' => 'SHN', 'currency' => 'SHP', ],
		'SI' => [ 'name' => 'Slovenia', 'alpha2' => 'SI', 'alpha3' => 'SVN', 'currency' => 'EUR', ],
		'SJ' => [ 'name' => 'Svalbard and Jan Mayen', 'alpha2' => 'SJ', 'alpha3' => 'SJM', 'currency' => 'NOK', ],
		'SK' => [ 'name' => 'Slovakia', 'alpha2' => 'SK', 'alpha3' => 'SVK', 'currency' => 'EUR', ],
		'SL' => [ 'name' => 'Sierra Leone', 'alpha2' => 'SL', 'alpha3' => 'SLE', 'currency' => 'SLL', ],
		'SM' => [ 'name' => 'San Marino', 'alpha2' => 'SM', 'alpha3' => 'SMR', 'currency' => 'EUR', ],
		'SN' => [ 'name' => 'Senegal', 'alpha2' => 'SN', 'alpha3' => 'SEN', 'currency' => 'XOF', ],
		'SO' => [ 'name' => 'Somalia', 'alpha2' => 'SO', 'alpha3' => 'SOM', 'currency' => 'SOS', ],
		'SR' => [ 'name' => 'Suriname', 'alpha2' => 'SR', 'alpha3' => 'SUR', 'currency' => 'SRD', ],
		'SS' => [ 'name' => 'South Sudan', 'alpha2' => 'SS', 'alpha3' => 'SSD', 'currency' => 'SSP', ],
		'ST' => [ 'name' => 'Sao Tome and Principe', 'alpha2' => 'ST', 'alpha3' => 'STP', 'currency' => 'STD', ],
		'SV' => [ 'name' => 'El Salvador', 'alpha2' => 'SV', 'alpha3' => 'SLV', 'currency' => 'USD', ],
		'SX' => [ 'name' => 'Sint Maarten (Dutch part)', 'alpha2' => 'SX', 'alpha3' => 'SXM', 'currency' => 'ANG', ],
		'SY' => [ 'name' => 'Syrian Arab Republic', 'alpha2' => 'SY', 'alpha3' => 'SYR', 'currency' => 'SYP', ],
		'SZ' => [ 'name' => 'Eswatini', 'alpha2' => 'SZ', 'alpha3' => 'SWZ', 'currency' => 'SZL', ],
		'TC' => [ 'name' => 'Turks and Caicos Islands', 'alpha2' => 'TC', 'alpha3' => 'TCA', 'currency' => 'USD', ],
		'TD' => [ 'name' => 'Chad', 'alpha2' => 'TD', 'alpha3' => 'TCD', 'currency' => 'XAF', ],
		'TF' => [ 'name' => 'French Southern Territories', 'alpha2' => 'TF', 'alpha3' => 'ATF', 'currency' => 'EUR', ],
		'TG' => [ 'name' => 'Togo', 'alpha2' => 'TG', 'alpha3' => 'TGO', 'currency' => 'XOF', ],
		'TH' => [ 'name' => 'Thailand', 'alpha2' => 'TH', 'alpha3' => 'THA', 'currency' => 'THB', ],
		'TJ' => [ 'name' => 'Tajikistan', 'alpha2' => 'TJ', 'alpha3' => 'TJK', 'currency' => 'TJS', ],
		'TK' => [ 'name' => 'Tokelau', 'alpha2' => 'TK', 'alpha3' => 'TKL', 'currency' => 'NZD', ],
		'TL' => [ 'name' => 'Timor-Leste', 'alpha2' => 'TL', 'alpha3' => 'TLS', 'currency' => 'USD', ],
		'TM' => [ 'name' => 'Turkmenistan', 'alpha2' => 'TM', 'alpha3' => 'TKM', 'currency' => 'TMT', ],
		'TN' => [ 'name' => 'Tunisia', 'alpha2' => 'TN', 'alpha3' => 'TUN', 'currency' => 'TND', ],
		'TO' => [ 'name' => 'Tonga', 'alpha2' => 'TO', 'alpha3' => 'TON', 'currency' => 'TOP', ],
		'TR' => [ 'name' => 'Turkey', 'alpha2' => 'TR', 'alpha3' => 'TUR', 'currency' => 'TRY', ],
		'TT' => [ 'name' => 'Trinidad and Tobago', 'alpha2' => 'TT', 'alpha3' => 'TTO', 'currency' => 'TTD', ],
		'TV' => [ 'name' => 'Tuvalu', 'alpha2' => 'TV', 'alpha3' => 'TUV', 'currency' => 'AUD', ],
		'TW' => [ 'name' => 'Taiwan, Province of China', 'alpha2' => 'TW', 'alpha3' => 'TWN', 'currency' => 'TWD', ],
		'TZ' => [ 'name' => 'Tanzania, United Republic of', 'alpha2' => 'TZ', 'alpha3' => 'TZA', 'currency' => 'TZS', ],
		'UA' => [ 'name' => 'Ukraine', 'alpha2' => 'UA', 'alpha3' => 'UKR', 'currency' => 'UAH', ],
		'UG' => [ 'name' => 'Uganda', 'alpha2' => 'UG', 'alpha3' => 'UGA', 'currency' => 'UGX', ],
		'UM' => [ 'name' => 'United States Minor Outlying Islands', 'alpha2' => 'UM', 'alpha3' => 'UMI', 'currency' => 'USD', ],
		'US' => [ 'name' => 'United States of America', 'alpha2' => 'US', 'alpha3' => 'USA', 'currency' => 'USD', ],
		'UY' => [ 'name' => 'Uruguay', 'alpha2' => 'UY', 'alpha3' => 'URY', 'currency' => 'UYU', ],
		'UZ' => [ 'name' => 'Uzbekistan', 'alpha2' => 'UZ', 'alpha3' => 'UZB', 'currency' => 'UZS', ],
		'VA' => [ 'name' => 'Holy See', 'alpha2' => 'VA', 'alpha3' => 'VAT', 'currency' => 'EUR', ],
		'VC' => [ 'name' => 'Saint Vincent and the Grenadines', 'alpha2' => 'VC', 'alpha3' => 'VCT', 'currency' => 'XCD', ],
		'VE' => [ 'name' => 'Venezuela (Bolivarian Republic of)', 'alpha2' => 'VE', 'alpha3' => 'VEN', 'currency' => 'VEF', ],
		'VG' => [ 'name' => 'Virgin Islands (British)', 'alpha2' => 'VG', 'alpha3' => 'VGB', 'currency' => 'USD', ],
		'VI' => [ 'name' => 'Virgin Islands (U.S.)', 'alpha2' => 'VI', 'alpha3' => 'VIR', 'currency' => 'USD', ],
		'VN' => [ 'name' => 'Viet Nam', 'alpha2' => 'VN', 'alpha3' => 'VNM', 'currency' => 'VND', ],
		'VU' => [ 'name' => 'Vanuatu', 'alpha2' => 'VU', 'alpha3' => 'VUT', 'currency' => 'VUV', ],
		'WF' => [ 'name' => 'Wallis and Futuna', 'alpha2' => 'WF', 'alpha3' => 'WLF', 'currency' => 'XPF', ],
		'WS' => [ 'name' => 'Samoa', 'alpha2' => 'WS', 'alpha3' => 'WSM', 'currency' => 'WST', ],
		'XK' => [ 'name' => 'Kosovo', 'alpha2' => 'XK', 'alpha3' => 'XKX', 'currency' => 'EUR', ],
		'YE' => [ 'name' => 'Yemen', 'alpha2' => 'YE', 'alpha3' => 'YEM', 'currency' => 'YER', ],
		'YT' => [ 'name' => 'Mayotte', 'alpha2' => 'YT', 'alpha3' => 'MYT', 'currency' => 'EUR', ],
		'ZA' => [ 'name' => 'South Africa', 'alpha2' => 'ZA', 'alpha3' => 'ZAF', 'currency' => 'ZAR', ],
		'ZM' => [ 'name' => 'Zambia', 'alpha2' => 'ZM', 'alpha3' => 'ZMB', 'currency' => 'ZMK', ],
		'ZW' => [ 'name' => 'Zimbabwe', 'alpha2' => 'ZW', 'alpha3' => 'ZWE', 'currency' => 'ZWL', ],
	];

	return ( ! empty( $alpha2 ) && isset( $countries[ $alpha2 ] ) )
		? $countries[ $alpha2 ]
		: false;
}

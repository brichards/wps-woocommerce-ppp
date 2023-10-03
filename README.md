# Purchasing Power Parity for WooCommerce
Converts all product prices to an equivalent rate for visitors based on their local [purchasing power parity](https://en.wikipedia.org/wiki/Purchasing_power_parity) (PPP).

This [WooCommerce](https://woocommerce.com) extension was developed exlusively for use on [WPSessions.com](https://WPSessions.com) and provides visitors around the world with pricing that delivers the equivalent spend to their US counterparts based on their current economic buying power.

This plugin makes one API call to determine the relative exchange rate and a second API call to get the relative purchasing power between a given country and the US. These two numbers together allow us to calculate the "PPP Rate," which is the percentage a person should pay for any given product price.

The code ensures that no one will ever pay greater than 100% of the original price, nor will they pay less than 10% of the original price. If the PPP rate causes the product price to change, a helper function shows the price drop similar to other sale prices throughout WooCommerce.

With this plugin, the store can operate and manage inventory using regular and sale pricing as usual and customers will automatically see pricing adjusted to their local buying power (but still in the store's default currency).

# Considerations
Becuase this plugin was written for an audience of one (me), I took some liberties hard-coding the API requests to utilize US as the base/relative comparison. If you would like to utilize this and pick another country as the base, you'll need to modify a few lines that explicitly set `US` or `USA` as default values **and also** change the two API requests.

# See it in action
If you're outside the US, and in a country whose buying power is currently lower than the US, you'll automatically see pricing at [https://WPSessions.com/join/](https://WPSessions.com/join/) updated to match your PPP.

# Contributions Welcome!
If you've found a bug, or something that can be improved, please open a pull request and document your changes.

This should follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/) (WPCS), but sometimes I move fast and loose and am imperfect. However, I would appreciate any pull requests to adhere to the WPCS.

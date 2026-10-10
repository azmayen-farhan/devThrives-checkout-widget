<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Core {

	/* =====================================================================
	 * Setup
	 * ===================================================================== */

	public static function init() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );

		$actions = [
			'dtc_refresh'     => 'ajax_refresh',
			'dtc_place_order' => 'ajax_place_order',
			'dtc_nonce'       => 'ajax_nonce',
		];
		foreach ( $actions as $action => $callback ) {
			add_action( 'wp_ajax_' . $action, [ __CLASS__, $callback ] );
			add_action( 'wp_ajax_nopriv_' . $action, [ __CLASS__, $callback ] );
		}
	}

	public static function register_assets() {
		wp_register_style( 'dtc-style', DTC_URL . 'assets/css/dtc.css', [], DTC_VERSION );
		wp_register_script( 'dtc-script', DTC_URL . 'assets/js/dtc.js', [], DTC_VERSION, true );
		wp_localize_script( 'dtc-script', 'dtcData', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'dtc_checkout' ),
			'i18n'    => [
				'select' => __( 'Select...', 'dt-checkout' ),
				'error'  => __( 'Something went wrong. Please try again.', 'dt-checkout' ),
			],
		] );

		// Thank-you page order summary widget.
		wp_register_style( 'dts-style', DTC_URL . 'assets/css/dts.css', [], DTC_VERSION );
		wp_register_script( 'dts-script', DTC_URL . 'assets/js/dts.js', [], DTC_VERSION, true );
	}

	/* =====================================================================
	 * Config (single source of truth for texts + flags used by AJAX)
	 * ===================================================================== */

	/**
	 * Form fields. key => [ admin label, show, required, default label, default placeholder ]
	 */
	public static function field_defs() {
		return [
			'name'     => [ 'Name', 1, 1, 'আপনার নাম', 'আপনার নাম লিখুন' ],
			'phone'    => [ 'Phone', 1, 1, 'আপনার ফোন নাম্বার', 'আপনার ফোন নম্বর দিন (WhatsApp যদি থাকে)' ],
			'email'    => [ 'Email', 1, 0, 'ইমেইল ঠিকানা', 'john@example.com' ],
			'address'  => [ 'Full Address', 1, 1, 'আপনার সম্পূর্ণ ঠিকানা', 'বাসা #, রোড #, এলাকা...' ],
			'city'     => [ 'City', 0, 0, 'শহর / জেলা', 'আপনার শহর বা জেলা' ],
			'postcode' => [ 'Postcode', 0, 0, 'পোস্টকোড', '' ],
			'country'  => [ 'Country', 0, 0, 'দেশ', '' ],
			'state'    => [ 'State / District', 0, 0, 'বিভাগ / জেলা', '' ],
			'notes'    => [ 'Order Notes', 0, 0, 'অর্ডার নোট', 'আপনার অর্ডার সম্পর্কে কোনো নোট থাকলে লিখুন' ],
		];
	}

	public static function default_cfg() {
		$cfg = [
			'source'            => 'manual',
			'hide_radio_single' => true,
			'show_thumb'        => true,
			'country'           => '',
			'col_product'       => 'Product',
			'col_subtotal'      => 'Subtotal',
			'row_subtotal'      => 'Subtotal',
			'row_shipping'      => 'Shipping',
			'row_discount'      => 'Discount',
			'row_total'         => 'Total',
			'text_free'         => 'Free',
			'shipping_heading'  => 'Shipping Method',
			'payment_heading'   => '',
			'msg_empty'         => 'Please select a product.',
			'msg_no_shipping'   => 'No shipping options are available for your address.',
			'msg_no_payment'    => 'No payment methods are available.',
			'button_text'       => 'Place Order · {total}',
			'button_loading'    => 'Processing...',
			'thankyou_page'     => '',
		];
		foreach ( self::field_defs() as $key => $def ) {
			$cfg[ "f_{$key}_show" ] = (bool) $def[1];
			$cfg[ "f_{$key}_req" ]  = (bool) $def[2];
		}
		return $cfg;
	}

	/** Build the config from Elementor settings (switchers are 'yes' or ''). */
	public static function cfg_from_settings( $s ) {
		$cfg = self::default_cfg();
		foreach ( $cfg as $key => $default ) {
			if ( ! isset( $s[ $key ] ) ) {
				continue;
			}
			$cfg[ $key ] = is_bool( $default ) ? ( 'yes' === $s[ $key ] ) : trim( wp_strip_all_tags( (string) $s[ $key ] ) );
		}
		if ( ! in_array( $cfg['source'], [ 'manual', 'query', 'current', 'cart' ], true ) ) {
			$cfg['source'] = 'manual';
		}
		return $cfg;
	}

	/** Clean config that came back from the browser. */
	public static function sanitize_cfg( $raw ) {
		$out = [];
		foreach ( self::default_cfg() as $key => $default ) {
			if ( ! isset( $raw[ $key ] ) ) {
				$out[ $key ] = $default;
				continue;
			}
			$out[ $key ] = is_bool( $default )
				? (bool) filter_var( $raw[ $key ], FILTER_VALIDATE_BOOLEAN )
				: sanitize_text_field( (string) $raw[ $key ] );
		}
		if ( ! in_array( $out['source'], [ 'manual', 'query', 'current', 'cart' ], true ) ) {
			$out['source'] = 'manual';
		}
		return $out;
	}

	/* =====================================================================
	 * Option lists for the Elementor panel
	 * ===================================================================== */

	public static function product_options() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY post_title ASC LIMIT 1000" );
		$out  = [];
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$out[ (string) $row->ID ] = $row->post_title . ' (#' . $row->ID . ')';
			}
		}
		return $out;
	}

	public static function term_options( $taxonomy ) {
		$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
		$out   = [];
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$out[ $term->slug ] = $term->name;
			}
		}
		return $out;
	}

	public static function country_options() {
		$out = [ '' => __( 'Store base country', 'dt-checkout' ) ];
		if ( function_exists( 'WC' ) && WC()->countries ) {
			foreach ( WC()->countries->get_allowed_countries() as $code => $name ) {
				$out[ $code ] = $name;
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Products
	 * ===================================================================== */

	public static function get_products( $s ) {
		$source   = isset( $s['source'] ) ? $s['source'] : 'manual';
		$hide_oos = ( isset( $s['hide_out_of_stock'] ) ? $s['hide_out_of_stock'] : 'yes' ) === 'yes';
		$ids      = [];

		if ( 'manual' === $source ) {
			$ids = array_map( 'absint', (array) ( isset( $s['manual_products'] ) ? $s['manual_products'] : [] ) );
		} elseif ( 'current' === $source ) {
			$id = get_queried_object_id();
			if ( ! $id || 'product' !== get_post_type( $id ) ) {
				global $product;
				$id = ( $product instanceof WC_Product ) ? $product->get_id() : get_the_ID();
			}
			$ids = [ absint( $id ) ];
		} elseif ( 'query' === $source ) {
			$ids = self::query_ids( $s, $hide_oos );
		}

		$out = [];
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || 'publish' !== $p->get_status() || $p->is_type( 'variation' ) ) {
				continue;
			}
			if ( ! $p->is_purchasable() || ( $hide_oos && ! $p->is_in_stock() ) ) {
				continue;
			}
			$out[] = $p;
		}
		return $out;
	}

	private static function query_ids( $s, $hide_oos ) {
		$args = [
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'posts_per_page'      => max( 1, (int) ( isset( $s['query_limit'] ) ? $s['query_limit'] : 6 ) ),
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		];

		$tax = [];
		if ( ! empty( $s['query_categories'] ) ) {
			$tax[] = [ 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => (array) $s['query_categories'] ];
		}
		if ( ! empty( $s['query_tags'] ) ) {
			$tax[] = [ 'taxonomy' => 'product_tag', 'field' => 'slug', 'terms' => (array) $s['query_tags'] ];
		}
		if ( ! empty( $s['query_featured'] ) && 'yes' === $s['query_featured'] ) {
			$tax[] = [ 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => 'featured' ];
		}
		if ( $tax ) {
			$args['tax_query'] = array_merge( [ 'relation' => 'AND' ], $tax );
		}

		if ( ! empty( $s['query_on_sale'] ) && 'yes' === $s['query_on_sale'] ) {
			$sale_ids         = wc_get_product_ids_on_sale();
			$args['post__in'] = $sale_ids ? $sale_ids : [ 0 ];
		}
		if ( ! empty( $s['query_exclude'] ) ) {
			$args['post__not_in'] = array_map( 'absint', (array) $s['query_exclude'] );
		}
		if ( $hide_oos ) {
			$args['meta_query'] = [ [ 'key' => '_stock_status', 'value' => 'outofstock', 'compare' => '!=' ] ];
		}

		$orderby = isset( $s['query_orderby'] ) ? $s['query_orderby'] : 'date';
		$args['order'] = ( isset( $s['query_order'] ) && 'ASC' === $s['query_order'] ) ? 'ASC' : 'DESC';
		switch ( $orderby ) {
			case 'title':
				$args['orderby'] = 'title';
				break;
			case 'price':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = '_price';
				break;
			case 'popularity':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = 'total_sales';
				break;
			case 'rand':
				$args['orderby'] = 'rand';
				break;
			case 'menu_order':
				$args['orderby'] = 'menu_order title';
				break;
			default:
				$args['orderby'] = 'date';
		}

		$query = new WP_Query( $args );
		return $query->posts;
	}

	public static function price_html( $p ) {
		if ( $p->is_type( 'variable' ) ) {
			return $p->get_price_html();
		}
		$now = wc_get_price_to_display( $p );
		if ( $p->is_on_sale() && '' !== $p->get_regular_price() ) {
			$old = wc_get_price_to_display( $p, [ 'price' => $p->get_regular_price() ] );
			if ( $old > $now ) {
				return '<span class="dtc-price-now">' . wc_price( $now ) . '</span><del class="dtc-price-old">' . wc_price( $old ) . '</del>';
			}
		}
		return '<span class="dtc-price-now">' . wc_price( $now ) . '</span>';
	}

	private static function variation_options( $p ) {
		$out = [];
		foreach ( array_slice( $p->get_children(), 0, 60 ) as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || ! $v->exists() || ! $v->is_purchasable() || ! $v->is_in_stock() ) {
				continue;
			}
			// Skip "Any ..." variations because they need an extra choice.
			$skip = false;
			foreach ( $v->get_attributes() as $value ) {
				if ( '' === $value ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}
			$label = trim( wp_strip_all_tags( wc_get_formatted_variation( $v, true, false ) ) );
			if ( '' === $label ) {
				$label = $v->get_name();
			}
			$out[] = [
				'id'    => (int) $vid,
				'label' => $label,
				'price' => self::price_html( $v ),
			];
		}
		return $out;
	}

	/* =====================================================================
	 * Cart helpers
	 * ===================================================================== */

	private static function boot_cart() {
		if ( function_exists( 'wc_load_cart' ) && ( null === WC()->cart || null === WC()->session ) ) {
			wc_load_cart();
		}
	}

	/**
	 * Remove error notices that were queued before this request started.
	 *
	 * Other plugins (CartFlows, for example, queues "No product is selected. Please select
	 * products from the checkout meta settings to continue." on its own checkout steps) can
	 * leave an error sitting in the WooCommerce session. WooCommerce counts errors before it
	 * creates an order, so one stale error blocks a perfectly valid order. This widget builds
	 * and checks its own cart, so only errors raised during the current request should count.
	 * Success and info notices are left alone.
	 */
	private static function drop_stale_errors() {
		if ( ! function_exists( 'wc_get_notices' ) ) {
			return;
		}
		$notices = wc_get_notices();
		if ( empty( $notices['error'] ) ) {
			return;
		}
		unset( $notices['error'] );
		if ( function_exists( 'wc_set_notices' ) ) {
			wc_set_notices( $notices );
		} else {
			wc_clear_notices();
		}
	}

	/**
	 * Replace the cart with the chosen items. Returns error text so we can show it.
	 */
	private static function build_cart( $items, $coupon ) {
		$snapshot = function_exists( 'wc_get_notices' ) ? wc_get_notices() : [];
		wc_clear_notices();

		$cart = WC()->cart;
		$cart->empty_cart();

		foreach ( $items as $item ) {
			$pid = (int) $item['id'];
			$vid = (int) $item['variation_id'];
			$qty = max( 1, min( 999, (int) $item['qty'] ) );
			if ( $pid < 1 ) {
				continue;
			}
			$cart->add_to_cart( $pid, $qty, $vid );
		}

		$errors = [];
		foreach ( wc_get_notices( 'error' ) as $notice ) {
			$errors[] = wp_strip_all_tags( is_array( $notice ) ? $notice['notice'] : $notice );
		}
		wc_clear_notices();

		$coupon_msg = '';
		if ( '' !== $coupon && ! $cart->is_empty() ) {
			if ( ! $cart->apply_coupon( wc_format_coupon_code( $coupon ) ) ) {
				foreach ( wc_get_notices( 'error' ) as $notice ) {
					$coupon_msg .= wp_strip_all_tags( is_array( $notice ) ? $notice['notice'] : $notice ) . ' ';
				}
			}
			wc_clear_notices();
		}

		if ( function_exists( 'wc_set_notices' ) ) {
			wc_set_notices( $snapshot );
		}

		return [ 'errors' => $errors, 'coupon_msg' => trim( $coupon_msg ) ];
	}

	private static function resolve_country( $cfg, $loc ) {
		$sell    = WC()->countries->get_allowed_countries();
		$country = '';
		if ( ! empty( $cfg['f_country_show'] ) && ! empty( $loc['billing_country'] ) ) {
			$country = strtoupper( sanitize_text_field( wp_unslash( $loc['billing_country'] ) ) );
		}
		if ( ! isset( $sell[ $country ] ) ) {
			$country = $cfg['country'];
		}
		if ( ! isset( $sell[ $country ] ) ) {
			$country = WC()->countries->get_base_country();
		}
		if ( ! isset( $sell[ $country ] ) ) {
			$keys    = array_keys( $sell );
			$country = $keys ? $keys[0] : '';
		}
		return $country;
	}

	private static function apply_location( $cfg, $loc ) {
		$country  = self::resolve_country( $cfg, $loc );
		$state    = ( ! empty( $cfg['f_state_show'] ) && isset( $loc['billing_state'] ) ) ? sanitize_text_field( wp_unslash( $loc['billing_state'] ) ) : '';
		$postcode = ( ! empty( $cfg['f_postcode_show'] ) && isset( $loc['billing_postcode'] ) ) ? sanitize_text_field( wp_unslash( $loc['billing_postcode'] ) ) : '';
		$city     = ( ! empty( $cfg['f_city_show'] ) && isset( $loc['billing_city'] ) ) ? sanitize_text_field( wp_unslash( $loc['billing_city'] ) ) : '';

		$customer = WC()->customer;
		foreach ( [ 'billing', 'shipping' ] as $type ) {
			$customer->{"set_{$type}_country"}( $country );
			$customer->{"set_{$type}_state"}( $state );
			$customer->{"set_{$type}_postcode"}( $postcode );
			$customer->{"set_{$type}_city"}( $city );
		}
		return $country;
	}

	/* =====================================================================
	 * Main calculation: returns every HTML piece the widget needs
	 * ===================================================================== */

	public static function compute( $cfg, $req ) {
		self::boot_cart();

		$out = [
			'items'       => '',
			'shipping'    => '',
			'totals'      => '',
			'payment'     => '',
			'payment_sig' => 'none',
			'button'      => '',
			'empty'       => false,
			'error'       => '',
			'coupon_msg'  => '',
		];

		$cart   = WC()->cart;
		$errors = [];

		if ( 'cart' !== $cfg['source'] ) {
			$built             = self::build_cart( $req['items'], $req['coupon'] );
			$errors            = $built['errors'];
			$out['coupon_msg'] = $built['coupon_msg'];
		}

		if ( $cart->is_empty() ) {
			$out['empty']  = true;
			$out['items']  = '<p class="dtc-empty">' . esc_html( $cfg['msg_empty'] ) . '</p>';
			$out['button'] = self::button_label( $cfg, wc_price( 0 ) );
			$out['error']  = implode( ' ', $errors );
			return $out;
		}

		self::apply_location( $cfg, $req['loc'] );
		$cart->calculate_totals();

		$rates  = [];
		$chosen = '';
		if ( $cart->needs_shipping() ) {
			$packages       = WC()->shipping()->get_packages();
			$chosen_methods = [];
			foreach ( $packages as $i => $package ) {
				$ids  = array_keys( $package['rates'] );
				$want = ( 0 === (int) $i ) ? $req['shipping'] : '';
				$chosen_methods[ $i ] = in_array( $want, $ids, true ) ? $want : ( $ids ? $ids[0] : '' );
			}
			if ( $packages ) {
				WC()->session->set( 'chosen_shipping_methods', $chosen_methods );
				$cart->calculate_totals();
				$first  = reset( $packages );
				$rates  = $first['rates'];
				$chosen = isset( $chosen_methods[0] ) ? $chosen_methods[0] : '';
			}
		}

		$out['items']    = self::html_items( $cfg, $cart );
		$out['shipping'] = $cart->needs_shipping() ? self::html_shipping( $cfg, $rates, $chosen ) : '';
		$out['totals']   = self::html_totals( $cfg, $cart, ! empty( $rates ) );

		$pay                = self::html_payment( $cfg, $cart, $req['payment'] );
		$out['payment']     = $pay['html'];
		$out['payment_sig'] = $pay['sig'];

		$out['button'] = self::button_label( $cfg, $cart->get_total() );
		$out['error']  = implode( ' ', $errors );
		return $out;
	}

	/* =====================================================================
	 * HTML pieces
	 * ===================================================================== */

	private static function button_label( $cfg, $total_html ) {
		$parts = explode( '{total}', $cfg['button_text'], 2 );
		if ( 2 === count( $parts ) ) {
			return esc_html( $parts[0] ) . '<span class="dtc-btn-total">' . $total_html . '</span>' . esc_html( $parts[1] );
		}
		return esc_html( $cfg['button_text'] );
	}

	private static function html_items( $cfg, $cart ) {
		ob_start();
		echo '<div class="dtc-sum-head"><span>' . esc_html( $cfg['col_product'] ) . '</span><span>' . esc_html( $cfg['col_subtotal'] ) . '</span></div>';
		foreach ( $cart->get_cart() as $item ) {
			$p = $item['data'];
			if ( ! $p || ! $p->exists() ) {
				continue;
			}
			$parent = $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : $p;
			$name   = $parent ? $parent->get_name() : $p->get_name();
			$meta   = wp_strip_all_tags( wc_get_formatted_cart_item_data( $item, true ) );
			$meta   = trim( preg_replace( '/\s*[\r\n]+\s*/', ', ', trim( $meta ) ), ', ' );

			echo '<div class="dtc-sum-item">';
			if ( $cfg['show_thumb'] ) {
				echo '<div class="dtc-sum-thumb">' . wp_kses_post( $p->get_image( 'thumbnail' ) ) . '</div>';
			}
			echo '<div class="dtc-sum-info"><div class="dtc-sum-name">' . esc_html( $name ) . '</div>';
			if ( '' !== $meta ) {
				echo '<div class="dtc-sum-meta">' . esc_html( $meta ) . '</div>';
			}
			echo '</div>';
			echo '<div class="dtc-sum-line"><span class="dtc-sum-qty">&times; ' . (int) $item['quantity'] . '</span><span class="dtc-sum-price">' . wp_kses_post( $cart->get_product_subtotal( $p, $item['quantity'] ) ) . '</span></div>';
			echo '</div>';
		}
		return ob_get_clean();
	}

	private static function html_shipping( $cfg, $rates, $chosen ) {
		ob_start();
		if ( '' !== $cfg['shipping_heading'] ) {
			echo '<h3 class="dtc-sec-title">' . esc_html( $cfg['shipping_heading'] ) . '</h3>';
		}
		if ( ! $rates ) {
			echo '<p class="dtc-note">' . esc_html( $cfg['msg_no_shipping'] ) . '</p>';
			return ob_get_clean();
		}
		$incl = WC()->cart->display_prices_including_tax();
		echo '<div class="dtc-ship-list">';
		foreach ( $rates as $id => $rate ) {
			$cost = (float) $rate->get_cost();
			if ( $incl ) {
				$cost += (float) $rate->get_shipping_tax();
			}
			$active = ( (string) $id === (string) $chosen );
			echo '<label class="dtc-ship-item' . ( $active ? ' is-active' : '' ) . '">';
			echo '<input type="radio" name="shipping_method[0]" value="' . esc_attr( $id ) . '" ' . checked( $active, true, false ) . '>';
			echo '<span class="dtc-dot"></span>';
			echo '<span class="dtc-ship-label">' . esc_html( $rate->get_label() ) . '</span>';
			echo '<span class="dtc-ship-cost">' . ( $cost > 0 ? wp_kses_post( wc_price( $cost ) ) : esc_html( $cfg['text_free'] ) ) . '</span>';
			echo '</label>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	private static function html_totals( $cfg, $cart, $has_rates ) {
		$incl = $cart->display_prices_including_tax();
		$row  = function ( $label, $value, $class = '' ) {
			return '<div class="dtc-total-row ' . esc_attr( $class ) . '"><span>' . $label . '</span><span>' . $value . '</span></div>';
		};

		ob_start();
		echo '<div class="dtc-totals">';
		echo $row( esc_html( $cfg['row_subtotal'] ), wp_kses_post( $cart->get_cart_subtotal() ) ); // phpcs:ignore

		foreach ( $cart->get_coupons() as $code => $coupon ) {
			$amount = $cart->get_coupon_discount_amount( $code, ! $incl );
			$label  = esc_html( $cfg['row_discount'] ) . ' <small>(' . esc_html( $code ) . ')</small> <a href="#" class="dtc-coupon-remove" aria-label="Remove coupon">&times;</a>';
			echo $row( $label, '-' . wp_kses_post( wc_price( $amount ) ), 'is-discount' ); // phpcs:ignore
		}

		foreach ( $cart->get_fees() as $fee ) {
			echo $row( esc_html( $fee->name ), wp_kses_post( wc_price( $fee->total + ( $incl ? $fee->tax : 0 ) ) ) ); // phpcs:ignore
		}

		if ( $cart->needs_shipping() ) {
			if ( $has_rates ) {
				$ship = (float) $cart->get_shipping_total();
				if ( $incl ) {
					$ship += (float) $cart->get_shipping_tax();
				}
				$ship_html = $ship > 0 ? wp_kses_post( wc_price( $ship ) ) : esc_html( $cfg['text_free'] );
			} else {
				$ship_html = '&mdash;';
			}
			echo $row( esc_html( $cfg['row_shipping'] ), $ship_html ); // phpcs:ignore
		}

		if ( wc_tax_enabled() && ! $incl ) {
			foreach ( $cart->get_tax_totals() as $tax ) {
				echo $row( esc_html( $tax->label ), wp_kses_post( $tax->formatted_amount ) ); // phpcs:ignore
			}
		}

		echo $row( esc_html( $cfg['row_total'] ), wp_kses_post( $cart->get_total() ), 'is-total' ); // phpcs:ignore
		echo '</div>';
		return ob_get_clean();
	}

	private static function html_payment( $cfg, $cart, $selected ) {
		if ( ! $cart->needs_payment() ) {
			return [ 'html' => '', 'sig' => 'free' ];
		}

		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		ob_start();
		if ( '' !== $cfg['payment_heading'] ) {
			echo '<h3 class="dtc-sec-title">' . esc_html( $cfg['payment_heading'] ) . '</h3>';
		}
		if ( ! $gateways ) {
			echo '<p class="dtc-note">' . esc_html( $cfg['msg_no_payment'] ) . '</p>';
			return [ 'html' => ob_get_clean(), 'sig' => 'empty' ];
		}

		$ids    = array_keys( $gateways );
		$chosen = isset( $gateways[ $selected ] ) ? $selected : $ids[0];
		$single = ( 1 === count( $gateways ) && $cfg['hide_radio_single'] );

		echo '<div class="dtc-pay-list' . ( $single ? ' is-single' : '' ) . '">';
		foreach ( $gateways as $id => $gateway ) {
			$active = ( (string) $id === (string) $chosen );
			echo '<div class="dtc-pay-item' . ( $active ? ' is-active' : '' ) . '">';
			echo '<label class="dtc-pay-head">';
			echo '<input type="radio" class="dtc-pay-input" name="payment_method" value="' . esc_attr( $id ) . '" ' . checked( $active, true, false ) . '>';
			echo '<span class="dtc-pay-title">' . wp_kses_post( $gateway->get_title() ) . '</span>';
			echo '<span class="dtc-pay-icon">' . $gateway->get_icon() . '</span>'; // phpcs:ignore
			echo '</label>';

			$box = '';
			try {
				ob_start();
				$gateway->payment_fields();
				$box = trim( ob_get_clean() );
			} catch ( \Throwable $e ) {
				ob_end_clean();
			}
			if ( '' !== $box ) {
				echo '<div class="dtc-pay-box">' . $box . '</div>'; // phpcs:ignore
			}
			echo '</div>';
		}
		echo '</div>';

		return [ 'html' => ob_get_clean(), 'sig' => md5( implode( '|', $ids ) ) ];
	}

	/* =====================================================================
	 * Frontend render (called by the Elementor widget)
	 * ===================================================================== */

	public static function render( $s, $uid ) {
		try {
			self::render_inner( $s, $uid );
		} catch ( \Throwable $e ) {
			if ( current_user_can( 'manage_options' ) ) {
				echo '<div class="dtc-wrap"><div class="dtc-notices">' . esc_html( $e->getMessage() ) . '</div></div>';
			}
		}
	}

	private static function render_inner( $s, $uid ) {
		self::boot_cart();

		$cfg      = self::cfg_from_settings( $s );
		$source   = $cfg['source'];
		$multi    = ( isset( $s['selection'] ) ? $s['selection'] : 'single' ) === 'multiple';
		$show_sel = ( isset( $s['show_selector'] ) ? $s['show_selector'] : 'yes' ) === 'yes';
		$default  = isset( $s['default_selected'] ) ? $s['default_selected'] : 'first';

		// Build the product cards.
		$cards = [];
		if ( 'cart' !== $source ) {
			foreach ( self::get_products( $s ) as $p ) {
				$vopts = $p->is_type( 'variable' ) ? self::variation_options( $p ) : [];
				if ( $p->is_type( 'variable' ) && ! $vopts ) {
					continue;
				}
				$cards[] = [ 'p' => $p, 'vopts' => $vopts ];
			}
			if ( ! $cards ) {
				if ( current_user_can( 'manage_options' ) ) {
					echo '<div class="dtc-wrap"><div class="dtc-notices">DT Checkout: no products found. Open the "Products / Query" section and choose products.</div></div>';
				}
				return;
			}
		}

		// Work out which cards start selected.
		$items = [];
		foreach ( $cards as $i => &$card ) {
			if ( 'none' === $default ) {
				$on = false;
			} elseif ( 'all' === $default && $multi ) {
				$on = true;
			} else {
				$on = ( 0 === $i );
			}
			$card['on'] = $on;
			if ( $on ) {
				$items[] = [
					'id'           => $card['p']->get_id(),
					'variation_id' => $card['vopts'] ? $card['vopts'][0]['id'] : 0,
					'qty'          => 1,
				];
			}
		}
		unset( $card );

		$country = self::resolve_country( $cfg, [] );
		$data    = self::compute( $cfg, [
			'items'    => $items,
			'shipping' => '',
			'payment'  => '',
			'coupon'   => '',
			'loc'      => [],
		] );

		echo '<div class="dtc-wrap" data-config="' . esc_attr( wp_json_encode( $cfg ) ) . '">';
		echo '<form class="dtc-form" action="#" method="post">';
		echo '<div class="dtc-notices" role="alert" hidden></div>';

		if ( ! empty( $s['main_heading'] ) ) {
			echo '<h2 class="dtc-title">' . esc_html( $s['main_heading'] ) . '</h2>';
		}

		echo '<div class="dtc-grid"><div class="dtc-left">';

		// Product selection.
		if ( $cards ) {
			echo '<div class="dtc-block dtc-select-block"' . ( $show_sel ? '' : ' style="display:none"' ) . '>';
			if ( ! empty( $s['select_heading'] ) ) {
				echo '<h3 class="dtc-sec-title">' . esc_html( $s['select_heading'] ) . '</h3>';
			}
			echo '<div class="dtc-products" data-default="' . esc_attr( $default ) . '" data-multi="' . ( $multi ? '1' : '0' ) . '">';
			foreach ( $cards as $card ) {
				self::render_card( $card, $multi, $s );
			}
			echo '</div></div>';
		}

		// Billing form.
		echo '<div class="dtc-block dtc-form-block">';
		if ( ! empty( $s['form_heading'] ) ) {
			echo '<h3 class="dtc-sec-title">' . esc_html( $s['form_heading'] ) . '</h3>';
		}
		self::render_fields( $s, $cfg, $uid, $country );
		echo '</div>';
		echo '</div>'; // .dtc-left

		// Right column.
		echo '<div class="dtc-right">';
		if ( ! empty( $s['summary_heading'] ) ) {
			echo '<h3 class="dtc-sec-title">' . esc_html( $s['summary_heading'] ) . '</h3>';
		}
		echo '<div class="dtc-js-items">' . $data['items'] . '</div>'; // phpcs:ignore
		echo '<div class="dtc-ship dtc-js-shipping">' . $data['shipping'] . '</div>'; // phpcs:ignore
		echo '<div class="dtc-js-totals">' . $data['totals'] . '</div>'; // phpcs:ignore

		if ( ( isset( $s['show_coupon'] ) ? $s['show_coupon'] : '' ) === 'yes' ) {
			echo '<div class="dtc-coupon">';
			echo '<button type="button" class="dtc-coupon-toggle">' . esc_html( isset( $s['coupon_toggle'] ) ? $s['coupon_toggle'] : 'Have a coupon?' ) . '</button>';
			echo '<div class="dtc-coupon-form">';
			echo '<input type="text" class="dtc-input dtc-coupon-input" autocomplete="off" placeholder="' . esc_attr( isset( $s['coupon_placeholder'] ) ? $s['coupon_placeholder'] : 'Coupon code' ) . '">';
			echo '<button type="button" class="dtc-coupon-apply">' . esc_html( isset( $s['coupon_apply'] ) ? $s['coupon_apply'] : 'Apply' ) . '</button>';
			echo '</div><div class="dtc-coupon-msg"></div></div>';
		}

		echo '<div class="dtc-pay dtc-js-payment" data-sig="' . esc_attr( $data['payment_sig'] ) . '">' . $data['payment'] . '</div>'; // phpcs:ignore

		if ( ( isset( $s['show_privacy'] ) ? $s['show_privacy'] : 'yes' ) === 'yes' && ! empty( $s['privacy_text'] ) ) {
			$url       = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
			$link_text = esc_html( ! empty( $s['privacy_link_text'] ) ? $s['privacy_link_text'] : 'privacy policy' );
			$link      = $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . $link_text . '</a>' : $link_text;
			echo '<p class="dtc-privacy">' . str_replace( '[privacy_policy]', $link, esc_html( $s['privacy_text'] ) ) . '</p>'; // phpcs:ignore
		}

		echo '<button type="submit" class="dtc-btn"' . ( $data['empty'] ? ' disabled' : '' ) . '><span class="dtc-js-button">' . $data['button'] . '</span></button>'; // phpcs:ignore
		echo '</div>'; // .dtc-right

		echo '</div>'; // .dtc-grid
		echo '</form></div>';
	}

	private static function render_card( $card, $multi, $s ) {
		$p        = $card['p'];
		$vopts    = $card['vopts'];
		$on       = $card['on'];
		$show_qty = ( isset( $s['show_qty'] ) ? $s['show_qty'] : '' ) === 'yes';
		$show_img = ( isset( $s['show_card_image'] ) ? $s['show_card_image'] : 'yes' ) === 'yes';
		$price    = $vopts ? $vopts[0]['price'] : self::price_html( $p );
		$max      = $p->get_max_purchase_quantity();

		echo '<label class="dtc-product-card' . ( $on ? ' is-selected' : '' ) . '" data-id="' . (int) $p->get_id() . '">';
		echo '<input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" class="dtc-product-input" name="' . ( $multi ? 'dtc_product[]' : 'dtc_product' ) . '" value="' . (int) $p->get_id() . '" ' . checked( $on, true, false ) . '>';
		echo '<span class="dtc-check"><svg viewBox="0 0 12 12" aria-hidden="true"><path d="M2.5 6.3l2.3 2.3 4.7-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
		if ( $show_img ) {
			echo '<span class="dtc-product-thumb">' . wp_kses_post( $p->get_image( 'thumbnail' ) ) . '</span>';
		}
		echo '<span class="dtc-product-info">';
		echo '<span class="dtc-product-title">' . esc_html( $p->get_name() ) . '</span>';
		echo '<span class="dtc-product-price">' . wp_kses_post( $price ) . '</span>';

		if ( $vopts ) {
			echo '<select class="dtc-variation" aria-label="' . esc_attr( $p->get_name() ) . '">';
			foreach ( $vopts as $opt ) {
				echo '<option value="' . (int) $opt['id'] . '" data-price="' . esc_attr( $opt['price'] ) . '">' . esc_html( $opt['label'] ) . '</option>';
			}
			echo '</select>';
		}

		if ( $show_qty ) {
			echo '<span class="dtc-qty-wrap">';
			echo '<button type="button" class="dtc-qty-btn" data-dir="-1" aria-label="-">&minus;</button>';
			echo '<input type="number" class="dtc-qty" value="1" min="1"' . ( $max > 0 ? ' max="' . (int) $max . '"' : '' ) . ' inputmode="numeric">';
			echo '<button type="button" class="dtc-qty-btn" data-dir="1" aria-label="+">+</button>';
			echo '</span>';
		}
		echo '</span>';
		echo '</label>';
	}

	private static function render_fields( $s, $cfg, $uid, $country ) {
		$names = [
			'name'     => 'billing_first_name',
			'phone'    => 'billing_phone',
			'email'    => 'billing_email',
			'address'  => 'billing_address_1',
			'city'     => 'billing_city',
			'postcode' => 'billing_postcode',
			'country'  => 'billing_country',
			'state'    => 'billing_state',
			'notes'    => 'order_comments',
		];
		$auto = [
			'name'     => 'name',
			'phone'    => 'tel',
			'email'    => 'email',
			'address'  => 'street-address',
			'city'     => 'address-level2',
			'postcode' => 'postal-code',
			'country'  => 'country',
			'state'    => 'address-level1',
			'notes'    => 'off',
		];
		$optional = esc_html( isset( $s['text_optional'] ) ? $s['text_optional'] : '(optional)' );

		if ( empty( $cfg['f_country_show'] ) ) {
			echo '<input type="hidden" name="billing_country" value="' . esc_attr( $country ) . '">';
		}

		foreach ( self::field_defs() as $key => $def ) {
			if ( empty( $cfg[ "f_{$key}_show" ] ) ) {
				continue;
			}
			$label = isset( $s[ "f_{$key}_label" ] ) ? $s[ "f_{$key}_label" ] : $def[3];
			$ph    = isset( $s[ "f_{$key}_ph" ] ) ? $s[ "f_{$key}_ph" ] : $def[4];
			$req   = ( 'country' !== $key ) && ! empty( $cfg[ "f_{$key}_req" ] );
			$id    = 'dtc-' . $uid . '-' . $key;
			$name  = $names[ $key ];
			$loc   = in_array( $key, [ 'city', 'postcode', 'country', 'state' ], true ) ? ' dtc-loc' : '';

			echo '<div class="dtc-field dtc-field-' . esc_attr( $key ) . '">';
			echo '<label class="dtc-label" for="' . esc_attr( $id ) . '">' . esc_html( $label );
			echo $req ? ' <span class="dtc-req">*</span>' : ( 'country' === $key ? '' : ' <span class="dtc-opt">' . $optional . '</span>' ); // phpcs:ignore
			echo '</label>';

			$common = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" autocomplete="' . esc_attr( $auto[ $key ] ) . '"' . ( $req ? ' required' : '' ) . ' placeholder="' . esc_attr( $ph ) . '"';

			if ( 'address' === $key || 'notes' === $key ) {
				echo '<textarea class="dtc-input" rows="' . ( 'address' === $key ? 3 : 2 ) . '"' . $common . '></textarea>'; // phpcs:ignore
			} elseif ( 'country' === $key ) {
				echo '<select class="dtc-input' . esc_attr( $loc ) . '" id="' . esc_attr( $id ) . '" name="billing_country" autocomplete="country">';
				foreach ( WC()->countries->get_allowed_countries() as $code => $cname ) {
					echo '<option value="' . esc_attr( $code ) . '"' . selected( $code, $country, false ) . '>' . esc_html( $cname ) . '</option>';
				}
				echo '</select>';
			} elseif ( 'state' === $key ) {
				$states = ! empty( $cfg['f_country_show'] ) ? WC()->countries->get_states() : [ $country => WC()->countries->get_states( $country ) ];
				$list   = isset( $states[ $country ] ) && is_array( $states[ $country ] ) ? $states[ $country ] : [];
				echo '<div class="dtc-state-wrap" data-states="' . esc_attr( wp_json_encode( $states ) ) . '">';
				if ( $list ) {
					echo '<select class="dtc-input' . esc_attr( $loc ) . '"' . $common . '><option value="">' . esc_html__( 'Select...', 'dt-checkout' ) . '</option>'; // phpcs:ignore
					foreach ( $list as $code => $sname ) {
						echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $sname ) . '</option>';
					}
					echo '</select>';
				} else {
					echo '<input type="text" class="dtc-input' . esc_attr( $loc ) . '"' . $common . '>'; // phpcs:ignore
				}
				echo '</div>';
			} else {
				$type = ( 'phone' === $key ) ? 'tel' : ( 'email' === $key ? 'email' : 'text' );
				echo '<input type="' . esc_attr( $type ) . '" class="dtc-input' . esc_attr( $loc ) . '"' . $common . '>'; // phpcs:ignore
			}
			echo '</div>';
		}
	}

	/* =====================================================================
	 * AJAX
	 * ===================================================================== */

	private static function read_cfg() {
		$raw = isset( $_POST['cfg'] ) ? json_decode( wp_unslash( $_POST['cfg'] ), true ) : []; // phpcs:ignore
		return self::sanitize_cfg( is_array( $raw ) ? $raw : [] );
	}

	private static function read_req() {
		$items = [];
		if ( isset( $_POST['items'] ) ) { // phpcs:ignore
			$decoded = json_decode( wp_unslash( $_POST['items'] ), true ); // phpcs:ignore
			if ( is_array( $decoded ) ) {
				foreach ( array_slice( $decoded, 0, 50 ) as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$items[] = [
						'id'           => absint( isset( $item['id'] ) ? $item['id'] : 0 ),
						'variation_id' => absint( isset( $item['variation_id'] ) ? $item['variation_id'] : 0 ),
						'qty'          => absint( isset( $item['qty'] ) ? $item['qty'] : 1 ),
					];
				}
			}
		}
		return [
			'items'    => $items,
			'shipping' => isset( $_POST['shipping'] ) ? wc_clean( wp_unslash( $_POST['shipping'] ) ) : '', // phpcs:ignore
			'payment'  => isset( $_POST['payment'] ) ? wc_clean( wp_unslash( $_POST['payment'] ) ) : '', // phpcs:ignore
			'coupon'   => isset( $_POST['coupon'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon'] ) ) : '', // phpcs:ignore
			'loc'      => $_POST, // phpcs:ignore
		];
	}

	public static function ajax_nonce() {
		nocache_headers();
		wp_send_json_success( [ 'nonce' => wp_create_nonce( 'dtc_checkout' ) ] );
	}

	public static function ajax_refresh() {
		nocache_headers();
		try {
			self::boot_cart();
			self::drop_stale_errors();
			$cfg = self::read_cfg();
			wp_send_json_success( self::compute( $cfg, self::read_req() ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	private static function fail( $messages ) {
		$messages = (array) $messages;
		$html     = '<ul class="woocommerce-error">';
		foreach ( $messages as $message ) {
			$html .= '<li>' . esc_html( $message ) . '</li>';
		}
		$html .= '</ul>';
		wp_send_json( [ 'result' => 'failure', 'messages' => $html ] );
	}

	/**
	 * Optional "Thank You Page": after a successful order, send the customer to that page with the
	 * order's secret key, so the DT Order Summary widget on it can show the order.
	 *
	 * Only WooCommerce's own order-received address (and a CartFlows thank-you address) is replaced.
	 * Payment gateways that send the customer to an outside site, or to a further payment step,
	 * keep their own redirect.
	 */
	private static function route_thankyou( $cfg ) {
		$page_id = absint( $cfg['thankyou_page'] );
		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			return;
		}
		$target = get_permalink( $page_id );
		if ( ! $target ) {
			return;
		}

		$rewrite = function ( $url, $order ) use ( $target ) {
			if ( ! $order || ! is_string( $url ) || '' === $url ) {
				return $url;
			}
			$is_received = ( 0 === strpos( $url, $order->get_checkout_order_received_url() ) );
			$is_cartflows = ( false !== strpos( $url, 'wcf-key=' ) );
			if ( ! $is_received && ! $is_cartflows ) {
				return $url;
			}
			return add_query_arg(
				[
					'dtc_order' => $order->get_id(),
					'dtc_key'   => $order->get_order_key(),
				],
				$target
			);
		};

		// Payment methods such as cash on delivery or bank transfer.
		add_filter( 'woocommerce_payment_successful_result', function ( $result, $order_id ) use ( $rewrite ) {
			if ( is_array( $result ) && ! empty( $result['redirect'] ) ) {
				$result['redirect'] = $rewrite( $result['redirect'], wc_get_order( $order_id ) );
			}
			return $result;
		}, 20, 2 );

		// Orders that need no payment.
		add_filter( 'woocommerce_checkout_no_payment_needed_redirect', $rewrite, 20, 2 );
	}

	public static function ajax_place_order() {
		nocache_headers();

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'dtc_checkout' ) ) {
			self::fail( __( 'Your session expired. Please reload the page and try again.', 'dt-checkout' ) );
		}

		try {
			self::boot_cart();

			// A leftover error from another plugin (e.g. CartFlows' "No product is selected")
			// must not block this order. We build and validate our own cart below.
			self::drop_stale_errors();

			$cfg = self::read_cfg();
			$req = self::read_req();

			if ( 'cart' !== $cfg['source'] ) {
				$built = self::build_cart( $req['items'], $req['coupon'] );
				if ( $built['errors'] ) {
					self::fail( $built['errors'] );
				}
			}
			if ( WC()->cart->is_empty() ) {
				self::fail( $cfg['msg_empty'] );
			}

			// Country comes from the widget settings unless the field is shown.
			$country = self::apply_location( $cfg, $req['loc'] );
			$_POST['billing_country'] = $country;

			// Only ask WooCommerce to check the fields this widget shows.
			add_filter( 'woocommerce_checkout_fields', function ( $fields ) use ( $cfg ) {
				$map = [
					'name'     => 'billing_first_name',
					'phone'    => 'billing_phone',
					'email'    => 'billing_email',
					'address'  => 'billing_address_1',
					'city'     => 'billing_city',
					'postcode' => 'billing_postcode',
					'state'    => 'billing_state',
				];
				foreach ( [ 'billing_last_name', 'billing_company', 'billing_address_2' ] as $remove ) {
					unset( $fields['billing'][ $remove ] );
				}
				foreach ( $map as $key => $field_key ) {
					if ( ! isset( $fields['billing'][ $field_key ] ) ) {
						continue;
					}
					if ( empty( $cfg[ "f_{$key}_show" ] ) ) {
						unset( $fields['billing'][ $field_key ] );
						continue;
					}
					$fields['billing'][ $field_key ]['required'] = ! empty( $cfg[ "f_{$key}_req" ] );
				}
				if ( empty( $cfg['f_notes_show'] ) ) {
					unset( $fields['order']['order_comments'] );
				}
				return $fields;
			}, 9999 );

			// Create a fresh WooCommerce nonce that matches the session started above.
			$wc_nonce = wp_create_nonce( 'woocommerce-process_checkout' );
			$_POST['woocommerce-process-checkout-nonce']    = $wc_nonce;
			$_REQUEST['woocommerce-process-checkout-nonce'] = $wc_nonce;

			WC()->cart->calculate_totals();

			// Start validation from a clean slate. WooCommerce re-checks the cart and every
			// field inside process_checkout(), so real problems still stop the order.
			self::drop_stale_errors();

			// Optional: send the customer to the page chosen in "After Order".
			self::route_thankyou( $cfg );

			WC()->checkout()->process_checkout(); // Sends JSON and stops.
		} catch ( \Throwable $e ) {
			self::fail( $e->getMessage() );
		}

		self::fail( __( 'We could not place your order. Please try again.', 'dt-checkout' ) );
	}
}

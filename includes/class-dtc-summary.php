<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order Summary (thank-you page) logic and markup.
 *
 * - finds the order from the URL and only shows it when the order key matches
 * - turns a WC_Order (or editor sample data) into one plain "view model"
 * - prints the markup from that view model, so real and sample data share one code path
 */
class DTC_Summary {

	/* ------------------------------------------------------------------
	 * Small helpers
	 * ------------------------------------------------------------------ */

	private static function yes( $s, $key, $default = 'yes' ) {
		$v = isset( $s[ $key ] ) ? $s[ $key ] : $default;
		return 'yes' === $v;
	}

	private static function tx( $s, $key, $default = '' ) {
		return ( isset( $s[ $key ] ) && is_scalar( $s[ $key ] ) ) ? (string) $s[ $key ] : $default;
	}

	private static function param( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : '';
	}

	/** Price HTML -> plain text ("৳1,500.00"). */
	private static function plain( $html ) {
		return trim( html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	public static function is_editor() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		$p = \Elementor\Plugin::$instance;
		if ( $p->editor && $p->editor->is_edit_mode() ) {
			return true;
		}
		return (bool) ( $p->preview && $p->preview->is_preview_mode() );
	}

	/** Cancelled, failed and refunded orders must not get the happy "order received" look. */
	private static function is_bad( $status_key ) {
		return in_array( $status_key, [ 'cancelled', 'failed', 'refunded' ], true );
	}

	/** An order page must never be served from a page cache. */
	private static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
		do_action( 'litespeed_control_set_nocache', 'dt order summary' );
	}

	/* ------------------------------------------------------------------
	 * Finding the order (securely)
	 * ------------------------------------------------------------------ */

	/**
	 * Works with: this plugin's own redirect, the CartFlows thank-you step, the standard
	 * WooCommerce "order received" address, and plain ?order_id=&key= links.
	 *
	 * The order is only returned when the order key matches. Shop managers may open any order,
	 * and a logged-in customer may open their own.
	 */
	public static function find_order() {
		$id  = 0;
		$key = '';

		$endpoint = absint( get_query_var( 'order-received' ) );
		if ( $endpoint ) {
			$id  = $endpoint;
			$key = self::param( 'key' );
		}

		if ( ! $id ) {
			$pairs = [
				[ 'dtc_order', 'dtc_key' ],
				[ 'wcf-order', 'wcf-key' ],
				[ 'order_id', 'key' ],
				[ 'order', 'key' ],
			];
			foreach ( $pairs as $pair ) {
				$maybe = self::param( $pair[0] );
				if ( '' !== $maybe && ctype_digit( $maybe ) ) {
					$id  = (int) $maybe;
					$key = self::param( $pair[1] );
					break;
				}
			}
		}

		// Only a key in the address (WooCommerce keys look like wc_order_xxxx).
		if ( ! $id ) {
			$loose = self::param( 'key' );
			if ( '' === $loose ) {
				$loose = self::param( 'wcf-key' );
			}
			if ( 0 === strpos( $loose, 'wc_order_' ) ) {
				$id  = (int) wc_get_order_id_by_order_key( $loose );
				$key = $loose;
			}
		}

		if ( ! $id ) {
			return null;
		}

		$order = wc_get_order( $id );
		if ( ! $order || ! ( $order instanceof WC_Order ) ) {
			return null;
		}

		if ( '' !== $key && hash_equals( (string) $order->get_order_key(), $key ) ) {
			return $order;
		}
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return $order;
		}
		if ( is_user_logged_in() && $order->get_user_id() && (int) $order->get_user_id() === get_current_user_id() ) {
			return $order;
		}
		return null;
	}

	/* ------------------------------------------------------------------
	 * View models
	 * ------------------------------------------------------------------ */

	private static function address_line( $a ) {
		$country = isset( $a['country'] ) ? (string) $a['country'] : '';
		$state   = isset( $a['state'] ) ? (string) $a['state'] : '';
		$wc      = WC()->countries;

		if ( $country && $state ) {
			$states = $wc->get_states( $country );
			if ( is_array( $states ) && isset( $states[ $state ] ) ) {
				$state = $states[ $state ];
			}
		}

		$country_name = '';
		if ( $country && $country !== $wc->get_base_country() ) {
			$all = $wc->get_countries();
			if ( isset( $all[ $country ] ) ) {
				$country_name = $all[ $country ];
			}
		}

		$parts = [
			isset( $a['address_1'] ) ? $a['address_1'] : '',
			isset( $a['address_2'] ) ? $a['address_2'] : '',
			isset( $a['city'] ) ? $a['city'] : '',
			$state,
			isset( $a['postcode'] ) ? $a['postcode'] : '',
			$country_name,
		];
		$parts = array_filter( array_map( 'trim', array_map( 'strval', $parts ) ), 'strlen' );
		return implode( ', ', $parts );
	}

	public static function from_order( $order ) {
		$currency = $order->get_currency();
		$cur      = [ 'currency' => $currency ];
		$incl     = ( 'incl' === get_option( 'woocommerce_tax_display_cart' ) );

		// ----- who may see personal details -----
		$staff   = current_user_can( 'manage_woocommerce' );
		$owner   = $order->get_user_id() && (int) $order->get_user_id() === get_current_user_id();
		$created = $order->get_date_created();
		$age     = $created ? ( time() - $created->getTimestamp() ) : 0;
		$fresh   = $age < (int) apply_filters( 'dtc_summary_fresh_seconds', HOUR_IN_SECONDS );
		// Orders of registered customers are only detailed to the owner, staff, or right after checkout.
		$private = ( $staff || $owner || ! $order->get_user_id() || $fresh );

		// ----- items -----
		$items = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}
			$product = $item->get_product();

			$meta = '';
			if ( function_exists( 'wc_display_item_meta' ) ) {
				$meta = wc_display_item_meta( $item, [
					'before'    => '',
					'after'     => '',
					'separator' => "\n",
					'echo'      => false,
					'autop'     => false,
				] );
				$meta = trim( wp_strip_all_tags( (string) $meta ) );
				$meta = trim( (string) preg_replace( '/\s*[\r\n]+\s*/', ', ', $meta ), ', ' );
			}

			$items[] = [
				'name'  => $item->get_name(),
				'meta'  => $meta,
				'qty'   => (int) $item->get_quantity(),
				'image' => $product ? $product->get_image( 'thumbnail' ) : wc_placeholder_img( 'thumbnail' ),
				'total' => $order->get_formatted_line_subtotal( $item ),
			];
		}

		// ----- totals -----
		$discount = null;
		$disc_amt = (float) $order->get_total_discount( ! $incl );
		if ( $disc_amt > 0 ) {
			$discount = [
				'html'  => '-' . wc_price( $disc_amt, $cur ),
				'codes' => implode( ', ', array_map( 'strtoupper', (array) $order->get_coupon_codes() ) ),
			];
		}

		$shipping = null;
		if ( count( $order->get_shipping_methods() ) > 0 ) {
			$cost = (float) $order->get_shipping_total();
			if ( $incl ) {
				$cost += (float) $order->get_shipping_tax();
			}
			$shipping = [
				'cost'   => $cost,
				'html'   => wc_price( $cost, $cur ),
				'method' => (string) $order->get_shipping_method(),
			];
		}

		$fees = [];
		foreach ( $order->get_fees() as $fee ) {
			$amt = (float) $fee->get_total();
			if ( $incl ) {
				$amt += (float) $fee->get_total_tax();
			}
			$fees[] = [
				'name' => $fee->get_name(),
				'html' => wc_price( $amt, $cur ),
			];
		}

		$taxes = [];
		if ( wc_tax_enabled() && ! $incl ) {
			foreach ( $order->get_tax_totals() as $tax ) {
				$taxes[] = [
					'label' => $tax->label,
					'html'  => $tax->formatted_amount,
				];
			}
		}

		$refunded      = (float) $order->get_total_refunded();
		$total_html    = wc_price( $order->get_total(), $cur );
		$refunded_html = $refunded > 0 ? '-' . wc_price( $refunded, $cur ) : '';

		// ----- customer -----
		$billing  = $order->get_address( 'billing' );
		$delivery = $order->has_shipping_address() ? $order->get_address( 'shipping' ) : $billing;
		$name     = trim( ( isset( $delivery['first_name'] ) ? $delivery['first_name'] : '' ) . ' ' . ( isset( $delivery['last_name'] ) ? $delivery['last_name'] : '' ) );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}

		return [
			'sample'      => false,
			'private'     => $private,
			'id'          => $order->get_id(),
			'number'      => (string) $order->get_order_number(),
			'status_key'  => (string) $order->get_status(),
			'status'      => wc_get_order_status_name( $order->get_status() ),
			'date'        => $created ? wc_format_datetime( $created ) : '',
			'date_ts'     => $created ? $created->getTimestamp() : time(),
			'total'       => $total_html,
			'total_plain' => self::plain( $total_html ),
			'payment'     => (string) $order->get_payment_method_title(),
			'first_name'  => trim( $order->get_billing_first_name() ),
			'name'        => $name,
			'phone'       => (string) $order->get_billing_phone(),
			'email'       => (string) $order->get_billing_email(),
			'address'     => self::address_line( $delivery ),
			'note'        => (string) $order->get_customer_note(),
			'items'       => $items,
			'subtotal'    => $order->get_subtotal_to_display(),
			'discount'    => $discount,
			'shipping'    => $shipping,
			'fees'        => $fees,
			'taxes'       => $taxes,
			'refunded'    => $refunded_html,
		];
	}

	/** Sample data shown in the Elementor editor so the design can be styled. */
	public static function sample( $status_key = 'processing' ) {
		$allowed = [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'failed', 'refunded' ];
		if ( ! in_array( $status_key, $allowed, true ) ) {
			$status_key = 'processing';
		}
		$cur_total = wc_price( 2260 );
		return [
			'sample'      => true,
			'private'     => true,
			'id'          => 0,
			'number'      => '1024',
			'status_key'  => $status_key,
			'status'      => wc_get_order_status_name( $status_key ),
			'date'        => wc_format_datetime( new WC_DateTime() ),
			'date_ts'     => time(),
			'total'       => $cur_total,
			'total_plain' => self::plain( $cur_total ),
			'payment'     => 'Cash on delivery',
			'first_name'  => 'Rahim',
			'name'        => 'Rahim Uddin',
			'phone'       => '01712 345 678',
			'email'       => 'rahim@example.com',
			'address'     => 'House 12, Road 5, Dhanmondi, Dhaka',
			'note'        => 'Please call before delivery.',
			'items'       => [
				[
					'name'  => 'Sample Product A',
					'meta'  => 'Size: M, Color: Navy',
					'qty'   => 1,
					'image' => wc_placeholder_img( 'thumbnail' ),
					'total' => wc_price( 1500 ),
				],
				[
					'name'  => 'Sample Product B',
					'meta'  => '',
					'qty'   => 2,
					'image' => wc_placeholder_img( 'thumbnail' ),
					'total' => wc_price( 800 ),
				],
			],
			'subtotal'    => wc_price( 2300 ),
			'discount'    => [ 'html' => '-' . wc_price( 100 ), 'codes' => 'WELCOME' ],
			'shipping'    => [ 'cost' => 60.0, 'html' => wc_price( 60 ), 'method' => 'Inside Dhaka' ],
			'fees'        => [],
			'taxes'       => [],
			'refunded'    => '',
		];
	}

	/* ------------------------------------------------------------------
	 * Text with {placeholders}
	 * ------------------------------------------------------------------ */

	private static function tokens( $vm ) {
		return [
			'{order_number}'   => $vm['number'],
			'{name}'           => $vm['private'] ? $vm['first_name'] : '',
			'{total}'          => $vm['total_plain'],
			'{date}'           => $vm['date'],
			'{email}'          => $vm['private'] ? $vm['email'] : '',
			'{phone}'          => $vm['private'] ? $vm['phone'] : '',
			'{status}'         => $vm['status'],
			'{payment_method}' => $vm['payment'],
			'{site_name}'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		];
	}

	/** Replace placeholders. $encode = true for use inside a URL. */
	private static function fill( $text, $tokens, $encode = false ) {
		if ( $encode ) {
			$tokens = array_map( 'rawurlencode', $tokens );
		}
		$out = strtr( (string) $text, $tokens );

		if ( ! $encode ) {
			// "ধন্যবাদ, {name}!" with an empty name should read "ধন্যবাদ!".
			$tidy = preg_replace( '/\s*,\s*(?=[!.?।])/u', '', $out );
			if ( null !== $tidy ) {
				$out = $tidy;
			}
			$tidy = preg_replace( '/[ \t]{2,}/u', ' ', $out );
			if ( null !== $tidy ) {
				$out = $tidy;
			}
			$out = trim( $out );
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Output
	 * ------------------------------------------------------------------ */

	public static function render( $s ) {
		try {
			self::render_inner( $s );
		} catch ( \Throwable $e ) {
			if ( current_user_can( 'manage_options' ) ) {
				echo '<div class="dts-wrap"><p class="dts-empty">' . esc_html( $e->getMessage() ) . '</p></div>';
			}
		}
	}

	private static function render_empty( $s ) {
		if ( 'hide' === self::tx( $s, 'no_order', 'message' ) ) {
			return;
		}
		$msg = self::tx( $s, 'msg_no_order', '' );
		if ( '' === $msg ) {
			return;
		}
		echo '<div class="dts-wrap dts-is-empty"><p class="dts-empty">' . esc_html( $msg ) . '</p></div>';
	}

	private static function render_inner( $s ) {
		$editor = self::is_editor();
		$order  = null;
		$vm     = null;

		if ( ! $editor ) {
			self::no_cache();
			$order = self::find_order();
			if ( $order ) {
				$vm = self::from_order( $order );
			}
		} elseif ( self::yes( $s, 'editor_sample', 'yes' ) ) {
			$vm = self::sample( self::tx( $s, 'preview_status', 'processing' ) );
		}

		if ( ! $vm ) {
			self::render_empty( $s );
			return;
		}

		$tok    = self::tokens( $vm );
		$status = $vm['status_key'];

		$show_details = self::yes( $s, 'show_details', 'yes' ) && $vm['private'];
		$rows         = [];
		if ( $show_details ) {
			if ( self::yes( $s, 'show_d_name', 'yes' ) && '' !== $vm['name'] ) {
				$rows[] = [ self::tx( $s, 'lbl_d_name', 'Name' ), esc_html( $vm['name'] ) ];
			}
			if ( self::yes( $s, 'show_d_phone', 'yes' ) && '' !== $vm['phone'] ) {
				$rows[] = [ self::tx( $s, 'lbl_d_phone', 'Phone' ), esc_html( $vm['phone'] ) ];
			}
			if ( self::yes( $s, 'show_d_email', 'yes' ) && '' !== $vm['email'] ) {
				$rows[] = [ self::tx( $s, 'lbl_d_email', 'Email' ), esc_html( $vm['email'] ) ];
			}
			if ( self::yes( $s, 'show_d_address', 'yes' ) && '' !== $vm['address'] ) {
				$rows[] = [ self::tx( $s, 'lbl_d_address', 'Address' ), esc_html( $vm['address'] ) ];
			}
			if ( self::yes( $s, 'show_d_note', 'yes' ) && '' !== $vm['note'] ) {
				$rows[] = [ self::tx( $s, 'lbl_d_note', 'Note' ), nl2br( esc_html( $vm['note'] ) ) ];
			}
		}
		$has_side = ! empty( $rows );

		// Payment instructions printed by the gateway (bank details, cash-on-delivery text...).
		$instructions = '';
		if ( self::yes( $s, 'show_instructions', 'yes' ) && ! self::is_bad( $status ) ) {
			if ( $vm['sample'] ) {
				$instructions = '<p>' . esc_html( 'Pay in cash when your order is delivered.' ) . '</p>';
			} elseif ( $order && $order->get_payment_method() ) {
				ob_start();
				try {
					do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() );
				} catch ( \Throwable $e ) {
					// A gateway problem must never break the whole summary.
					$instructions = '';
				}
				$instructions = trim( (string) ob_get_clean() );
			}
		}

		$classes = [
			'dts-wrap',
			'dts-layout-' . ( 'one' === self::tx( $s, 'layout', 'two' ) ? 'one' : 'two' ),
			'dts-status-' . sanitize_html_class( $status ),
		];
		if ( ! $has_side ) {
			$classes[] = 'dts-no-side';
		}
		if ( $vm['sample'] ) {
			$classes[] = 'dts-is-sample';
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		self::part_hero( $s, $vm, $tok );
		self::part_meta( $s, $vm );
		self::part_tracker( $s, $vm );
		self::part_eta( $s, $vm );

		echo '<div class="dts-body">';
		self::part_items( $s, $vm, $instructions );
		if ( $has_side ) {
			self::part_details( $s, $rows );
		}
		echo '</div>';

		self::part_actions( $s, $vm, $tok );

		echo '</div>';

		// Tracking plugins and payment gateways listen to this action on the normal thank-you page.
		if ( $order && self::yes( $s, 'run_hooks', '' ) && ! did_action( 'woocommerce_thankyou' ) ) {
			do_action( 'woocommerce_thankyou', $order->get_id() );
		}
	}

	/* ----- hero ----- */

	private static function part_hero( $s, $vm, $tok ) {
		if ( ! self::yes( $s, 'show_hero', 'yes' ) ) {
			return;
		}
		$bad = self::is_bad( $vm['status_key'] );
		if ( $bad ) {
			$title = self::fill( self::tx( $s, 'hero_title_bad', '' ), $tok );
			$text  = self::fill( self::tx( $s, 'hero_text_bad', '' ), $tok );
			$mark  = 'M19 19l14 14M33 19L19 33'; // a cross
		} else {
			$title = self::fill( self::tx( $s, 'hero_title', '' ), $tok );
			$text  = self::fill( self::tx( $s, 'hero_text', '' ), $tok );
			$mark  = 'M15 27.5l7.5 7.5L37 19'; // a check
		}

		echo '<div class="dts-hero">';
		echo '<span class="dts-seal' . ( $bad ? ' dts-seal-bad' : '' ) . '" aria-hidden="true"><svg viewBox="0 0 52 52" focusable="false"><circle class="dts-seal-ring" cx="26" cy="26" r="23"/><path class="dts-seal-tick" d="' . esc_attr( $mark ) . '"/></svg></span>';
		if ( '' !== $title ) {
			echo '<h2 class="dts-title">' . esc_html( $title ) . '</h2>';
		}
		if ( '' !== $text ) {
			echo '<p class="dts-sub">' . esc_html( $text ) . '</p>';
		}
		if ( self::yes( $s, 'show_badge', 'yes' ) ) {
			echo '<span class="dts-badge dts-badge-' . esc_attr( sanitize_html_class( $vm['status_key'] ) ) . '">' . esc_html( $vm['status'] ) . '</span>';
		}
		echo '</div>';
		echo '<div class="dts-perf" aria-hidden="true"></div>';
	}

	/* ----- order number / date / total / payment ----- */

	private static function part_meta( $s, $vm ) {
		if ( ! self::yes( $s, 'show_meta', 'yes' ) ) {
			return;
		}
		$cells = [];

		if ( self::yes( $s, 'show_m_number', 'yes' ) ) {
			$copy = '';
			if ( self::yes( $s, 'show_copy', 'yes' ) ) {
				$copy = '<button type="button" class="dts-copy" data-dts-copy="' . esc_attr( $vm['number'] ) . '" data-done="' . esc_attr( self::tx( $s, 'txt_copied', 'Copied' ) ) . '" aria-label="' . esc_attr( self::tx( $s, 'txt_copy_label', 'Copy order number' ) ) . '">'
					. '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="9" y="9" width="11" height="11" rx="2.5"/><path d="M5 15V6.5A2.5 2.5 0 0 1 7.5 4H15"/></svg></button>';
			}
			$cells[] = [ self::tx( $s, 'lbl_number', 'Order number' ), '#' . esc_html( $vm['number'] ) . $copy ];
		}
		if ( self::yes( $s, 'show_m_date', 'yes' ) && '' !== $vm['date'] ) {
			$cells[] = [ self::tx( $s, 'lbl_date', 'Date' ), esc_html( $vm['date'] ) ];
		}
		if ( self::yes( $s, 'show_m_total', 'yes' ) ) {
			$cells[] = [ self::tx( $s, 'lbl_total_tile', 'Total' ), wp_kses_post( $vm['total'] ) ];
		}
		if ( self::yes( $s, 'show_m_payment', 'yes' ) && '' !== $vm['payment'] ) {
			$cells[] = [ self::tx( $s, 'lbl_payment', 'Payment method' ), esc_html( $vm['payment'] ) ];
		}
		if ( ! $cells ) {
			return;
		}

		echo '<dl class="dts-meta">';
		foreach ( $cells as $cell ) {
			echo '<div class="dts-meta-item"><dt>' . esc_html( $cell[0] ) . '</dt><dd>' . $cell[1] . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built above.
		}
		echo '</dl>';
	}

	/* ----- progress ----- */

	private static function part_tracker( $s, $vm ) {
		if ( ! self::yes( $s, 'show_track', 'yes' ) ) {
			return;
		}
		$map = [
			'pending'    => 1,
			'on-hold'    => 1,
			'processing' => 2,
			'completed'  => 3,
		];
		if ( ! isset( $map[ $vm['status_key'] ] ) ) {
			return; // cancelled, failed, refunded or a custom status: the badge says it all.
		}
		$current = $map[ $vm['status_key'] ];
		$labels  = [
			self::tx( $s, 'lbl_step1', 'Order received' ),
			self::tx( $s, 'lbl_step2', 'Preparing' ),
			self::tx( $s, 'lbl_step3', 'Delivered' ),
		];

		echo '<ol class="dts-steps">';
		foreach ( $labels as $i => $label ) {
			$n     = $i + 1;
			$state = '';
			$aria  = '';
			if ( $n < $current || ( 3 === $current && 3 === $n ) ) {
				$state = ' is-done';
			} elseif ( $n === $current ) {
				$state = ' is-current';
				$aria  = ' aria-current="step"';
			}
			echo '<li class="dts-step' . $state . '"' . $aria . '><span class="dts-dot"><svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M3.5 8.5l3 3 6-6.5"/></svg></span><span class="dts-step-label">' . esc_html( $label ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- only fixed class names and escaped text.
		}
		echo '</ol>';
	}

	/* ----- estimated delivery ----- */

	private static function part_eta( $s, $vm ) {
		if ( ! self::yes( $s, 'show_eta', '' ) ) {
			return;
		}
		if ( in_array( $vm['status_key'], [ 'completed', 'cancelled', 'failed', 'refunded' ], true ) ) {
			return;
		}
		$min = max( 0, (int) self::tx( $s, 'eta_min', '2' ) );
		$max = max( $min, (int) self::tx( $s, 'eta_max', '4' ) );
		$from = $vm['date_ts'] + $min * DAY_IN_SECONDS;
		$to   = $vm['date_ts'] + $max * DAY_IN_SECONDS;

		$range = wp_date( 'j M', $from );
		if ( $max > $min ) {
			$range .= ' – ' . wp_date( 'j M', $to );
		}
		echo '<p class="dts-eta"><span>' . esc_html( self::tx( $s, 'lbl_eta', 'Estimated delivery' ) ) . '</span> <strong>' . esc_html( $range ) . '</strong></p>';
	}

	/* ----- items + totals ----- */

	private static function part_items( $s, $vm, $instructions ) {
		echo '<div class="dts-main">';

		$title = self::tx( $s, 'ttl_items', '' );
		if ( '' !== $title ) {
			echo '<h3 class="dts-sec-title">' . esc_html( $title ) . '</h3>';
		}

		$thumbs = self::yes( $s, 'show_thumb', 'yes' );
		$qty    = self::yes( $s, 'show_qty', 'yes' );

		echo '<ul class="dts-items">';
		foreach ( $vm['items'] as $item ) {
			echo '<li class="dts-item">';
			if ( $thumbs ) {
				echo '<span class="dts-thumb">' . wp_kses_post( $item['image'] ) . '</span>';
			}
			echo '<span class="dts-item-info"><span class="dts-item-name">' . esc_html( $item['name'] ) . '</span>';
			if ( '' !== $item['meta'] ) {
				echo '<span class="dts-item-meta">' . esc_html( $item['meta'] ) . '</span>';
			}
			if ( $qty ) {
				echo '<span class="dts-item-qty">× ' . (int) $item['qty'] . '</span>';
			}
			echo '</span>';
			echo '<span class="dts-item-total">' . wp_kses_post( $item['total'] ) . '</span>';
			echo '</li>';
		}
		echo '</ul>';

		echo '<dl class="dts-totals">';

		echo self::total_row( self::tx( $s, 'lbl_subtotal', 'Subtotal' ), wp_kses_post( $vm['subtotal'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $vm['discount'] ) {
			$label = esc_html( self::tx( $s, 'lbl_discount', 'Discount' ) );
			if ( '' !== $vm['discount']['codes'] ) {
				$label .= ' <small>(' . esc_html( $vm['discount']['codes'] ) . ')</small>';
			}
			echo self::total_row( $label, wp_kses_post( $vm['discount']['html'] ), 'dts-neg', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( $vm['shipping'] ) {
			$label = esc_html( self::tx( $s, 'lbl_shipping', 'Delivery charge' ) );
			if ( self::yes( $s, 'show_ship_method', 'yes' ) && '' !== $vm['shipping']['method'] ) {
				$label .= ' <small>(' . esc_html( $vm['shipping']['method'] ) . ')</small>';
			}
			$value = $vm['shipping']['cost'] > 0
				? wp_kses_post( $vm['shipping']['html'] )
				: esc_html( self::tx( $s, 'lbl_free', 'Free' ) );
			echo self::total_row( $label, $value, '', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		foreach ( $vm['fees'] as $fee ) {
			echo self::total_row( esc_html( $fee['name'] ), wp_kses_post( $fee['html'] ), '', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		foreach ( $vm['taxes'] as $tax ) {
			echo self::total_row( esc_html( $tax['label'] ), wp_kses_post( $tax['html'] ), '', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo self::total_row( self::tx( $s, 'lbl_total', 'Total' ), wp_kses_post( $vm['total'] ), 'dts-row-total' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( '' !== $vm['refunded'] ) {
			echo self::total_row( self::tx( $s, 'lbl_refunded', 'Refunded' ), wp_kses_post( $vm['refunded'] ), 'dts-neg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</dl>';

		if ( '' !== $instructions ) {
			echo '<div class="dts-note">';
			$ttl = self::tx( $s, 'ttl_instructions', '' );
			if ( '' !== $ttl ) {
				echo '<h4 class="dts-note-title">' . esc_html( $ttl ) . '</h4>';
			}
			echo '<div class="dts-note-body">' . wp_kses_post( $instructions ) . '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	/** One line of the totals list. $label_is_html: label is already escaped HTML. */
	private static function total_row( $label, $value_html, $class = '', $label_is_html = false ) {
		$label = $label_is_html ? $label : esc_html( $label );
		return '<div class="dts-row' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '"><dt>' . $label . '</dt><dd>' . $value_html . '</dd></div>';
	}

	/* ----- delivery details ----- */

	private static function part_details( $s, $rows ) {
		echo '<aside class="dts-side">';
		$title = self::tx( $s, 'ttl_details', '' );
		if ( '' !== $title ) {
			echo '<h3 class="dts-sec-title">' . esc_html( $title ) . '</h3>';
		}
		echo '<dl class="dts-details">';
		foreach ( $rows as $row ) {
			echo '<div class="dts-detail"><dt>' . esc_html( $row[0] ) . '</dt><dd>' . $row[1] . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
		}
		echo '</dl>';
		echo '</aside>';
	}

	/* ----- buttons ----- */

	private static function part_actions( $s, $vm, $tok ) {
		$buttons = [];

		if ( self::yes( $s, 'show_btn_continue', 'yes' ) ) {
			$url = trim( self::tx( $s, 'url_continue', '' ) );
			if ( '' === $url ) {
				$url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
			}
			if ( '' === $url ) {
				$url = home_url( '/' );
			}
			$buttons[] = '<a class="dts-btn dts-btn-primary" href="' . esc_url( self::fill( $url, $tok, true ) ) . '">' . esc_html( self::tx( $s, 'txt_continue', 'Continue shopping' ) ) . '</a>';
		}

		if ( self::yes( $s, 'show_btn_contact', '' ) ) {
			$url = trim( self::tx( $s, 'url_contact', '' ) );
			if ( '' !== $url ) {
				$buttons[] = '<a class="dts-btn dts-btn-ghost" target="_blank" rel="noopener noreferrer" href="' . esc_url( self::fill( $url, $tok, true ) ) . '">' . esc_html( self::tx( $s, 'txt_contact', 'Contact us' ) ) . '</a>';
			}
		}

		if ( self::yes( $s, 'show_btn_print', 'yes' ) ) {
			$buttons[] = '<button type="button" class="dts-btn dts-btn-ghost" data-dts-print>' . esc_html( self::tx( $s, 'txt_print', 'Print' ) ) . '</button>';
		}

		if ( ! $buttons ) {
			return;
		}
		echo '<div class="dts-actions">' . implode( '', $buttons ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each button is escaped above.
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;

class DTC_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'dt_checkout';
	}

	public function get_title() {
		return 'DT Checkout';
	}

	public function get_icon() {
		return 'eicon-product-add-to-cart';
	}

	public function get_categories() {
		return [ 'devthrives' ];
	}

	public function get_keywords() {
		return [ 'checkout', 'woocommerce', 'order', 'cod', 'payment', 'shipping', 'devthrives' ];
	}

	public function get_style_depends() {
		return [ 'dtc-style' ];
	}

	public function get_script_depends() {
		return [ 'dtc-script' ];
	}

	/* ------------------------------------------------------------------
	 * Small helpers to keep the control list short
	 * ------------------------------------------------------------------ */

	private function sec( $id, $label, $style = false, $condition = [] ) {
		$args = [
			'label' => $label,
			'tab'   => $style ? Controls_Manager::TAB_STYLE : Controls_Manager::TAB_CONTENT,
		];
		if ( $condition ) {
			$args['condition'] = $condition;
		}
		$this->start_controls_section( $id, $args );
	}

	private function t( $id, $label, $default = '', $condition = [], $textarea = false ) {
		$args = [
			'label'       => $label,
			'type'        => $textarea ? Controls_Manager::TEXTAREA : Controls_Manager::TEXT,
			'default'     => $default,
			'label_block' => true,
			'dynamic'     => [ 'active' => true ],
		];
		if ( $condition ) {
			$args['condition'] = $condition;
		}
		$this->add_control( $id, $args );
	}

	private function sw( $id, $label, $default = 'yes', $condition = [], $desc = '' ) {
		$args = [
			'label'        => $label,
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => $default,
		];
		if ( $condition ) {
			$args['condition'] = $condition;
		}
		if ( $desc ) {
			$args['description'] = $desc;
		}
		$this->add_control( $id, $args );
	}

	/** Colour control. $map: selector => 'css-prop' (comma list allowed). */
	private function col( $id, $label, $map ) {
		$this->add_control( $id, [
			'label'     => $label,
			'type'      => Controls_Manager::COLOR,
			'selectors' => $this->selectors( $map, '{{VALUE}}' ),
		] );
	}

	/** Slider control. $map: selector => 'css-prop' (comma list allowed). */
	private function sld( $id, $label, $map, $max = 100, $default = null ) {
		$args = [
			'label'      => $label,
			'type'       => Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range'      => [ 'px' => [ 'min' => 0, 'max' => $max ] ],
			'selectors'  => $this->selectors( $map, '{{SIZE}}{{UNIT}}' ),
		];
		if ( null !== $default ) {
			$args['default'] = [ 'unit' => 'px', 'size' => $default ];
		}
		$this->add_responsive_control( $id, $args );
	}

	private function dims( $id, $label, $selector, $prop = 'padding' ) {
		$this->add_responsive_control( $id, [
			'label'      => $label,
			'type'       => Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', 'em', '%' ],
			'selectors'  => [
				'{{WRAPPER}} ' . $selector => $prop . ': {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
			],
		] );
	}

	private function typo( $name, $selector, $label = 'Typography' ) {
		$this->add_group_control( Group_Control_Typography::get_type(), [
			'name'     => $name,
			'label'    => $label,
			'selector' => '{{WRAPPER}} ' . $selector,
		] );
	}

	private function selectors( $map, $value ) {
		$out = [];
		foreach ( $map as $selector => $props ) {
			$css = '';
			foreach ( explode( ',', $props ) as $prop ) {
				$css .= trim( $prop ) . ': ' . $value . ';';
			}
			$out[ '{{WRAPPER}} ' . $selector ] = $css;
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Controls
	 * ------------------------------------------------------------------ */

	protected function register_controls() {
		$this->controls_products();
		$this->controls_selection();
		$this->controls_layout();
		$this->controls_after_order();
		$this->controls_texts();
		$this->controls_fields();
		$this->controls_summary_texts();
		$this->controls_button_texts();
		$this->controls_style();
	}

	private function controls_products() {
		$this->sec( 'section_query', 'Products / Query' );

		$this->add_control( 'source', [
			'label'   => 'Product Source',
			'type'    => Controls_Manager::SELECT,
			'default' => 'manual',
			'options' => [
				'manual'  => 'Manual Selection',
				'query'   => 'Query (category, tag, sale...)',
				'current' => 'Current Product (single product page)',
				'cart'    => 'WooCommerce Cart (normal checkout)',
			],
		] );

		$this->add_control( 'manual_products', [
			'label'       => 'Select Products',
			'type'        => Controls_Manager::SELECT2,
			'multiple'    => true,
			'label_block' => true,
			'options'     => DTC_Core::product_options(),
			'description' => 'Search and pick the products visitors can choose from. The order follows your selection.',
			'condition'   => [ 'source' => 'manual' ],
		] );

		$q = [ 'source' => 'query' ];

		$this->add_control( 'query_categories', [
			'label'       => 'Categories',
			'type'        => Controls_Manager::SELECT2,
			'multiple'    => true,
			'label_block' => true,
			'options'     => DTC_Core::term_options( 'product_cat' ),
			'condition'   => $q,
		] );
		$this->add_control( 'query_tags', [
			'label'       => 'Tags',
			'type'        => Controls_Manager::SELECT2,
			'multiple'    => true,
			'label_block' => true,
			'options'     => DTC_Core::term_options( 'product_tag' ),
			'condition'   => $q,
		] );
		$this->add_control( 'query_exclude', [
			'label'       => 'Exclude Products',
			'type'        => Controls_Manager::SELECT2,
			'multiple'    => true,
			'label_block' => true,
			'options'     => DTC_Core::product_options(),
			'condition'   => $q,
		] );
		$this->sw( 'query_on_sale', 'Only On-Sale Products', '', $q );
		$this->sw( 'query_featured', 'Only Featured Products', '', $q );
		$this->add_control( 'query_orderby', [
			'label'     => 'Order By',
			'type'      => Controls_Manager::SELECT,
			'default'   => 'date',
			'options'   => [
				'date'       => 'Date',
				'title'      => 'Title',
				'price'      => 'Price',
				'popularity' => 'Popularity (sales)',
				'menu_order' => 'Menu Order',
				'rand'       => 'Random',
			],
			'condition' => $q,
		] );
		$this->add_control( 'query_order', [
			'label'     => 'Order',
			'type'      => Controls_Manager::SELECT,
			'default'   => 'DESC',
			'options'   => [ 'DESC' => 'Descending', 'ASC' => 'Ascending' ],
			'condition' => $q,
		] );
		$this->add_control( 'query_limit', [
			'label'     => 'Number of Products',
			'type'      => Controls_Manager::NUMBER,
			'default'   => 6,
			'min'       => 1,
			'max'       => 50,
			'condition' => $q,
		] );

		$this->sw( 'hide_out_of_stock', 'Hide Out-of-Stock Products', 'yes', [ 'source!' => 'cart' ] );

		$this->add_control( 'source_note', [
			'type'            => Controls_Manager::RAW_HTML,
			'raw'             => 'Note: with Manual, Query and Current Product, the visitor\'s WooCommerce cart is replaced by the chosen product. Use "WooCommerce Cart" for a normal checkout.',
			'content_classes' => 'elementor-descriptor',
			'condition'       => [ 'source!' => 'cart' ],
		] );

		$this->end_controls_section();
	}

	private function controls_selection() {
		$c = [ 'source!' => 'cart' ];
		$this->sec( 'section_selection', 'Product Selection Box', false, $c );

		$this->sw( 'show_selector', 'Show Product Selection Box', 'yes', [], 'Turn off to hide the cards but still sell the selected product.' );
		$this->add_control( 'selection', [
			'label'   => 'Selection Type',
			'type'    => Controls_Manager::SELECT,
			'default' => 'single',
			'options' => [
				'single'   => 'Single (radio) - one product',
				'multiple' => 'Multiple (checkbox) - many products',
			],
		] );
		$this->add_control( 'default_selected', [
			'label'   => 'Selected at Start',
			'type'    => Controls_Manager::SELECT,
			'default' => 'first',
			'options' => [
				'first' => 'First product',
				'all'   => 'All products (multiple only)',
				'none'  => 'Nothing',
			],
		] );
		$this->sw( 'show_card_image', 'Show Product Image', 'yes' );
		$this->sw( 'show_qty', 'Show Quantity Buttons on Cards', '' );

		$this->end_controls_section();
	}

	private function controls_layout() {
		$this->sec( 'section_layout', 'Layout' );

		$this->add_control( 'columns', [
			'label'     => 'Column Widths (desktop)',
			'type'      => Controls_Manager::SELECT,
			'default'   => 'minmax(0,1.45fr) minmax(0,1fr)',
			'options'   => [
				'minmax(0,1.45fr) minmax(0,1fr)' => '60% / 40%',
				'minmax(0,1fr) minmax(0,1fr)'    => '50% / 50%',
				'minmax(0,1.9fr) minmax(0,1fr)'  => '65% / 35%',
				'minmax(0,1fr)'                  => 'One column (stacked)',
			],
			'selectors' => [ '{{WRAPPER}} .dtc-wrap' => '--dtc-cols: {{VALUE}};' ],
		] );
		$this->sld( 'col_gap', 'Space Between Columns', [ '.dtc-grid' => 'column-gap' ], 100, 32 );
		$this->sw( 'show_thumb', 'Show Image in Order Summary', 'yes' );
		$this->sw( 'hide_radio_single', 'Hide Radio When Only One Payment Method', 'yes' );

		$this->end_controls_section();
	}

	private function controls_after_order() {
		$this->sec( 'section_after_order', 'After Order' );

		$pages = [ '' => 'WooCommerce default (order received page)' ];
		foreach ( get_pages( [ 'post_status' => 'publish', 'number' => 300 ] ) as $page ) {
			$pages[ (string) $page->ID ] = $page->post_title ? $page->post_title : '#' . $page->ID;
		}

		$this->add_control( 'thankyou_page', [
			'label'       => 'Thank You Page',
			'type'        => Controls_Manager::SELECT2,
			'default'     => '',
			'options'     => $pages,
			'label_block' => true,
			'description' => 'Send customers to this page after a successful order. Put the DT Order Summary widget on it. Leave on default to use the normal WooCommerce page.',
		] );

		$this->end_controls_section();
	}

	private function controls_texts() {
		$this->sec( 'section_texts', 'Headings' );

		$this->t( 'main_heading', 'Main Heading', 'আপনার অর্ডার সম্পন্ন করুন' );
		$this->t( 'select_heading', 'Product Selection Heading', 'আপনার পছন্দ নির্বাচন করুন' );
		$this->t( 'form_heading', 'Form Heading', 'নিচের তথ্য গুলো সঠিক ভাবে পূরণ করুন' );
		$this->t( 'summary_heading', 'Order Summary Heading', 'আপনার অর্ডার' );
		$this->t( 'shipping_heading', 'Shipping Heading', 'শিপিং পদ্ধতি' );
		$this->t( 'payment_heading', 'Payment Heading', '' );
		$this->add_control( 'heading_note', [
			'type'            => Controls_Manager::RAW_HTML,
			'raw'             => 'Leave a heading empty to hide it.',
			'content_classes' => 'elementor-descriptor',
		] );

		$this->end_controls_section();
	}

	private function controls_fields() {
		$this->sec( 'section_fields', 'Form Fields' );

		$this->add_control( 'country', [
			'label'       => 'Default Country',
			'type'        => Controls_Manager::SELECT2,
			'label_block' => true,
			'default'     => '',
			'options'     => DTC_Core::country_options(),
			'description' => 'Used to find shipping methods when the Country field is hidden.',
		] );
		$this->t( 'text_optional', 'Text for Optional Fields', '(ঐচ্ছিক)' );

		foreach ( DTC_Core::field_defs() as $key => $def ) {
			$this->add_control( "f_{$key}_head", [
				'label'     => $def[0],
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			] );
			$this->sw( "f_{$key}_show", 'Show Field', $def[1] ? 'yes' : '' );
			if ( 'country' !== $key ) {
				$this->sw( "f_{$key}_req", 'Required', $def[2] ? 'yes' : '', [ "f_{$key}_show" => 'yes' ] );
			}
			$this->t( "f_{$key}_label", 'Label', $def[3], [ "f_{$key}_show" => 'yes' ] );
			if ( 'country' !== $key ) {
				$this->t( "f_{$key}_ph", 'Placeholder', $def[4], [ "f_{$key}_show" => 'yes' ] );
			}
		}

		$this->end_controls_section();
	}

	private function controls_summary_texts() {
		$this->sec( 'section_summary_texts', 'Order Summary Texts' );

		$this->t( 'col_product', 'Table Head: Product', 'Product' );
		$this->t( 'col_subtotal', 'Table Head: Subtotal', 'Subtotal' );
		$this->t( 'row_subtotal', 'Row: Subtotal', 'Subtotal' );
		$this->t( 'row_shipping', 'Row: Shipping', 'শিপিং' );
		$this->t( 'row_discount', 'Row: Discount', 'Discount' );
		$this->t( 'row_total', 'Row: Total', 'Total' );
		$this->t( 'text_free', 'Text for Free Shipping', 'Free' );
		$this->t( 'msg_empty', 'Message: No Product Selected', 'Please select a product.' );
		$this->t( 'msg_no_shipping', 'Message: No Shipping Method', 'No shipping options are available for your address.' );
		$this->t( 'msg_no_payment', 'Message: No Payment Method', 'No payment methods are available.' );

		$this->add_control( 'coupon_head', [
			'label'     => 'Coupon',
			'type'      => Controls_Manager::HEADING,
			'separator' => 'before',
		] );
		$this->sw( 'show_coupon', 'Show Coupon Box', '' );
		$cc = [ 'show_coupon' => 'yes' ];
		$this->t( 'coupon_toggle', 'Coupon Link Text', 'Have a coupon?', $cc );
		$this->t( 'coupon_placeholder', 'Coupon Placeholder', 'Coupon code', $cc );
		$this->t( 'coupon_apply', 'Apply Button Text', 'Apply', $cc );

		$this->end_controls_section();
	}

	private function controls_button_texts() {
		$this->sec( 'section_button_texts', 'Button & Privacy Text' );

		$this->t( 'button_text', 'Place Order Button', 'অর্ডার করুন · {total}' );
		$this->add_control( 'button_note', [
			'type'            => Controls_Manager::RAW_HTML,
			'raw'             => 'Use {total} to show the order total inside the button text.',
			'content_classes' => 'elementor-descriptor',
		] );
		$this->t( 'button_loading', 'Button Text While Sending', 'Processing...' );

		$this->sw( 'show_privacy', 'Show Privacy Text', 'yes' );
		$this->t(
			'privacy_text',
			'Privacy Text',
			'Your personal data will be used to process your order, support your experience throughout this website, and for other purposes described in our [privacy_policy].',
			[ 'show_privacy' => 'yes' ],
			true
		);
		$this->t( 'privacy_link_text', 'Privacy Link Text', 'privacy policy', [ 'show_privacy' => 'yes' ] );

		$this->end_controls_section();
	}

	/* ------------------------------------------------------------------
	 * Style tab
	 * ------------------------------------------------------------------ */

	private function controls_style() {
		// General.
		$this->sec( 'style_general', 'General', true );
		$this->col( 'accent', 'Accent Color (button, check, active borders)', [ '.dtc-wrap' => '--dtc-dark' ] );
		$this->sld( 'corner', 'Corner Radius (cards, fields, button)', [ '.dtc-wrap' => '--dtc-radius' ], 40, 14 );
		$this->col( 'wrap_text', 'Text Color', [ '.dtc-wrap' => 'color' ] );
		$this->col( 'wrap_bg', 'Box Background', [ '.dtc-wrap' => 'background-color' ] );
		$this->sld( 'wrap_radius', 'Box Corner Radius', [ '.dtc-wrap' => 'border-radius' ], 60, 18 );
		$this->dims( 'wrap_pad', 'Box Padding', '.dtc-wrap' );
		$this->add_group_control( Group_Control_Border::get_type(), [
			'name'     => 'wrap_border',
			'selector' => '{{WRAPPER}} .dtc-wrap',
		] );
		$this->add_group_control( Group_Control_Box_Shadow::get_type(), [
			'name'     => 'wrap_shadow',
			'selector' => '{{WRAPPER}} .dtc-wrap',
		] );
		$this->end_controls_section();

		// Headings.
		$this->sec( 'style_headings', 'Headings', true );
		$this->col( 'title_color', 'Main Heading Color', [ '.dtc-title' => 'color' ] );
		$this->typo( 'title_typo', '.dtc-title', 'Main Heading Typography' );
		$this->col( 'sec_color', 'Section Heading Color', [ '.dtc-sec-title' => 'color' ] );
		$this->typo( 'sec_typo', '.dtc-sec-title', 'Section Heading Typography' );
		$this->end_controls_section();

		// Product cards.
		$this->sec( 'style_cards', 'Product Cards', true );
		$this->col( 'card_bg', 'Background', [ '.dtc-product-card' => 'background-color' ] );
		$this->col( 'card_border', 'Border Color', [ '.dtc-product-card' => 'border-color' ] );
		$this->col( 'card_border_on', 'Selected Border Color', [ '.dtc-product-card.is-selected' => 'border-color' ] );
		$this->col( 'card_check', 'Check Icon Color', [ '.dtc-product-card.is-selected .dtc-check' => 'background-color,border-color' ] );
		$this->sld( 'card_radius', 'Corner Radius', [ '.dtc-product-card' => 'border-radius' ], 40 );
		$this->dims( 'card_pad', 'Padding', '.dtc-product-card' );
		$this->sld( 'card_gap', 'Space Between Cards', [ '.dtc-products' => 'gap' ], 40 );
		$this->sld( 'thumb_size', 'Image Size', [ '.dtc-product-thumb' => 'width,height,flex-basis' ], 140 );
		$this->sld( 'thumb_radius', 'Image Corner Radius', [ '.dtc-product-thumb' => 'border-radius' ], 40 );
		$this->col( 'card_title_color', 'Title Color', [ '.dtc-product-title' => 'color' ] );
		$this->typo( 'card_title_typo', '.dtc-product-title', 'Title Typography' );
		$this->col( 'card_price_color', 'Price Color', [ '.dtc-product-price' => 'color' ] );
		$this->typo( 'card_price_typo', '.dtc-product-price .dtc-price-now, {{WRAPPER}} .dtc-product-price .amount', 'Price Typography' );
		$this->col( 'card_old_color', 'Old Price Color', [ '.dtc-price-old' => 'color' ] );
		$this->end_controls_section();

		// Form.
		$this->sec( 'style_form', 'Form Fields', true );
		$this->col( 'label_color', 'Label Color', [ '.dtc-label' => 'color' ] );
		$this->typo( 'label_typo', '.dtc-label', 'Label Typography' );
		$this->col( 'req_color', 'Required Star Color', [ '.dtc-req' => 'color' ] );
		$this->col( 'input_bg', 'Field Background', [ '.dtc-input' => 'background-color' ] );
		$this->col( 'input_color', 'Field Text Color', [ '.dtc-input' => 'color' ] );
		$this->col( 'input_ph', 'Placeholder Color', [ '.dtc-input::placeholder' => 'color' ] );
		$this->col( 'input_border', 'Field Border Color', [ '.dtc-input' => 'border-color' ] );
		$this->col( 'input_focus', 'Field Focus Border Color', [ '.dtc-input:focus' => 'border-color' ] );
		$this->sld( 'input_radius', 'Field Corner Radius', [ '.dtc-input' => 'border-radius' ], 40 );
		$this->dims( 'input_pad', 'Field Padding', '.dtc-input' );
		$this->sld( 'field_gap', 'Space Between Fields', [ '.dtc-field' => 'margin-bottom' ], 50 );
		$this->typo( 'input_typo', '.dtc-input', 'Field Typography' );
		$this->end_controls_section();

		// Order summary.
		$this->sec( 'style_summary', 'Order Summary', true );
		$this->col( 'sum_name_color', 'Product Name Color', [ '.dtc-sum-name' => 'color' ] );
		$this->typo( 'sum_name_typo', '.dtc-sum-name', 'Product Name Typography' );
		$this->col( 'sum_meta_color', 'Variation / Quantity Color', [ '.dtc-sum-meta, {{WRAPPER}} .dtc-sum-qty' => 'color' ] );
		$this->typo( 'sum_price_typo', '.dtc-sum-line', 'Price Typography' );
		$this->typo( 'sum_head_typo', '.dtc-sum-head', 'Table Head Typography' );
		$this->col( 'sum_line_color', 'Divider Color', [ '.dtc-sum-item, {{WRAPPER}} .dtc-sum-head, {{WRAPPER}} .dtc-totals, {{WRAPPER}} .dtc-total-row.is-total' => 'border-color' ] );
		$this->sld( 'sum_thumb', 'Image Size', [ '.dtc-sum-thumb' => 'width,height,flex-basis' ], 100 );
		$this->sld( 'sum_thumb_radius', 'Image Corner Radius', [ '.dtc-sum-thumb' => 'border-radius' ], 40 );
		$this->typo( 'total_typo', '.dtc-total-row', 'Totals Typography' );
		$this->typo( 'grand_typo', '.dtc-total-row.is-total', 'Grand Total Typography' );
		$this->end_controls_section();

		// Shipping.
		$this->sec( 'style_shipping', 'Shipping Methods', true );
		$this->col( 'ship_bg', 'Background', [ '.dtc-ship-item' => 'background-color' ] );
		$this->col( 'ship_border', 'Border Color', [ '.dtc-ship-item' => 'border-color' ] );
		$this->col( 'ship_bg_on', 'Selected Background', [ '.dtc-ship-item.is-active' => 'background-color' ] );
		$this->col( 'ship_border_on', 'Selected Border Color', [ '.dtc-ship-item.is-active' => 'border-color' ] );
		$this->col( 'ship_dot', 'Radio Color', [ '.dtc-ship-item.is-active .dtc-dot' => 'border-color' ] );
		$this->sld( 'ship_radius', 'Corner Radius', [ '.dtc-ship-item' => 'border-radius' ], 40 );
		$this->dims( 'ship_pad', 'Padding', '.dtc-ship-item' );
		$this->typo( 'ship_typo', '.dtc-ship-item', 'Typography' );
		$this->end_controls_section();

		// Payment.
		$this->sec( 'style_payment', 'Payment Methods', true );
		$this->col( 'pay_bg', 'Area Background', [ '.dtc-pay-list' => 'background-color' ] );
		$this->sld( 'pay_radius', 'Area Corner Radius', [ '.dtc-pay-list' => 'border-radius' ], 40 );
		$this->col( 'pay_title_color', 'Title Color', [ '.dtc-pay-head' => 'color' ] );
		$this->typo( 'pay_title_typo', '.dtc-pay-head', 'Title Typography' );
		$this->col( 'pay_box_bg', 'Description Box Background', [ '.dtc-pay-box' => 'background-color', '.dtc-pay-box::before' => 'border-bottom-color' ] );
		$this->col( 'pay_box_color', 'Description Text Color', [ '.dtc-pay-box' => 'color' ] );
		$this->sld( 'pay_box_radius', 'Description Box Corner Radius', [ '.dtc-pay-box' => 'border-radius' ], 40 );
		$this->typo( 'pay_box_typo', '.dtc-pay-box', 'Description Typography' );
		$this->end_controls_section();

		// Button.
		$this->sec( 'style_button', 'Place Order Button', true );
		$this->typo( 'btn_typo', '.dtc-btn', 'Typography' );
		$this->col( 'btn_color', 'Text Color', [ '.dtc-btn' => 'color' ] );
		$this->col( 'btn_bg', 'Background', [ '.dtc-btn' => 'background-color' ] );
		$this->col( 'btn_color_h', 'Hover Text Color', [ '.dtc-btn:hover' => 'color' ] );
		$this->col( 'btn_bg_h', 'Hover Background', [ '.dtc-btn:hover' => 'background-color' ] );
		$this->sld( 'btn_radius', 'Corner Radius', [ '.dtc-btn' => 'border-radius' ], 50 );
		$this->dims( 'btn_pad', 'Padding', '.dtc-btn' );
		$this->add_group_control( Group_Control_Box_Shadow::get_type(), [
			'name'     => 'btn_shadow',
			'selector' => '{{WRAPPER}} .dtc-btn',
		] );
		$this->end_controls_section();

		// Messages.
		$this->sec( 'style_notice', 'Error Messages', true );
		$this->col( 'notice_color', 'Text Color', [ '.dtc-notices' => 'color' ] );
		$this->col( 'notice_bg', 'Background', [ '.dtc-notices' => 'background-color' ] );
		$this->end_controls_section();
	}

	/* ------------------------------------------------------------------
	 * Render
	 * ------------------------------------------------------------------ */

	protected function render() {
		DTC_Core::render( $this->get_settings_for_display(), $this->get_id() );
	}
}

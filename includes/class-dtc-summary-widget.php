<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;

/**
 * "DT Order Summary" - put it on the thank-you page.
 */
class DTC_Summary_Widget extends \Elementor\Widget_Base {

	use DTC_Controls;

	public function get_name() {
		return 'dt_order_summary';
	}

	public function get_title() {
		return 'DT Order Summary';
	}

	public function get_icon() {
		return 'eicon-check-circle';
	}

	public function get_categories() {
		return [ 'devthrives' ];
	}

	public function get_keywords() {
		return [ 'thank you', 'thankyou', 'order', 'summary', 'receipt', 'confirmation', 'woocommerce', 'devthrives' ];
	}

	public function get_style_depends() {
		return [ 'dts-style' ];
	}

	public function get_script_depends() {
		return [ 'dts-script' ];
	}

	/**
	 * This widget prints one customer's order, so Elementor must never cache its output
	 * and show it to someone else.
	 */
	public function is_dynamic_content( ...$args ): bool {
		return true;
	}

	/* ------------------------------------------------------------------
	 * Controls
	 * ------------------------------------------------------------------ */

	protected function register_controls() {
		$this->controls_source();
		$this->controls_hero();
		$this->controls_meta();
		$this->controls_progress();
		$this->controls_items();
		$this->controls_details();
		$this->controls_buttons();
		$this->controls_layout();
		$this->controls_style();
	}

	private function controls_source() {
		$this->sec( 'section_source', 'Order Source & Preview' );

		$this->add_control( 'source_help', [
			'type'            => Controls_Manager::RAW_HTML,
			'raw'             => 'Place this widget on your <strong>thank-you page</strong>. The order is found automatically from the page address (WooCommerce order-received page, CartFlows thank-you step, or a link made by the DT Checkout widget). An order is only shown when its secret order key is in the address.',
			'content_classes' => 'elementor-descriptor',
		] );

		$this->sw( 'editor_sample', 'Show sample order in the editor', 'yes', [], 'Only visible while editing, so you can style the design. Real visitors see their own order.' );

		$this->add_control( 'preview_status', [
			'label'     => 'Preview order status',
			'type'      => Controls_Manager::SELECT,
			'default'   => 'processing',
			'options'   => [
				'pending'    => 'Pending payment',
				'processing' => 'Processing',
				'on-hold'    => 'On hold',
				'completed'  => 'Completed',
				'cancelled'  => 'Cancelled',
				'failed'     => 'Failed',
				'refunded'   => 'Refunded',
			],
			'description' => 'Editor only. Lets you design how every status looks.',
			'condition'   => [ 'editor_sample' => 'yes' ],
		] );

		$this->add_control( 'no_order', [
			'label'   => 'When no order is found',
			'type'    => Controls_Manager::SELECT,
			'default' => 'message',
			'options' => [
				'message' => 'Show a message',
				'hide'    => 'Show nothing',
			],
		] );
		$this->t( 'msg_no_order', 'Message: No Order Found', 'কোনো অর্ডার খুঁজে পাওয়া যায়নি। অর্ডার কনফার্মেশনের লিংক থেকে এই পেজে আসুন।', [ 'no_order' => 'message' ], true );

		$this->sw( 'run_hooks', "Run WooCommerce 'thank you' actions", '', [], 'Turn on if your tracking plugin (Facebook Pixel, GTM, Google Analytics purchase events) or payment gateway relies on the normal WooCommerce thank-you page. Leave off if the standard WooCommerce order details are also on this page, to avoid counting a purchase twice.' );

		$this->end_controls_section();
	}

	private function controls_hero() {
		$this->sec( 'section_hero', 'Success Message' );

		$this->sw( 'show_hero', 'Show success message' );
		$this->t( 'hero_title', 'Heading', 'ধন্যবাদ, {name}! আপনার অর্ডার গৃহীত হয়েছে', [ 'show_hero' => 'yes' ] );
		$this->t( 'hero_text', 'Text', 'আপনার অর্ডার নম্বর #{order_number}। আমাদের প্রতিনিধি খুব শীঘ্রই ফোন করে অর্ডারটি কনফার্ম করবেন।', [ 'show_hero' => 'yes' ], true );
		$this->t( 'hero_title_bad', 'Heading (cancelled / failed / refunded)', 'অর্ডারের বর্তমান অবস্থা: {status}', [ 'show_hero' => 'yes' ], false, 'Shown instead of the heading above, with a red cross, when the order did not go through.' );
		$this->t( 'hero_text_bad', 'Text (cancelled / failed / refunded)', 'অর্ডার নম্বর #{order_number}। কোনো প্রশ্ন থাকলে আমাদের সাথে যোগাযোগ করুন।', [ 'show_hero' => 'yes' ], true );
		$this->sw( 'show_badge', 'Show order status badge', 'yes', [ 'show_hero' => 'yes' ] );

		$this->add_control( 'tokens_help', [
			'type'            => Controls_Manager::RAW_HTML,
			'raw'             => 'You can use these placeholders in the heading, text and button links:<br><code>{name}</code> <code>{order_number}</code> <code>{total}</code> <code>{date}</code> <code>{email}</code> <code>{phone}</code> <code>{status}</code> <code>{payment_method}</code> <code>{site_name}</code>',
			'content_classes' => 'elementor-descriptor',
			'condition'       => [ 'show_hero' => 'yes' ],
		] );

		$this->add_responsive_control( 'hero_align', [
			'label'     => 'Alignment',
			'type'      => Controls_Manager::CHOOSE,
			'options'   => [
				'left'   => [ 'title' => 'Left', 'icon' => 'eicon-text-align-left' ],
				'center' => [ 'title' => 'Center', 'icon' => 'eicon-text-align-center' ],
				'right'  => [ 'title' => 'Right', 'icon' => 'eicon-text-align-right' ],
			],
			'default'   => 'center',
			'selectors' => [ '{{WRAPPER}} .dts-hero' => 'text-align: {{VALUE}};' ],
			'condition' => [ 'show_hero' => 'yes' ],
		] );

		$this->end_controls_section();
	}

	private function controls_meta() {
		$this->sec( 'section_meta', 'Order Info Strip' );

		$this->sw( 'show_meta', 'Show info strip' );

		$this->sw( 'show_m_number', 'Order number', 'yes', [ 'show_meta' => 'yes' ] );
		$this->t( 'lbl_number', 'Label: Order Number', 'অর্ডার নম্বর', [ 'show_meta' => 'yes', 'show_m_number' => 'yes' ] );
		$this->sw( 'show_copy', 'Copy button next to number', 'yes', [ 'show_meta' => 'yes', 'show_m_number' => 'yes' ] );
		$this->t( 'txt_copied', 'Text: After Copy', 'কপি হয়েছে', [ 'show_meta' => 'yes', 'show_m_number' => 'yes', 'show_copy' => 'yes' ] );
		$this->t( 'txt_copy_label', 'Copy Button Tooltip', 'অর্ডার নম্বর কপি করুন', [ 'show_meta' => 'yes', 'show_m_number' => 'yes', 'show_copy' => 'yes' ] );

		$this->sw( 'show_m_date', 'Date', 'yes', [ 'show_meta' => 'yes' ] );
		$this->t( 'lbl_date', 'Label: Date', 'তারিখ', [ 'show_meta' => 'yes', 'show_m_date' => 'yes' ] );

		$this->sw( 'show_m_total', 'Total', 'yes', [ 'show_meta' => 'yes' ] );
		$this->t( 'lbl_total_tile', 'Label: Total', 'মোট টাকা', [ 'show_meta' => 'yes', 'show_m_total' => 'yes' ] );

		$this->sw( 'show_m_payment', 'Payment method', 'yes', [ 'show_meta' => 'yes' ] );
		$this->t( 'lbl_payment', 'Label: Payment Method', 'পেমেন্ট মেথড', [ 'show_meta' => 'yes', 'show_m_payment' => 'yes' ] );

		$this->end_controls_section();
	}

	private function controls_progress() {
		$this->sec( 'section_progress', 'Progress & Delivery Estimate' );

		$this->sw( 'show_track', 'Show progress steps', 'yes', [], 'Follows the order status: received, preparing (processing), delivered (completed). Hidden for cancelled, failed and refunded orders.' );
		$this->t( 'lbl_step1', 'Step 1', 'অর্ডার গৃহীত', [ 'show_track' => 'yes' ] );
		$this->t( 'lbl_step2', 'Step 2', 'প্রস্তুত করা হচ্ছে', [ 'show_track' => 'yes' ] );
		$this->t( 'lbl_step3', 'Step 3', 'ডেলিভারি সম্পন্ন', [ 'show_track' => 'yes' ] );

		$this->add_control( 'eta_divider', [
			'type' => Controls_Manager::DIVIDER,
		] );

		$this->sw( 'show_eta', 'Show estimated delivery', '', [], 'Counted from the day the order was placed.' );
		$this->t( 'lbl_eta', 'Label', 'আনুমানিক ডেলিভারি', [ 'show_eta' => 'yes' ] );
		$this->add_control( 'eta_min', [
			'label'     => 'From (days)',
			'type'      => Controls_Manager::NUMBER,
			'min'       => 0,
			'max'       => 60,
			'default'   => 2,
			'condition' => [ 'show_eta' => 'yes' ],
		] );
		$this->add_control( 'eta_max', [
			'label'     => 'To (days)',
			'type'      => Controls_Manager::NUMBER,
			'min'       => 0,
			'max'       => 60,
			'default'   => 4,
			'condition' => [ 'show_eta' => 'yes' ],
		] );

		$this->end_controls_section();
	}

	private function controls_items() {
		$this->sec( 'section_items', 'Products & Totals' );

		$this->t( 'ttl_items', 'Heading', 'আপনার অর্ডার' );
		$this->sw( 'show_thumb', 'Product image' );
		$this->sw( 'show_qty', 'Quantity' );

		$this->t( 'lbl_subtotal', 'Label: Subtotal', 'সাবটোটাল' );
		$this->t( 'lbl_discount', 'Label: Discount', 'ডিসকাউন্ট' );
		$this->t( 'lbl_shipping', 'Label: Delivery Charge', 'ডেলিভারি চার্জ' );
		$this->sw( 'show_ship_method', 'Show delivery method name', 'yes' );
		$this->t( 'lbl_free', 'Text: Free Delivery', 'ফ্রি' );
		$this->t( 'lbl_total', 'Label: Total', 'সর্বমোট' );
		$this->t( 'lbl_refunded', 'Label: Refunded', 'ফেরত দেওয়া হয়েছে' );

		$this->add_control( 'instr_divider', [
			'type' => Controls_Manager::DIVIDER,
		] );

		$this->sw( 'show_instructions', 'Show payment instructions', 'yes', [], 'Shows what the payment method prints on the thank-you page, such as bank transfer details or the cash-on-delivery message.' );
		$this->t( 'ttl_instructions', 'Heading', 'পেমেন্ট নির্দেশনা', [ 'show_instructions' => 'yes' ] );

		$this->end_controls_section();
	}

	private function controls_details() {
		$this->sec( 'section_details', 'Delivery Details' );

		$this->sw( 'show_details', 'Show delivery details', 'yes', [], 'Personal details are only shown to the person who placed the order.' );
		$this->t( 'ttl_details', 'Heading', 'ডেলিভারি তথ্য', [ 'show_details' => 'yes' ] );

		$this->sw( 'show_d_name', 'Name', 'yes', [ 'show_details' => 'yes' ] );
		$this->t( 'lbl_d_name', 'Label: Name', 'নাম', [ 'show_details' => 'yes', 'show_d_name' => 'yes' ] );
		$this->sw( 'show_d_phone', 'Phone', 'yes', [ 'show_details' => 'yes' ] );
		$this->t( 'lbl_d_phone', 'Label: Phone', 'মোবাইল', [ 'show_details' => 'yes', 'show_d_phone' => 'yes' ] );
		$this->sw( 'show_d_email', 'Email', 'yes', [ 'show_details' => 'yes' ] );
		$this->t( 'lbl_d_email', 'Label: Email', 'ইমেইল', [ 'show_details' => 'yes', 'show_d_email' => 'yes' ] );
		$this->sw( 'show_d_address', 'Address', 'yes', [ 'show_details' => 'yes' ] );
		$this->t( 'lbl_d_address', 'Label: Address', 'ঠিকানা', [ 'show_details' => 'yes', 'show_d_address' => 'yes' ] );
		$this->sw( 'show_d_note', 'Order note', 'yes', [ 'show_details' => 'yes' ] );
		$this->t( 'lbl_d_note', 'Label: Order Note', 'নোট', [ 'show_details' => 'yes', 'show_d_note' => 'yes' ] );

		$this->end_controls_section();
	}

	private function controls_buttons() {
		$this->sec( 'section_buttons', 'Buttons' );

		$this->sw( 'show_btn_continue', 'Continue shopping button' );
		$this->t( 'txt_continue', 'Text', 'কেনাকাটা চালিয়ে যান', [ 'show_btn_continue' => 'yes' ] );
		$this->t( 'url_continue', 'Link', '', [ 'show_btn_continue' => 'yes' ], false, 'Leave empty to use your WooCommerce shop page.' );

		$this->sw( 'show_btn_contact', 'Contact button (e.g. WhatsApp)', '' );
		$this->t( 'txt_contact', 'Text', 'হোয়াটসঅ্যাপে যোগাযোগ করুন', [ 'show_btn_contact' => 'yes' ] );
		$this->t( 'url_contact', 'Link', '', [ 'show_btn_contact' => 'yes' ], false, 'Example: https://wa.me/8801XXXXXXXXX?text=Order #{order_number}' );

		$this->sw( 'show_btn_print', 'Print button' );
		$this->t( 'txt_print', 'Text', 'প্রিন্ট করুন', [ 'show_btn_print' => 'yes' ] );

		$this->end_controls_section();
	}

	private function controls_layout() {
		$this->sec( 'section_layout', 'Layout' );

		$this->add_control( 'layout', [
			'label'   => 'Layout',
			'type'    => Controls_Manager::SELECT,
			'default' => 'two',
			'options' => [
				'two' => 'Two columns (products | delivery details)',
				'one' => 'One column',
			],
		] );

		$this->add_control( 'cols', [
			'label'     => 'Column Widths',
			'type'      => Controls_Manager::SELECT,
			'default'   => 'minmax(0, 1.45fr) minmax(0, 1fr)',
			'options'   => [
				'minmax(0, 1.45fr) minmax(0, 1fr)' => 'Wide products',
				'minmax(0, 1fr) minmax(0, 1fr)'    => 'Equal',
				'minmax(0, 1fr) minmax(0, 1.45fr)' => 'Wide details',
			],
			'selectors' => [ '{{WRAPPER}} .dts-wrap' => '--dts-cols: {{VALUE}};' ],
			'condition' => [ 'layout' => 'two' ],
		] );

		$this->end_controls_section();
	}

	/* ------------------------------------------------------------------
	 * Style
	 * ------------------------------------------------------------------ */

	private function controls_style() {
		/* ----- card ----- */
		$this->sec( 'style_card', 'Card', true );
		$this->col( 'c_bg', 'Background', [ '.dts-wrap' => 'background-color' ] );
		$this->col( 'c_text', 'Text Color', [ '.dts-wrap' => 'color' ] );
		$this->col( 'c_muted', 'Muted Text Color', [ '.dts-wrap' => '--dts-muted' ] );
		$this->col( 'c_line', 'Divider / Line Color', [ '.dts-wrap' => '--dts-line' ] );
		$this->col( 'c_notch', 'Ticket Notch Color', [ '.dts-wrap' => '--dts-notch' ] );
		$this->add_control( 'c_notch_help', [
			'type'            => Controls_Manager::RAW_HTML,
			'raw'             => 'The half circles on the dashed line. Set this to your page background color so they look cut out.',
			'content_classes' => 'elementor-descriptor',
		] );
		$this->add_group_control( Group_Control_Border::get_type(), [
			'name'     => 'c_border',
			'selector' => '{{WRAPPER}} .dts-wrap',
		] );
		$this->sld( 'c_radius', 'Corner Radius', [ '.dts-wrap' => 'border-radius' ], 50 );
		$this->dims( 'c_pad', 'Padding', '.dts-wrap' );
		$this->add_group_control( Group_Control_Box_Shadow::get_type(), [
			'name'     => 'c_shadow',
			'selector' => '{{WRAPPER}} .dts-wrap',
		] );
		$this->end_controls_section();

		/* ----- success message ----- */
		$this->sec( 'style_hero', 'Success Message', true );
		$this->col( 'h_seal', 'Check Icon Color', [ '.dts-wrap' => '--dts-ok' ] );
		$this->sld( 'h_seal_size', 'Check Icon Size', [ '.dts-seal' => 'width, height' ], 140, 64 );
		$this->col( 'h_title_c', 'Heading Color', [ '.dts-title' => 'color' ] );
		$this->typo( 'h_title_t', '.dts-title', 'Heading Typography' );
		$this->col( 'h_sub_c', 'Text Color', [ '.dts-sub' => 'color' ] );
		$this->typo( 'h_sub_t', '.dts-sub', 'Text Typography' );
		$this->typo( 'h_badge_t', '.dts-badge', 'Badge Typography' );
		$this->end_controls_section();

		/* ----- info strip ----- */
		$this->sec( 'style_meta', 'Order Info Strip', true );
		$this->col( 'm_bg', 'Background', [ '.dts-meta' => 'background-color' ] );
		$this->sld( 'm_radius', 'Corner Radius', [ '.dts-meta' => 'border-radius' ], 40 );
		$this->col( 'm_label_c', 'Label Color', [ '.dts-meta dt' => 'color' ] );
		$this->typo( 'm_label_t', '.dts-meta dt', 'Label Typography' );
		$this->col( 'm_value_c', 'Value Color', [ '.dts-meta dd' => 'color' ] );
		$this->typo( 'm_value_t', '.dts-meta dd', 'Value Typography' );
		$this->end_controls_section();

		/* ----- progress ----- */
		$this->sec( 'style_progress', 'Progress Steps', true );
		$this->col( 'p_on', 'Active / Done Color', [ '.dts-wrap' => '--dts-step-on' ] );
		$this->col( 'p_off', 'Upcoming Color', [ '.dts-wrap' => '--dts-step-off' ] );
		$this->col( 'p_label_c', 'Label Color', [ '.dts-step-label' => 'color' ] );
		$this->col( 'p_label_on', 'Current Step Label Color', [ '.dts-step.is-current .dts-step-label, {{WRAPPER}} .dts-step.is-done .dts-step-label' => 'color' ] );
		$this->typo( 'p_label_t', '.dts-step-label', 'Label Typography' );
		$this->col( 'eta_c', 'Delivery Estimate Color', [ '.dts-eta strong' => 'color' ] );
		$this->typo( 'eta_t', '.dts-eta', 'Delivery Estimate Typography' );
		$this->end_controls_section();

		/* ----- products ----- */
		$this->sec( 'style_items', 'Products', true );
		$this->col( 'i_ttl_c', 'Heading Color', [ '.dts-sec-title' => 'color' ] );
		$this->typo( 'i_ttl_t', '.dts-sec-title', 'Heading Typography' );
		$this->col( 'i_name_c', 'Product Name Color', [ '.dts-item-name' => 'color' ] );
		$this->typo( 'i_name_t', '.dts-item-name', 'Product Name Typography' );
		$this->col( 'i_meta_c', 'Variation / Quantity Color', [ '.dts-item-meta, {{WRAPPER}} .dts-item-qty' => 'color' ] );
		$this->col( 'i_price_c', 'Price Color', [ '.dts-item-total' => 'color' ] );
		$this->typo( 'i_price_t', '.dts-item-total', 'Price Typography' );
		$this->sld( 'i_thumb', 'Image Size', [ '.dts-thumb' => 'width, height' ], 140, 56 );
		$this->sld( 'i_thumb_r', 'Image Corner Radius', [ '.dts-thumb' => 'border-radius' ], 40 );
		$this->end_controls_section();

		/* ----- totals ----- */
		$this->sec( 'style_totals', 'Totals', true );
		$this->col( 't_label_c', 'Label Color', [ '.dts-row dt' => 'color' ] );
		$this->col( 't_value_c', 'Value Color', [ '.dts-row dd' => 'color' ] );
		$this->typo( 't_t', '.dts-row', 'Typography' );
		$this->col( 't_neg_c', 'Discount Color', [ '.dts-neg dd' => 'color' ] );
		$this->col( 't_total_c', 'Total Color', [ '.dts-row-total dt, {{WRAPPER}} .dts-row-total dd' => 'color' ] );
		$this->typo( 't_total_t', '.dts-row-total', 'Total Typography' );
		$this->end_controls_section();

		/* ----- details ----- */
		$this->sec( 'style_details', 'Delivery Details', true );
		$this->col( 'd_bg', 'Panel Background', [ '.dts-side' => 'background-color' ] );
		$this->sld( 'd_radius', 'Panel Corner Radius', [ '.dts-side' => 'border-radius' ], 40 );
		$this->dims( 'd_pad', 'Panel Padding', '.dts-side' );
		$this->col( 'd_label_c', 'Label Color', [ '.dts-detail dt' => 'color' ] );
		$this->typo( 'd_label_t', '.dts-detail dt', 'Label Typography' );
		$this->col( 'd_value_c', 'Value Color', [ '.dts-detail dd' => 'color' ] );
		$this->typo( 'd_value_t', '.dts-detail dd', 'Value Typography' );
		$this->end_controls_section();

		/* ----- payment note ----- */
		$this->sec( 'style_note', 'Payment Instructions', true );
		$this->col( 'n_bg', 'Background', [ '.dts-note' => 'background-color' ] );
		$this->col( 'n_c', 'Text Color', [ '.dts-note' => 'color' ] );
		$this->col( 'n_accent', 'Accent Color', [ '.dts-note' => 'border-color' ] );
		$this->typo( 'n_t', '.dts-note-body', 'Text Typography' );
		$this->end_controls_section();

		/* ----- buttons ----- */
		$this->sec( 'style_buttons', 'Buttons', true );
		$this->typo( 'b_t', '.dts-btn', 'Typography' );
		$this->col( 'b_bg', 'Primary Background', [ '.dts-btn-primary' => 'background-color, border-color' ] );
		$this->col( 'b_c', 'Primary Text', [ '.dts-btn-primary' => 'color' ] );
		$this->col( 'b_bg_h', 'Primary Hover Background', [ '.dts-btn-primary:hover, {{WRAPPER}} .dts-btn-primary:focus-visible' => 'background-color, border-color' ] );
		$this->col( 'b_g_c', 'Secondary Text', [ '.dts-btn-ghost' => 'color' ] );
		$this->col( 'b_g_b', 'Secondary Border', [ '.dts-btn-ghost' => 'border-color' ] );
		$this->sld( 'b_radius', 'Corner Radius', [ '.dts-btn' => 'border-radius' ], 60 );
		$this->dims( 'b_pad', 'Padding', '.dts-btn' );
		$this->add_responsive_control( 'b_align', [
			'label'     => 'Alignment',
			'type'      => Controls_Manager::CHOOSE,
			'options'   => [
				'flex-start' => [ 'title' => 'Left', 'icon' => 'eicon-text-align-left' ],
				'center'     => [ 'title' => 'Center', 'icon' => 'eicon-text-align-center' ],
				'flex-end'   => [ 'title' => 'Right', 'icon' => 'eicon-text-align-right' ],
			],
			'selectors' => [ '{{WRAPPER}} .dts-actions' => 'justify-content: {{VALUE}};' ],
		] );
		$this->end_controls_section();
	}

	/* ------------------------------------------------------------------
	 * Output
	 * ------------------------------------------------------------------ */

	protected function render() {
		DTC_Summary::render( $this->get_settings_for_display() );
	}
}

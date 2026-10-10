<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

/**
 * Small helpers that keep the Elementor control lists short.
 * Used by the Order Summary widget.
 */
trait DTC_Controls {

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

	private function t( $id, $label, $default = '', $condition = [], $textarea = false, $desc = '' ) {
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
		if ( $desc ) {
			$args['description'] = $desc;
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
}

<?php
/**
 * Display attribute swatches
 */
namespace Emerce\VariationSwatches;

defined( 'ABSPATH' ) || exit;

use Emerce\VariationSwatches\Admin\Term_Meta;

class Swatches {
	/**
	 * The single instance of the class
	 *
	 * @var Emerce\VariationSwatches\Swatches
	 */
	protected static $_instance = null;

	/**
	 * Main instance
	 *
	 * @return Emerce\VariationSwatches\Swatches
	 */
	public static function instance() {
		if ( null == self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Class constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', [ $this, 'swatches_html' ], 100, 2 );
		add_action( 'emerce_swatches_grid_action', array( $this, 'product_attribute' ), 10 );
	}

	/**
	 * Enqueue scripts and stylesheets
	 */
	public function enqueue_scripts() {
		wp_enqueue_style( 'emerce-variation-swatches', plugins_url( 'assets/css/frontend.css', EMERCE_VARIATION_SWATCHES_FILE ), [], '20210628' );
		wp_add_inline_style( 'emerce-variation-swatches', $this->inline_style() );

		wp_enqueue_script( 'emerce-variation-swatches', plugins_url( 'assets/js/frontend.js', EMERCE_VARIATION_SWATCHES_FILE ), [ 'jquery' ], '20210908', true );
		wp_localize_script( 'emerce-variation-swatches', 'emerce_variation_swatches_params', [
			'show_selected_label' => wc_string_to_bool( Helper::get_settings( 'show_selected_label' ) ),
		] );
	}

	/**
	 * Inline style for variation swatches. Generated from the settings.
	 *
	 * @return string The CSS code
	 */
	public function inline_style() {
		$size = Helper::get_settings( 'size' );

		$css = '.emerce-variation-swatches__item { width: ' . absint( $size['width'] ) . 'px; height: ' . absint( $size['height'] ) . 'px; line-height: ' . absint( $size['height'] ) . 'px; }';
		$css .= '.emerce-variation-swatches--round.emerce-variation-swatches--button .emerce-variation-swatches__item {border-radius: ' . ( absint( $size['height'] ) / 2 ) . 'px}';

		return apply_filters( 'emerce_variation_swatches_css', $css );
	}

	/**
	 * Filter function to add swatches bellow the default selector
	 *
	 * @param $html
	 * @param $args
	 *
	 * @return string
	 */
	public function swatches_html( $html, $args ) {
		$options   = $args['options'];
		$product   = $args['product'];
		$attribute = $args['attribute'];
		$name      = $args['name'] ? $args['name'] : 'attribute_' . sanitize_title( $attribute );

		if ( empty( $options ) && ! empty( $product ) && ! empty( $attribute ) ) {
			$attributes = $product->get_variation_attributes();
			$options    = $attributes[ $attribute ];
		}

		if ( empty( $options ) ) {
			return $html;
		}

		// Get per-product swatches settings.
		$swatches_args = $this->get_swatches_args( $product->get_id(), $attribute );
		$swatches_args = wp_parse_args( $args, $swatches_args );

		if ( ! array_key_exists( $swatches_args['swatches_type'], Term_Meta::instance()->get_swatches_types() ) ) {
			return $html;
		}

		// Let's render the swatches html.
		$swatches_html = '';

		if ( $product && taxonomy_exists( $attribute ) ) {
			// Get terms if this is a taxonomy - ordered. We need the names too.
			$terms = wc_get_product_terms(
				$product->get_id(),
				$attribute,
				[ 'fields' => 'all' ]
			);

			foreach ( $terms as $term ) {
				if ( in_array( $term->slug, $options, true ) ) {
					$swatches_html .= $this->get_term_swatches( $term, $swatches_args );
				}
			}
		} else {
			foreach ( $options as $option ) {
				$swatches_html .= $this->get_term_swatches( $option, $swatches_args );
			}
		}

		if ( ! empty( $swatches_html ) ) {
			$classes       = [
				'emerce-variation-swatches',
				'emerce-variation-swatches--' . $swatches_args['swatches_type'],
				'emerce-variation-swatches--' . $swatches_args['swatches_shape']
			];

			if ( $swatches_args['swatches_tooltip'] ) {
				$classes[] = 'emerce-variation-swatches--has-tooltip';
			}

			$swatches_html = '<ul class="emerce-variation-swatches__wrapper" data-attribute_name="' . esc_attr( $name ) . '">' . $swatches_html . '</ul>';
			$html          = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">' . $html . $swatches_html . '</div>';
		}

		return $html;
	}
	
	//==============================================================================
	// Get variations
	//==============================================================================

	function get_variations( $default_attribute ) {
		global $product;

		$variations = array();
		if ( $product->get_type() == 'variable' ) {
			$args = array(
				'post_parent' => $product->get_id(),
				'post_type'   => 'product_variation',
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
				'fields'      => 'ids',
				'post_status' => 'publish',
				'numberposts' => - 1
			);

			if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
				$args['meta_query'][] = array(
					'key'     => '_stock_status',
					'value'   => 'instock',
					'compare' => '=',
				);
			}

			$thumbnail_id = get_post_thumbnail_id();

			$posts = get_posts( $args );

			foreach ( $posts as $post_id ) {
				$attachment_id = get_post_thumbnail_id( $post_id );
				$attribute     = $this->get_variation_attributes( $post_id, 'attribute_' . $default_attribute );

				if ( ! $attachment_id ) {
					$attachment_id = $thumbnail_id;
				}

				if ( $attribute ) {
					$variations[$attribute[0]] = $attachment_id;
				}

			}

		}

		return $variations;
	}


public function get_variation_attributes( $child_id, $attribute ) {
		global $wpdb;

		$values = array_unique(
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN (" . $child_id . ")",
					$attribute
				)
			)
		);

		return $values;
	}
	function product_attribute() {

		//global $tdl_options;

		//$default_attribute = sanitize_title( TDL_Opt::getOption('product_attribute') );

		

		$default_attribute = 'pa_color';

		global $product;
		$attributes         = maybe_unserialize( get_post_meta( $product->get_id(), '_product_attributes', true ) );
		$product_attributes = maybe_unserialize( get_post_meta( $product->get_id(), 'attributes_extra', true ) );

		if ( $product_attributes == 'none' ) {
			return;
		}

		if ( $product_attributes == '' ) {
			$product_attributes = $default_attribute;
		}

		$variations = $this->get_variations( $product_attributes );

		if ( ! $attributes ) {
			return;
		}

		foreach ( $attributes as $attribute ) {


			if ( $product->get_type() == 'variable' ) {
				if ( ! $attribute['is_variation'] ) {
					continue;
				}
			}

			if ( sanitize_title( $attribute['name'] ) == $product_attributes ) {

				echo '<ul class="attr-swatches">';
				if ( $attribute['is_taxonomy'] ) {
					$post_terms = wp_get_post_terms( $product->get_id(), $attribute['name'] );

					$attr_type = 'color';


					$found = false;
					foreach ( $post_terms as $term ) {
						$css_class = '';
						if ( is_wp_error( $term ) ) {
							continue;
						}
						if ( $variations && isset( $variations[$term->slug] ) ) {
							$attachment_id = $variations[$term->slug];
							$attachment    = wp_get_attachment_image_src( $attachment_id, 'shop_catalog' );
							$image_srcset  = '';

								$image_srcset = wp_get_attachment_image_srcset( $attachment_id, 'shop_catalog' );


							if ( $attachment_id == get_post_thumbnail_id() && ! $found ) {
								$css_class .= ' selected';
								$found = true;
							}

							if ( $attachment ) {
								$css_class .= ' xpc-swatch-variation-item';
								$img_src = $attachment[0];
								echo wp_kses_post($this->alt_swatch_html( $term, $attr_type, $img_src, $css_class, $image_srcset ));
							}

						}
					}
				}
				echo '</ul>';
				break;
			}
		}

	}
	
	//==============================================================================
	// Print HTML of a single swatch
	//==============================================================================

	public function alt_swatch_html( $term, $attr_type, $img_src, $css_class, $image_srcset ) {

		$html = '';
		$name = $term->name;
	$swatches_value = is_object( $term ) ? Term_Meta::instance()->get_meta( $term->term_id, $attr_type ) : '';
		switch ( $attr_type ) {
			case 'color':
				$color = $swatches_value;
				list( $r, $g, $b ) = sscanf( $color, "#%02x%02x%02x" );
				$html = sprintf(
					'<li class="swatch swatch-color %s" data-src="%s" data-src-set="%s"><span class="sub-swatch" ><span class="sub-swatch-bg" style="background-color:%s;color:%s;"></span><span class="tooltip">%s</span></span></li>',
					esc_attr( $css_class ),
					esc_url( $img_src ),
					esc_attr( $image_srcset ),
					esc_attr( $color ),
					"rgba($r,$g,$b,0.5)",
					esc_attr( $name )
				);
				break;

			case 'image':
				$image = $swatches_value;
				if ( $image ) {
					$image = wp_get_attachment_image_src( $image );
					$image = $image ? $image[0] : WC()->plugin_url() . '/assets/images/placeholder.png';
					$html  = sprintf(
						'<li class="swatch swatch-image %s" data-src="%s" data-src-set="%s">
						<span class="sub-swatch">
						<span class="sub-swatch-bg"><img src="%s"></span>
						<span class="tooltip">%s</span>
						</span>
						</li>',
						esc_attr( $css_class ),
						esc_url( $img_src ),
						esc_attr( $image_srcset ),
						esc_url( $image ),
						esc_attr( $name )
					);
				}

				break;

			default:
				$label = $swatches_value;
				$label = $label ? $label : $name;
				$html  = sprintf(
					'<li class="swatch swatch-label %s" data-src="%s" data-src-set="%s" title="%s"><span>%s</span></li>',
					esc_attr( $css_class ),
					esc_url( $img_src ),
					esc_attr( $image_srcset ),
					esc_attr( $name ),
					esc_html( $label )
				);
				break;


		}

		return $html;
	}



	/**
	 * Get HTML of a single attribute term swatches
	 *
	 * @param object|string $term
	 * @param array $args
	 * @return string
	 */
	public function get_term_swatches( $term, $args ) {
		$type  = $args['swatches_type'];
		$value = is_object( $term ) ? $term->slug : $term;
		$name  = is_object( $term ) ? $term->name : $term;
		$name  = apply_filters( 'woocommerce_variation_option_name', $name );
		$size  = ! empty( $args['swatches_size'] ) ? sprintf( 'width: %1$dpx; height: %2$dpx; line-height: %2$dpx;', absint( $args['swatches_size']['width'] ), absint( $args['swatches_size']['height'] ) ) : '';
		$html  = '';

		if ( is_object( $term ) ) {
			$selected = sanitize_title( $args['selected'] ) == $value;
		} else {
			// This handles < 2.4.0 bw compatibility where text attributes were not sanitized.
			$selected = sanitize_title( $args['selected'] ) === $args['selected'] ? $args['selected'] == sanitize_title( $value ) : $args['selected'] == $value;
		}

		$key = is_object( $term ) ? $term->term_id : sanitize_title( $term );

		if ( isset( $args['swatches_attributes'][ $key ] ) && isset( $args['swatches_attributes'][ $key ][ $type ] ) ) {
			$swatches_value = $args['swatches_attributes'][ $key ][ $type ];
		} else {
			$swatches_value = is_object( $term ) ? Term_Meta::instance()->get_meta( $term->term_id, $type ) : '';
		}

		switch ( $type ) {
			case 'color':
				$html = sprintf(
					'<li class="emerce-variation-swatches__item emerce-variation-swatches__item-%s %s" style="%s" aria-label="%s" data-value="%s" tabindex="0">
						<span class="emerce-variation-swatches__name" style="background-color: %s">%s</span>
					</li>',
					esc_attr( $value ),
					$selected ? 'selected' : '',
					esc_attr( $size ),
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( $swatches_value ),
					esc_html( $name )
				);
				break;

			case 'image':
				$dimension = array_values( $args['swatches_image_size'] );
				$image     = $swatches_value ? Helper::get_image( $swatches_value, $dimension ) : '';
				$image     = $image ? $image[0] : wc_placeholder_img_src( 'thumbnail' );

				$html = sprintf(
					'<li class="emerce-variation-swatches__item emerce-variation-swatches__item-%s %s" style="%s" aria-label="%s" data-value="%s" tabindex="0">
						<img src="%s" alt="%s">
						<span class="emerce-variation-swatches__name">%s</span>
					</li>',
					esc_attr( $value ),
					$selected ? 'selected' : '',
					esc_attr( $size ),
					esc_attr( $name ),
					esc_attr( $value ),
					esc_url( $image ),
					esc_attr( $name ),
					esc_html( $name )
				);
				break;

			case 'label':
				$label = $swatches_value ? $swatches_value : $name;

				$html = sprintf(
					'<li class="emerce-variation-swatches__item emerce-variation-swatches__item-%s %s" style="%s" aria-label="%s" data-value="%s" tabindex="0">
						<span class="emerce-variation-swatches__name">%s</span>
					</li>',
					esc_attr( $value ),
					$selected ? 'selected' : '',
					esc_attr( $size ),
					esc_attr( $name ),
					esc_attr( $value ),
					esc_html( $label )
				);
				break;

			case 'button':
				$html = sprintf(
					'<li class="emerce-variation-swatches__item emerce-variation-swatches__item-%s %s" style="%s" aria-label="%s" data-value="%s" tabindex="0">
						<span class="emerce-variation-swatches__name">%s</span>
					</li>',
					esc_attr( $value ),
					$selected ? 'selected' : '',
					esc_attr( $size ),
					esc_attr( $name ),
					esc_attr( $value ),
					esc_html( $name )
				);
				break;

			default:
				$html = apply_filters( 'emerce_variation_swatches_' . $type . '_html', '', $args );
				break;
		}

		return $html;
	}

	/**
	 * Get attribute swatches args
	 *
	 * @param int $product_id   Product ID
	 * @param string $attribute Attribute name
	 *
	 * @return array
	 */
	public function get_swatches_args( $product_id, $attribute ) {
		$swatches_meta = Helper::get_swatches_meta( $product_id );
		$attribute = sanitize_title( $attribute );

		if ( ! empty( $swatches_meta[ $attribute ] ) ) {
			$swatches_args = [
				'swatches_type'       => $swatches_meta[ $attribute ]['type'],
				'swatches_shape'      => $swatches_meta[ $attribute ]['shape'],
				'swatches_size'       => 'custom' == $swatches_meta[ $attribute ]['size'] ? $swatches_meta[ $attribute ]['custom_size'] : '',
				'swatches_attributes' => $swatches_meta[ $attribute ]['swatches'],
			];

			if ( Helper::is_default( $swatches_args['swatches_type'] ) ) {
				$swatches_args['swatches_type'] = taxonomy_exists( $attribute ) ? Helper::get_attribute_taxonomy( $attribute )->attribute_type : 'select';
				$swatches_args['swatches_attributes'] = [];

				// Auto convert to select.
				if ( 'select' == $swatches_args['swatches_type'] && wc_string_to_bool( Helper::get_settings( 'auto_button' ) ) ) {
					$swatches_args['swatches_type'] = 'button';
				}
			}

			if ( Helper::is_default( $swatches_args['swatches_shape'] ) ) {
				$swatches_args['swatches_shape'] = Helper::get_settings( 'shape' );
			}
		} else {
			$swatches_args = [
				'swatches_type'       => taxonomy_exists( $attribute ) ? Helper::get_attribute_taxonomy( $attribute )->attribute_type : 'select',
				'swatches_shape'      => Helper::get_settings( 'shape' ),
				'swatches_size'       => '',
				'swatches_attributes' => [],
			];

			// Auto convert to select.
			if ( 'select' == $swatches_args['swatches_type'] && wc_string_to_bool( Helper::get_settings( 'auto_button' ) ) ) {
				$swatches_args['swatches_type'] = 'button';
			}
		}

		$swatches_args['swatches_tooltip']    = wc_string_to_bool( Helper::get_settings( 'tooltip' ) );
		$swatches_args['swatches_image_size'] = $swatches_args['swatches_size'] ? $swatches_args['swatches_size'] : Helper::get_settings( 'size' );

		return $swatches_args;
	}
}

Swatches::instance();

<?php

/**
 * Class TA_WC_Variation_Swatches_Frontend
 */
class TA_WC_Variation_Swatches_Frontend {
	/**
	 * The single instance of the class
	 *
	 * @var TA_WC_Variation_Swatches_Frontend
	 */
	protected static $instance = null;

	private $generalSettings, $archiveSettings, $productDesign, $shopDesign, $toolTipDesign;

	/**
	 * Main instance
	 *
	 * @return TA_WC_Variation_Swatches_Frontend
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Class constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'woocommerce_before_variations_form', array( $this, 'get_available_variation' ) );
	add_action( 'woocommerce_before_shop_loop_item', array( $this, 'product_attribute' ), 10 );
	
	
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array(
			$this,
			'get_swatch_html'
		), 100, 2 );
		add_filter( 'tawcvs_swatch_html', array( $this, 'swatch_html' ), 5, 4 );

		$latest_option = get_option( 'woosuite_variation_swatches_option' );

		$this->generalSettings = isset( $latest_option['general'] ) ? $latest_option['general'] : array();
		$this->archiveSettings = isset( $latest_option['archive'] ) ? $latest_option['archive'] : array();
		$this->productDesign   = isset( $latest_option['design']['productDesign'] ) ? $latest_option['design']['productDesign'] : array();
		$this->shopDesign      = isset( $latest_option['design']['shopDesign'] ) ? $latest_option['design']['shopDesign'] : array();
		$this->toolTipDesign   = isset( $latest_option['design']['toolTipDesign'] ) ? $latest_option['design']['toolTipDesign'] : array();


		if ( isset( $this->archiveSettings['show-clear-link'] ) && ! $this->archiveSettings['show-clear-link'] ) {
			add_filter( 'woocommerce_reset_variations_link', array(
				$this,
				'tawcvs_show_clear_link_on_variations_on_shop_page'
			) );
		}

		if ( isset( $this->archiveSettings['show-swatch'] )
		     && $this->archiveSettings['show-swatch']
		     && ! TA_WC_Variation_Swatches::is_pro_addon_active() ) {
			add_filter( 'woocommerce_loop_add_to_cart_link', array(
				$this,
				'display_variations_on_shop_page_before_add_to_cart_btn'
			), 10, 3 );
		}
		add_action( 'wp_head', array( $this, 'apply_custom_design_styles' ) );

		add_filter( 'tawcvs_tax_attributes', array( $this, 'get_updated_attribute_type' ), 10 );
	}

	public function get_updated_attribute_type( $attr ) {
		if ( empty( $attr ) ) {
			return '';
		}
		$supported_swatch_types    = TA_WCVS()->types;
		$dropdown_to_label_setting = isset( $this->generalSettings['dropdown-to-label'] ) && $this->generalSettings['dropdown-to-label'];

		// If the type isn't supported, and we turned on the setting to convert dropdown to label/image
		// then we forced that type to the corresponding type
		if ( ! array_key_exists( $attr->attribute_type, $supported_swatch_types ) ) {

			if ( $dropdown_to_label_setting
			     && isset( $this->generalSettings[ 'dropdown-to-label-attribute-' . $attr->attribute_name ] )
			     && $this->generalSettings[ 'dropdown-to-label-attribute-' . $attr->attribute_name ] ) {

				$attr->attribute_type = 'label';

			} else {
				//Default attribute type should be select
				$attr->attribute_type = 'select';

			}

		}
		$attr->attribute_type = apply_filters( 'tawcvs_attribute_type', $attr->attribute_type, $attr, $supported_swatch_types );

		return $attr;
	}

	/**
	 * Getting all available variations to a hidden elements
	 */
	public function get_available_variation() {
		global $product;
		if ( $product instanceof WC_Product_Variable ) {
			$variations_json = wp_json_encode( $product->get_available_variations() );
			$variations_attr = function_exists( 'wc_esc_json' ) ? wc_esc_json( $variations_json ) : _wp_specialchars( $variations_json, ENT_QUOTES, 'UTF-8', true );
			if ( ! empty( $variations_attr ) ) {
				?>
                <div class="tawcvs-placeholder-element hidden tawcvs-available-product-variation"
                     data-product_variations="<?php echo $variations_attr; ?>"></div>
			<?php }
		}
	}

	/**
	 * Enqueue scripts and stylesheets
	 */
	public function enqueue_scripts() {
		if ( isset( $this->generalSettings['disable-plugin-stylesheet'] ) && ! $this->generalSettings['disable-plugin-stylesheet'] ) {
			wp_enqueue_style( 'tawcvs-frontend', plugins_url( 'assets/css/frontend.css', TAWC_VS_PLUGIN_FILE ), array(), WCVS_PLUGIN_VERSION );
		}
		if ( is_shop() || is_product_category() || is_product_tag() ) {
			wp_enqueue_style( 'tawcvs-frontend-for-listing-pages', plugins_url( 'assets/css/frontend-list-products.css', TAWC_VS_PLUGIN_FILE ) );
		}
		wp_enqueue_script( 'tawcvs-frontend', plugins_url( 'assets/js/frontend.js', TAWC_VS_PLUGIN_FILE ), array( 'jquery' ), WCVS_PLUGIN_VERSION, true );
	}

	/**
	 * Filter function to add swatches bellow the default selector
	 *
	 * @param $html
	 * @param $args
	 *
	 * @return string
	 */
	public function get_swatch_html( $html, $args ) {
		global $woocommerce_loop;

		$attr = TA_WCVS()->get_tax_attribute( $args['attribute'] );

		// Return if this is normal attribute
		if ( empty( $attr ) || ! $args['product'] instanceof WC_Product_Variable ) {
			return $html;
		}

		$options            = $args['options'];
		$product            = $args['product'];
		$attribute_tax_name = $args['attribute'];
		$class              = "variation-selector variation-select-{$attr->attribute_type}";
		$swatches           = '';
		$is_product_page    = is_product();
		$defined_limit      = apply_filters( 'tawcvs_swatch_limit_number', 0 );
		$out_of_stock_state = apply_filters( 'tawcvs_out_of_stock_state', '' );

		//If this product has disabled the variation swatches
		if ( $this->is_disabled_variation_swatches( $product ) ) {
			return $html;
		}

		if ( empty( $options ) && ! empty( $product ) && ! empty( $attribute_tax_name ) ) {
			$attributes = $product->get_variation_attributes();
			$options    = $attributes[ $attribute_tax_name ];
		}


		if ( empty( $attr->attribute_type ) ) {
			return $html;
		}

		// Add new option for tooltip to $args variable.
		$args['tooltip'] = apply_filters( 'tawcvs_tooltip_enabled', $this->is_tooltip_enabled() );
		//Get the product variation detail for each attribute
		//If there are more than one attributes, the first one will be applied
		$collected_variations = TA_WC_Variation_Swatches::get_detailed_product_variations( $product, $attribute_tax_name );

		if ( ! empty( $options ) && taxonomy_exists( $attribute_tax_name ) ) {
			// Get terms if this is a taxonomy - ordered. We need the names too.
			$terms = $this->get_product_variation_term( $product, $defined_limit, $attribute_tax_name, $options );
			foreach ( $terms as $term ) {

				//Check if we have the product variable for this attribute
				if ( isset( $collected_variations[ $term->slug ] ) ) {
					$args['variation_product'] = $collected_variations[ $term->slug ];
				} else {
					unset( $args['variation_product'] );
				}

				$swatches .= apply_filters( 'tawcvs_swatch_html', '', $term, $attr->attribute_type, $args );

			}
			//If we are on shop/archived page (not product page), we will check the defined limit number of variations
			//the product still have more variations -> show the view more icon
			if ( ( ! $is_product_page || $woocommerce_loop['name'] == 'related' )
			     && 0 < $defined_limit
			     && count( $options ) > $defined_limit ) {
				$swatches .= apply_filters( 'tawcvs_swatch_show_more_html', '', $product );
			}
		}

		if ( ! empty( $swatches ) ) {
			$class    .= ' hidden';
			$swatches = '<div class="tawcvs-swatches oss-' . $out_of_stock_state . '" data-attribute_name="attribute_' . esc_attr( $attribute_tax_name ) . '">' . $swatches . '</div>';
			$html     = '<div class="' . esc_attr( $class ) . '">' . $html . '</div>' . $swatches;
		}

		return $html;
	}

	private function is_disabled_variation_swatches( $product ) {
		if ( ! $product instanceof WC_Product_Variable ) {
			return false;
		}

		return 'yes' === get_post_meta( $product->get_id(), 'variation_swatches_disabled', true );
	}

	/**
	 * @param $product
	 * @param $defined_limit
	 * @param $attribute_tax_name
	 * @param $options
	 *
	 * @return array
	 */
	public function get_product_variation_term( $product, $defined_limit, $attribute_tax_name, $options ) {
		global $woocommerce_loop;

		$terms = wc_get_product_terms( $product->get_id(), $attribute_tax_name, array( 'fields' => 'all', ) );
		$terms = array_filter( $terms, function ( $term ) use ( $options ) {
			return in_array( $term->slug, $options, true );
		} );
		if ( $defined_limit > 0 && ( ! is_product() || $woocommerce_loop['name'] == 'related' ) ) {
			$terms = array_slice( $terms, 0, $defined_limit );
		}

		return $terms;
	}

	/**
	 * Print HTML of a single swatch
	 *
	 * @param $html
	 * @param $term
	 * @param $type
	 * @param $args
	 *
	 * @return string
	 */
	public function swatch_html( $html, $term, $type, $args ) {

		$selected = sanitize_title( $args['selected'] ) == $term->slug ? 'selected' : '';
		$name     = esc_html( apply_filters( 'woocommerce_variation_option_name', $term->name ) );

		$tooltip = $this->get_tooltip_html( '', $term, $name, $args );
		$tooltip = apply_filters( 'tawcvs_tooltip_html', $tooltip, $term, $name, $args );

		$swatchShape = isset( $this->generalSettings['swatch-shape'] ) ? $this->generalSettings['swatch-shape'] : 'circle';


		switch ( $type ) {
			case 'color':
				$main_color            = get_term_meta( $term->term_id, 'color', true );
				$formatted_color_style = TA_WC_Variation_Swatches::generate_color_style( $term->term_id, $main_color );
				list( $r, $g, $b ) = sscanf( $main_color, "#%02x%02x%02x" );
				$html = sprintf(
					'<div class="swatch-item-wrapper"><div class="swatch swatch-shape-' . $swatchShape . ' swatch-color swatch-%s %s" style="background:%s;color:%s;" data-value="%s"></div>%s</div>',
					esc_attr( $term->slug ),
					$selected,
					esc_attr( $formatted_color_style ),
					"rgba($r,$g,$b,0.5)",
					esc_attr( $term->slug ),
					$tooltip
				);
				break;

			case 'image':
				// First, we check the default thumbnail of attribute variation
				$attach_id = get_term_meta( $term->term_id, 'image', true );
				if ( ! empty( $attach_id ) ) {
					$image_url = wp_get_attachment_image_url( $attach_id );
				} else {
					//If we also do not have default thumbnail, we will use the placeholder image of WC
					$image_url = WC()->plugin_url() . '/assets/images/placeholder.png';
				}
				$image_url = apply_filters( 'tawcvs_product_swatch_image_url', $image_url, $args );

				$html = sprintf(
					'<div class="swatch-item-wrapper"><div class="swatch swatch-shape-' . $swatchShape . ' swatch-image swatch-%s %s %s" data-value="%s"><img src="%s" alt="%s"></div>%s</div>',
					esc_attr( $term->slug ),
					$selected,
					apply_filters( 'tawcvs_swatch_image_ratio_class', 'swatch-ratio-disabled' ),
					esc_attr( $term->slug ),
					esc_url( $image_url ),
					esc_attr( $name ),
					$tooltip
				);
				break;
			case 'label':
				$label = get_term_meta( $term->term_id, 'label', true );
				$label = $label ?: $name;
				$html  = sprintf(
					'<div class="swatch-item-wrapper"><div class="swatch swatch-shape-' . $swatchShape . ' swatch-label swatch-%s %s" data-value="%s"><span class="text">%s</span></div>%s</div>',
					esc_attr( $term->slug ),
					$selected,
					esc_attr( $term->slug ),
					esc_html( $label ),
					$tooltip
				);
				break;
		}

		return apply_filters( 'tawcvs_swatch_item_html', $html, $term, $type, $selected, $name, $tooltip, $swatchShape );
	}


	public function tawcvs_show_clear_link_on_variations_on_shop_page( $content ) {
		if ( ! is_product() ) {
			return '';
		}

		return $content;
	}

	/**
	 * Showing the variation section before the Add to cart button
	 *
	 * @param $html
	 * @param $product
	 * @param $args
	 *
	 * @return mixed|void
	 */
	public function display_variations_on_shop_page_before_add_to_cart_btn( $html, $product, $args ) {
		global $product;

		if ( $product instanceof WC_Product_Variable ) {

			//Removing the Show option button
			remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart' );

			//Rendering
			$alignment = isset( $this->archiveSettings['swatch-alignment'] ) ? $this->archiveSettings['swatch-alignment'] : 'left';
			echo '<div class="swatch-align-' . $alignment . '">';
			do_action( 'woocommerce_variable_add_to_cart' );
			echo '</div>';
		} else {
			return $html;
		}

	}

	/**
	 * Return the boolean to check if tooltip is enabled in Archive/Shop/Product pages
	 *
	 * @return bool
	 */
	public function is_tooltip_enabled() {
		if ( ! is_product() ) {
			return false;
		}

		return wc_string_to_bool( isset( $this->generalSettings['enable-tooltip'] ) ? $this->generalSettings['enable-tooltip'] : 0 );
	}

	/**
	 * Render the tooltip html if it is enabled
	 *
	 * @param $html
	 * @param $term
	 * @param $name
	 * @param $args
	 *
	 * @return mixed|string
	 */
	public function get_tooltip_html( $html, $term, $name, $args ) {
		if ( ! empty( $args['tooltip'] ) ) {
			$html = '<span class="swatch__tooltip">' . ( $term->description ?: $name ) . '</span>';
		}

		return $html;
	}


	public function apply_custom_design_styles() {
		$page = is_product() ? 'productDesign' : 'shopDesign';
		?>
        <style>
            .woocommerce div.product form.cart.variations_form .tawcvs-swatches,
            .woocommerce.single-product form.cart.variations_form .tawcvs-swatches,
            .woocommerce.archive form.cart.variations_form .tawcvs-swatches {
                margin-top: <?php echo isset($this->{$page}['wrm-top']) ? $this->{$page}['wrm-top'] : '0'; echo isset($this->{$page}['wrm-type']) ? $this->{$page}['wrm-type'] : 'px'  ?>;
                margin-right: <?php echo isset($this->{$page}['wrm-right']) ? $this->{$page}['wrm-right'] : '15'; echo isset($this->{$page}['wrm-type']) ? $this->{$page}['wrm-type'] : 'px'  ?>;
                margin-bottom: <?php echo isset($this->{$page}['wrm-bottom']) ? $this->{$page}['wrm-bottom'] : '15'; echo isset($this->{$page}['wrm-type']) ? $this->{$page}['wrm-type'] : 'px'  ?>;
                margin-left: <?php echo isset($this->{$page}['wrm-left']) ? $this->{$page}['wrm-left'] : '0'; echo isset($this->{$page}['wrm-type']) ? $this->{$page}['wrm-type'] : 'px'  ?>;
                padding-top: <?php echo isset($this->{$page}['wrp-top']) ? $this->{$page}['wrp-top'] : '0'; echo isset($this->{$page}['wrp-type']) ? $this->{$page}['wrp-type'] : 'px'  ?>;
                padding-right: <?php echo isset($this->{$page}['wrp-right']) ? $this->{$page}['wrp-right'] : '0'; echo isset($this->{$page}['wrp-type']) ? $this->{$page}['wrp-type'] : 'px'  ?>;
                padding-bottom: <?php echo isset($this->{$page}['wrp-bottom']) ? $this->{$page}['wrp-bottom'] : '0'; echo isset($this->{$page}['wrp-type']) ? $this->{$page}['wrp-type'] : 'px'  ?>;
                padding-left: <?php echo isset($this->{$page}['wrp-left']) ? $this->{$page}['wrp-left'] : '0'; echo isset($this->{$page}['wrp-type']) ? $this->{$page}['wrp-type'] : 'px'  ?>;
            }

            .woocommerce div.product form.cart.variations_form .tawcvs-swatches .swatch-item-wrapper,
            .woocommerce.single-product form.cart.variations_form .tawcvs-swatches .swatch-item-wrapper,
            .woocommerce.archive form.cart.variations_form .tawcvs-swatches .swatch-item-wrapper {
            <?php if($this->{$page}['item-font']):?> font-size: <?php echo isset($this->{$page}['text-font-size']) ? $this->{$page}['text-font-size'] : '12'; echo isset($this->{$page}['item-font-size-type']) ? $this->{$page}['item-font-size-type'] : 'px'; ?>;
            <?php endif;?> margin-top: <?php echo isset($this->{$page}['mar-top']) ? $this->{$page}['mar-top'] : '0'; echo isset($this->{$page}['mar-type']) ? $this->{$page}['mar-type'] : 'px'  ?> !important;
                margin-right: <?php echo isset($this->{$page}['mar-right']) ? $this->{$page}['mar-right'] : '15'; echo isset($this->{$page}['mar-type']) ? $this->{$page}['mar-type'] : 'px'  ?> !important;
                margin-bottom: <?php echo isset($this->{$page}['mar-bottom']) ? $this->{$page}['mar-bottom'] : '15'; echo isset($this->{$page}['mar-type']) ? $this->{$page}['mar-type'] : 'px'  ?> !important;
                margin-left: <?php echo isset($this->{$page}['mar-left']) ? $this->{$page}['mar-left'] : '0'; echo isset($this->{$page}['mar-type']) ? $this->{$page}['mar-type'] : 'px'  ?> !important;
                padding-top: <?php echo isset($this->{$page}['pad-top']) ? $this->{$page}['pad-top'] : '0'; echo isset($this->{$page}['pad-type']) ? $this->{$page}['pad-type'] : 'px'  ?> !important;
                padding-right: <?php echo isset($this->{$page}['pad-right']) ? $this->{$page}['pad-right'] : '0'; echo isset($this->{$page}['pad-type']) ? $this->{$page}['pad-type'] : 'px'  ?> !important;
                padding-bottom: <?php echo isset($this->{$page}['pad-bottom']) ? $this->{$page}['pad-bottom'] : '0'; echo isset($this->{$page}['pad-type']) ? $this->{$page}['pad-type'] : 'px'  ?> !important;
                padding-left: <?php echo isset($this->{$page}['pad-left']) ? $this->{$page}['pad-left'] : '0'; echo isset($this->{$page}['pad-type']) ? $this->{$page}['pad-type'] : 'px'  ?> !important;
            }

            /*tooltip*/
            .woocommerce div.product form.cart.variations_form .tawcvs-swatches .swatch .swatch__tooltip,
            .woocommerce.single-product form.cart.variations_form .tawcvs-swatches .swatch .swatch__tooltip,
            .woocommerce.archive form.cart.variations_form .tawcvs-swatches .swatch .swatch__tooltip {
            <?php if(isset($this->toolTipDesign['item-font']) && $this->toolTipDesign['item-font']):?> font-size: <?php echo isset($this->toolTipDesign['text-font-size']) ? $this->toolTipDesign['text-font-size'] : '14'; echo isset($this->toolTipDesign['item-font-size-type']) ? $this->toolTipDesign['item-font-size-type'] : 'px'; ?>;
            <?php endif;?> width: <?php echo isset($this->toolTipDesign['width']) ? $this->toolTipDesign['width'] . 'px' : 'auto' ?>;
                max-width: <?php echo isset($this->toolTipDesign['max-width']) ? $this->toolTipDesign['max-width'] .'px' : '100%' ?>;
                line-height: <?php echo isset($this->toolTipDesign['line-height']) ?: 'unset'; ?>;
            }
        </style>
		<?php
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

		

		$default_attribute = 'pa_size';

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

				echo '<div class="attr-swatches">';
				if ( $attribute['is_taxonomy'] ) {
					$post_terms = wp_get_post_terms( $product->get_id(), $attribute['name'] );

					$attr_type = '';

					if ( function_exists( 'TA_WCVS' ) ) {
						$attr = TA_WCVS()->get_tax_attribute( $attribute['name'] );
						if ( $attr ) {
							$attr_type = $attr->attribute_type;
						}
					}
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
								$css_class .= ' br-swatch-variation-image';
								$img_src = $attachment[0];
								echo wp_kses_post($this->alt_swatch_html( $term, $attr_type, $img_src, $css_class, $image_srcset ));
							}

						}
					}
				}
				echo '</div>';
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

		switch ( $attr_type ) {
			case 'color':
				$color = get_term_meta( $term->term_id, 'color', true );
				list( $r, $g, $b ) = sscanf( $color, "#%02x%02x%02x" );
				$html = sprintf(
					'<span class="swatch swatch-color %s" data-src="%s" data-src-set="%s"><span class="sub-swatch"><span class="sub-swatch-bg" style="background-color:%s;color:%s;"></span><span class="tooltip">%s</span></span></span>',
					esc_attr( $css_class ),
					esc_url( $img_src ),
					esc_attr( $image_srcset ),
					esc_attr( $color ),
					"rgba($r,$g,$b,0.5)",
					esc_attr( $name )
				);
				break;

			case 'image':
				$image = get_term_meta( $term->term_id, 'image', true );
				if ( $image ) {
					$image = wp_get_attachment_image_src( $image );
					$image = $image ? $image[0] : WC()->plugin_url() . '/assets/images/placeholder.png';
					$html  = sprintf(
						'<span class="swatch swatch-image %s" data-src="%s" data-src-set="%s">
						<span class="sub-swatch">
						<span class="sub-swatch-bg"><img src="%s"></span>
						<span class="tooltip">%s</span>
						</span>
						</span>',
						esc_attr( $css_class ),
						esc_url( $img_src ),
						esc_attr( $image_srcset ),
						esc_url( $image ),
						esc_attr( $name )
					);
				}

				break;

			default:
				$label = get_term_meta( $term->term_id, 'label', true );
				$label = $label ? $label : $name;
				$html  = sprintf(
					'<span class="swatch swatch-label %s" data-src="%s" data-src-set="%s" title="%s"><span>%s</span></span>',
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

}

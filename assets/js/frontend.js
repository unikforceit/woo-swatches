;( function( $, document ) {
	var VariationSwatches = function( $form ) {
		var self = this;

		self.$form = $form;

		// Initial states.
		$form.off( '.emerce-variation-swatches' );

		// Add a new CSS to the form.
		if ( $form.find( '.emerce-variation-swatches__wrapper' ).length ) {
			$form.addClass( 'swatches-support' );
		}

		// Events.
		$form.on( 'click.emerce-variation-swatches', '.emerce-variation-swatches__item', { variationSwatches: self }, self.onSelect );
		$form.on( 'keydown.emerce-variation-swatches', '.emerce-variation-swatches__item', { variationSwatches: self }, self.onKeyPress );
		$form.on( 'click.emerce-variation-swatches', '.reset_variations', { variationSwatches: self }, self.onReset );
		$form.on( 'woocommerce_update_variation_values', { variationSwatches: self }, self.onUpdateAttributes );

		if ( emerce_variation_swatches_params.show_selected_label ) {
			$form.on( 'change', '.value select', { variationSwatches: self }, self.updateLabel );
			$form.on( 'wc_variation_form', { variationSwatches: self }, self.updateAllLabels  );
		}

		$( document.body ).trigger( 'emerce_variation_swatches', self );
	};

	/**
	 * Toggle swatches and update the selected value
	 */
	VariationSwatches.prototype.onSelect = function( event ) {
		event.preventDefault();

		var $el = $( this ),
			$select = $el.closest( '.emerce-variation-swatches' ).find( 'select' ),
			value = $el.data( 'value' );

		if ( $el.hasClass( 'disabled' ) || $el.data( 'disabled' ) ) {
			return;
		}

		// Compatible with old version of WooCommerce.
		$select.trigger( 'focusin' );

		if ( $el.hasClass( 'selected' ) ) {
			$el.removeClass( 'selected' );
			$select.val( '' );
		} else {
			$el.addClass( 'selected' ).siblings( '.selected' ).removeClass( 'selected' );
			$select.val( value );
		}

		$select.trigger( 'change' );
	};

	/**
	 * Keyboard toggle select value
	 */
	VariationSwatches.prototype.onKeyPress = function( event ) {
		if ( event.keyCode && 32 === event.keyCode || event.key && ' ' === event.key || event.keyCode && 13 === event.keyCode || event.key && 'enter' === event.key.toLowerCase() ) {
			event.preventDefault();

			$( this ).trigger( 'click.emerce-variation-swatches' );
		}
	}

	/**
	 * Reset swatches states
	 */
	VariationSwatches.prototype.onReset = function( event ) {
		event.preventDefault();
		event.data.variationSwatches.$form.find( '.emerce-variation-swatches__item.selected' ).removeClass( 'selected' );
		event.data.variationSwatches.$form.find( '.emerce-variation-swatches__item.disabled' ).removeClass( 'disabled' ).data( 'disabled', false );
	};

	/**
	 * Check the state swatches then disable invalid ones
	 */
	VariationSwatches.prototype.onUpdateAttributes = function( event ) {
		setTimeout( function() {
			event.data.variationSwatches.$form.find( '.emerce-variation-swatches' ).each( function( index, row ) {
				var $swatches = $( row ),
					$options = $swatches.find( 'select' ).find( 'option' ),
					$selected = $options.filter( ':selected' ),
					values = [];

				$options.each( function( index, option ) {
					if ( option.value !== '' ) {
						values.push( option.value );
					}
				} );

				$swatches.find( '.emerce-variation-swatches__item' ).each( function( index, item ) {
					var $item = $( item ),
						value = $item.attr( 'data-value' );

					if ( values.indexOf( value ) > -1 ) {
						$item.removeClass( 'disabled' ).data( 'disabled', false );
					} else {
						$item.addClass( 'disabled' ).data( 'disabled', true );

						if ( $selected.length && value === $selected.val() ) {
							$item.removeClass( 'selected' );
						}
					}
				} );
			} );
		}, 100 );
	};

	/**
	 * Update the label of selected attribute
	 */
	VariationSwatches.prototype.updateLabel = function( event ) {
		event.data.variationSwatches.appendSelectedLabel( this );
	}

	/**
	 * Update labels of all attributes on form init
	 */
	VariationSwatches.prototype.updateAllLabels = function( event ) {
		event.data.variationSwatches.$form.find( '.value select' ).each( function() {
			event.data.variationSwatches.appendSelectedLabel( this );
		} );
	}

	/**
	 * Append the selected attribute's label to the variation's label
	 *
	 * @param {object} select The select tag
	 */
	VariationSwatches.prototype.appendSelectedLabel = function( select ) {
		var $label = $( select ).closest( '.value' ).siblings( '.label' ).find( 'label' ),
			$holder = $label.find( '.emerce-variation-swatches__selected-label' );

		if ( ! $holder.length ) {
			$holder = $( '<span class="emerce-variation-swatches__selected-label" />' );

			$label.append( $holder )
		}

		if ( select.value ) {
			$holder.text( select.options[ select.selectedIndex ].text ).show();
		} else {
			$holder.text( '' ).hide();
		}
	}

	/**
	 * Function to call emerce_variation_swatches on jquery selector.
	 */
	$.fn.emerce_variation_swatches = function() {
		new VariationSwatches( this );
		return this;
	};

	/**
	 * Function to init variation swatches on all variation forms
	 */
	function init_variation_swatches() {
		$( '.variations_form:not(.swatches-support)' ).each( function() {
			$( this ).emerce_variation_swatches();
		} );
		
		
	}

	$( function() {
		init_variation_swatches();

		// Support third-party plugins.
		$( document.body ).on( 'init_variation_swatches', function() {
			init_variation_swatches();
		} );

		$( document.body ).trigger( 'emerce_variation_swatches_initialized' );
	} );
} )( jQuery, document )

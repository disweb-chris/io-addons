/**
 * Imprenta Online — Addons · frontend
 *
 * REGLA DE ORO: este archivo no toca la galería de imágenes del producto.
 * No hay selectores de .woocommerce-product-gallery, ni listener de reset_image,
 * ni ninguna llamada AJAX. Los únicos eventos de WooCommerce que se escuchan son
 * found_variation y reset_data sobre form.cart, y solo para LEER el precio base
 * de la variación y aplicar las reglas por variación mostrando/ocultando nodos
 * propios del plugin.
 */
( function ( $ ) {
	'use strict';

	var settings = window.ioAddonsSettings || {};
	var money = settings.currencyFormat || {
		symbol: '$',
		decimals: 2,
		decimalSep: ',',
		thousandSep: '.',
		format: '%1$s%2$s'
	};
	var i18n = settings.i18n || {};

	/**
	 * Formatea un importe con el mismo criterio que WooCommerce.
	 *
	 * @param {number} amount Importe.
	 * @return {string} Importe formateado.
	 */
	function formatMoney( amount ) {
		var negative = amount < 0;
		var fixed = Math.abs( amount ).toFixed( money.decimals );
		var parts = fixed.split( '.' );

		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, money.thousandSep );

		var number = parts.length > 1 ? parts.join( money.decimalSep ) : parts[ 0 ];
		var out = String( money.format )
			.replace( '%1$s', money.symbol )
			.replace( '%2$s', number )
			.replace( /&nbsp;/g, ' ' );

		return ( negative ? '-' : '' ) + out;
	}

	/**
	 * Redondea a los decimales de la tienda (misma fórmula que el servidor).
	 *
	 * @param {number} value Valor.
	 * @return {number} Valor redondeado.
	 */
	function round( value ) {
		var factor = Math.pow( 10, money.decimals );
		return Math.round( ( value + Number.EPSILON ) * factor ) / factor;
	}

	/**
	 * Resuelve un importe según su tipo de precio.
	 *
	 * @param {number} price     Valor configurado.
	 * @param {string} priceType 'fixed' o 'percentage'.
	 * @param {number} basePrice Precio base del producto.
	 * @return {number} Importe.
	 */
	function resolveAmount( price, priceType, basePrice ) {
		price = parseFloat( price ) || 0;

		if ( 'percentage' === priceType ) {
			price = ( basePrice / 100 ) * price;
		}

		return round( price );
	}

	/**
	 * ¿El nodo aplica a la variación activa?
	 *
	 * @param {Element} el          Nodo con data-var-mode / data-var-ids.
	 * @param {number}  variationId Variación activa.
	 * @return {boolean} Si aplica.
	 */
	function appliesToVariation( el, variationId ) {
		var mode = el.getAttribute( 'data-var-mode' );
		var raw = el.getAttribute( 'data-var-ids' );

		if ( ! mode || 'all' === mode || ! raw ) {
			return true;
		}

		if ( ! variationId ) {
			return true;
		}

		var ids = raw.split( ',' ).map( function ( id ) {
			return parseInt( id, 10 );
		} );
		var inList = ids.indexOf( parseInt( variationId, 10 ) ) !== -1;

		return 'include' === mode ? inList : ! inList;
	}

	/**
	 * Inicializa un bloque de addons.
	 *
	 * @param {Element} root Contenedor .io-addons.
	 */
	function initAddons( root ) {
		var $root = $( root );
		var $form = $root.closest( 'form.cart' );

		var defaultBase = parseFloat( root.getAttribute( 'data-base-price' ) ) || 0;
		var defaultRatio = parseFloat( root.getAttribute( 'data-tax-ratio' ) ) || 1;

		var state = {
			basePrice: defaultBase,
			taxRatio: defaultRatio,
			variationId: 0
		};

		var groups = Array.prototype.slice.call( root.querySelectorAll( '.io-group' ) );
		var items = [];
		var itemsById = {};

		groups.forEach( function ( groupEl ) {
			Array.prototype.slice.call( groupEl.querySelectorAll( '.io-item-wrap' ) ).forEach( function ( wrap ) {
				var entry = {
					el: wrap,
					group: groupEl,
					input: wrap.querySelector( '.io-item__input' ),
					id: wrap.getAttribute( 'data-item-id' ),
					mandatory: '1' === wrap.getAttribute( 'data-mandatory' ),
					included: '1' === wrap.getAttribute( 'data-included' ),
					visible: true
				};

				items.push( entry );
				itemsById[ entry.id ] = entry;
			} );
		} );

		/**
		 * ¿El ítem cuenta como seleccionado?
		 *
		 * @param {Object} entry Entrada de ítem.
		 * @return {boolean} Si está seleccionado.
		 */
		function isSelected( entry ) {
			if ( entry.mandatory || entry.included ) {
				return true;
			}

			return !! ( entry.input && entry.input.checked );
		}

		/**
		 * Lee y acota la cantidad elegida para un ítem (1 si no tiene stepper).
		 * Corrige el valor mostrado si quedó fuera de rango (tipeo manual).
		 *
		 * @param {Object} entry Entrada de ítem.
		 * @return {number} Cantidad.
		 */
		function resolveQty( entry ) {
			var input = entry.el.querySelector( '.io-qty__input' );

			if ( ! input ) {
				return 1;
			}

			var min = parseInt( input.min, 10 ) || 1;
			var max = input.max ? parseInt( input.max, 10 ) : null;
			var value = parseInt( input.value, 10 );

			if ( isNaN( value ) || value < min ) {
				value = min;
			}
			if ( max && value > max ) {
				value = max;
			}

			input.value = value;

			return value;
		}

		/**
		 * Calcula visibilidad por reglas de variación + condicionales.
		 *
		 * Mismo algoritmo que IO_Addons_Pricing::resolve_visibility() en PHP.
		 */
		function computeVisibility() {
			items.forEach( function ( entry ) {
				entry.visible = appliesToVariation( entry.group, state.variationId ) &&
					appliesToVariation( entry.el, state.variationId );
			} );

			for ( var pass = 0; pass < items.length; pass++ ) {
				var changed = false;

				items.forEach( function ( entry ) {
					if ( ! entry.visible ) {
						return;
					}

					var targetId = entry.el.getAttribute( 'data-cond-target' );

					if ( ! targetId || ! itemsById[ targetId ] ) {
						return;
					}

					var target = itemsById[ targetId ];
					var targetSelected = target.visible && isSelected( target );
					var expected = 'selected' === entry.el.getAttribute( 'data-cond-is' );

					if ( targetSelected !== expected ) {
						entry.visible = false;
						changed = true;
					}
				} );

				if ( ! changed ) {
					break;
				}
			}
		}

		/**
		 * Vuelca la visibilidad al DOM.
		 *
		 * Los inputs ocultos se deshabilitan en vez de desmarcarse: así no se
		 * envían pero conservan su estado si vuelven a mostrarse.
		 */
		function applyVisibility() {
			items.forEach( function ( entry ) {
				entry.el.classList.toggle( 'is-hidden', ! entry.visible );

				var inputs = entry.el.querySelectorAll( 'input' );

				Array.prototype.forEach.call( inputs, function ( input ) {
					input.disabled = ! entry.visible;
				} );
			} );

			groups.forEach( function ( groupEl ) {
				var groupApplies = appliesToVariation( groupEl, state.variationId );
				var hasVisibleItem = false;

				items.forEach( function ( entry ) {
					if ( entry.group === groupEl && entry.visible ) {
						hasVisibleItem = true;
					}
				} );

				var isFieldsOrNotice = groupEl.classList.contains( 'io-group--fields' ) ||
					groupEl.classList.contains( 'io-group--notice' );

				var visible = groupApplies && ( hasVisibleItem || isFieldsOrNotice );

				groupEl.classList.toggle( 'is-hidden', ! visible );

				Array.prototype.forEach.call( groupEl.querySelectorAll( '.io-field__input' ), function ( input ) {
					input.disabled = ! visible;
				} );
			} );
		}

		/**
		 * Aplica el tope de opciones sin cargo de un grupo.
		 */
		function applyFreeLimits() {
			groups.forEach( function ( groupEl ) {
				if ( '1' !== groupEl.getAttribute( 'data-free' ) ) {
					return;
				}

				var max = parseInt( groupEl.getAttribute( 'data-max' ), 10 );

				if ( ! max ) {
					return;
				}

				var inputs = Array.prototype.slice.call( groupEl.querySelectorAll( '.io-item__input' ) );
				var checked = inputs.filter( function ( input ) {
					return input.checked;
				} );
				var reached = checked.length >= max;

				inputs.forEach( function ( input ) {
					var wrap = input.closest( '.io-item-wrap' );
					var hidden = wrap && wrap.classList.contains( 'is-hidden' );

					if ( hidden ) {
						return;
					}

					input.disabled = reached && ! input.checked;
					if ( wrap ) {
						wrap.classList.toggle( 'is-blocked', reached && ! input.checked );
					}
				} );
			} );
		}

		/**
		 * Recalcula precios y repinta el resumen.
		 */
		function recalc() {
			computeVisibility();
			applyVisibility();
			applyFreeLimits();

			var lines = [];
			var extra = 0;

			groups.forEach( function ( groupEl ) {
				if ( groupEl.classList.contains( 'is-hidden' ) ) {
					return;
				}

				var groupTitleEl = groupEl.querySelector( '.io-group__title' );
				var groupTitle = groupTitleEl ? groupTitleEl.textContent.trim() : '';
				var isFree = '1' === groupEl.getAttribute( 'data-free' );
				var isExclusive = '1' === groupEl.getAttribute( 'data-exclusive' );
				var counted = 0;

				items.forEach( function ( entry ) {
					if ( entry.group !== groupEl || ! entry.visible ) {
						return;
					}

					var selected = isSelected( entry );

					entry.el.classList.toggle( 'is-selected', selected );
					entry.el.classList.toggle( 'is-expanded', selected );

					var itemAmount = 0;

					if ( ! entry.included && ! isFree ) {
						itemAmount = resolveAmount(
							entry.el.getAttribute( 'data-price' ),
							entry.el.getAttribute( 'data-price-type' ),
							state.basePrice
						);
					}

					// La tarjeta muestra siempre lo que suma el ítem en sí, esté o no marcado.
					var priceEl = entry.el.querySelector( '[data-io-item-price]' );

					if ( priceEl ) {
						priceEl.textContent = itemAmount > 0 ? '+' + formatMoney( itemAmount * state.taxRatio ) : '';
					}

					if ( ! selected ) {
						return;
					}

					counted++;

					if ( isExclusive && counted > 1 ) {
						return;
					}

					var qty = resolveQty( entry );
					var amount = itemAmount;
					var axisLabels = [];

					Array.prototype.forEach.call( entry.el.querySelectorAll( '.io-axis' ), function ( axisEl ) {
						var checkedPill = axisEl.querySelector( '.io-pill__input:checked' );

						Array.prototype.forEach.call( axisEl.querySelectorAll( '.io-pill' ), function ( pill ) {
							// Clase explícita: no dependemos de :has() para el estado visual.
							pill.classList.toggle( 'is-checked', !! pill.querySelector( '.io-pill__input:checked' ) );

							var optionPriceEl = pill.querySelector( '[data-io-option-price]' );

							if ( ! optionPriceEl ) {
								return;
							}

							var optionAmount = resolveAmount(
								pill.getAttribute( 'data-price' ),
								pill.getAttribute( 'data-price-type' ),
								state.basePrice
							);

							optionPriceEl.textContent = optionAmount > 0 ? '+' + formatMoney( optionAmount * state.taxRatio ) : '';
						} );

						if ( ! checkedPill ) {
							return;
						}

						var pillEl = checkedPill.closest( '.io-pill' );
						var axisNameEl = axisEl.querySelector( '.io-axis__name' );
						var labelEl = pillEl ? pillEl.querySelector( '.io-pill__label' ) : null;

						if ( labelEl ) {
							axisLabels.push(
								( axisNameEl ? axisNameEl.textContent.trim() + ': ' : '' ) + labelEl.textContent.trim()
							);
						}

						if ( ! entry.included && ! isFree && pillEl ) {
							amount += resolveAmount(
								pillEl.getAttribute( 'data-price' ),
								pillEl.getAttribute( 'data-price-type' ),
								state.basePrice
							);
						}
					} );

					amount = round( amount * qty );
					extra += amount;

					var titleEl = entry.el.querySelector( '.io-item__title' );

					lines.push( {
						group: groupTitle,
						item: titleEl ? titleEl.textContent.trim() : '',
						value: axisLabels.join( ' · ' ),
						amount: amount,
						qty: qty,
						included: entry.included
					} );
				} );

				// Campos de personalización con precio.
				Array.prototype.forEach.call( groupEl.querySelectorAll( '.io-field' ), function ( fieldEl ) {
					var input = fieldEl.querySelector( '.io-field__input' );

					if ( ! input || input.disabled || '' === String( input.value ).trim() ) {
						return;
					}

					var price = fieldEl.getAttribute( 'data-price' );
					var priceType = fieldEl.getAttribute( 'data-price-type' );
					var label = String( input.value ).trim();

					if ( 'select' === fieldEl.getAttribute( 'data-type' ) ) {
						var option = input.options[ input.selectedIndex ];

						if ( ! option ) {
							return;
						}

						price = option.getAttribute( 'data-price' );
						priceType = option.getAttribute( 'data-price-type' );
					}

					var amount = resolveAmount( price, priceType, state.basePrice );
					var labelEl = fieldEl.querySelector( '.io-field__label' );

					extra += amount;

					lines.push( {
						group: groupTitle,
						item: labelEl ? labelEl.textContent.replace( '*', '' ).trim() : '',
						value: label,
						amount: amount,
						included: false
					} );
				} );
			} );

			renderSummary( lines, round( extra ) );
		}

		/**
		 * Repinta la caja de resumen.
		 *
		 * @param {Array}  lines Líneas.
		 * @param {number} extra Recargo total.
		 */
		function renderSummary( lines, extra ) {
			var linesEl = root.querySelector( '[data-io-summary-lines]' );
			var totalEl = root.querySelector( '[data-io-summary-total]' );

			if ( ! linesEl || ! totalEl ) {
				return;
			}

			linesEl.innerHTML = '';

			var baseRow = document.createElement( 'div' );
			baseRow.className = 'io-summary__line io-summary__line--base';
			baseRow.appendChild( labelNode( i18n.basePrice || 'Producto' ) );
			baseRow.appendChild( amountNode( formatMoney( state.basePrice * state.taxRatio ) ) );
			linesEl.appendChild( baseRow );

			lines.forEach( function ( line ) {
				var row = document.createElement( 'div' );
				row.className = 'io-summary__line';

				var text = line.item;

				if ( line.value ) {
					text += ' · ' + line.value;
				}

				if ( line.qty > 1 ) {
					text += ' ×' + line.qty;
				}

				row.appendChild( labelNode( text ) );

				if ( line.included ) {
					row.appendChild( amountNode( i18n.included || 'Incluido' ) );
				} else if ( line.amount > 0 ) {
					row.appendChild( amountNode( '+' + formatMoney( line.amount * state.taxRatio ) ) );
				} else {
					row.appendChild( amountNode( i18n.free || 'Sin cargo' ) );
				}

				linesEl.appendChild( row );
			} );

			totalEl.textContent = formatMoney( ( state.basePrice + extra ) * state.taxRatio );
		}

		/**
		 * Crea el nodo de etiqueta de una línea del resumen.
		 *
		 * @param {string} text Texto.
		 * @return {Element} Nodo.
		 */
		function labelNode( text ) {
			var el = document.createElement( 'span' );
			el.className = 'io-summary__label';
			el.textContent = text;
			return el;
		}

		/**
		 * Crea el nodo de importe de una línea del resumen.
		 *
		 * @param {string} text Texto.
		 * @return {Element} Nodo.
		 */
		function amountNode( text ) {
			var el = document.createElement( 'span' );
			el.className = 'io-summary__amount';
			el.textContent = text;
			return el;
		}

		// Los ítems obligatorios no se pueden desmarcar.
		$root.on( 'click', '.io-item-wrap.is-mandatory .io-item__input, .io-item-wrap.is-included .io-item__input', function ( event ) {
			if ( ! this.checked ) {
				event.preventDefault();
				this.checked = true;
			}
		} );

		// Stepper de cantidad: +/- acotan al min/max del input y, si el ítem
		// todavía no estaba marcado, elegir una cantidad lo selecciona.
		$root.on( 'click', '.io-qty__btn', function ( event ) {
			event.preventDefault();

			var wrap = this.closest( '.io-item-wrap' );
			var input = wrap ? wrap.querySelector( '.io-qty__input' ) : null;

			if ( ! input ) {
				return;
			}

			var min = parseInt( input.min, 10 ) || 1;
			var max = input.max ? parseInt( input.max, 10 ) : null;
			var value = parseInt( input.value, 10 ) || min;

			value += this.hasAttribute( 'data-io-qty-plus' ) ? 1 : -1;
			value = Math.max( min, value );
			if ( max ) {
				value = Math.min( max, value );
			}

			input.value = value;

			var checkbox = wrap ? wrap.querySelector( '.io-item__input' ) : null;
			if ( checkbox && ! checkbox.disabled && ! checkbox.checked ) {
				checkbox.checked = true;
			}

			recalc();
		} );

		$root.on( 'change input', 'input, select, textarea', function () {
			recalc();
		} );

		// Solo lectura del bus de variaciones de WooCommerce: leemos el precio base
		// de la variación elegida. Nada de imágenes, nada de AJAX.
		if ( $form.length ) {
			$form.on( 'found_variation', function ( event, variation ) {
				if ( ! variation ) {
					return;
				}

				state.variationId = parseInt( variation.variation_id, 10 ) || 0;

				if ( typeof variation.io_base_price !== 'undefined' ) {
					state.basePrice = parseFloat( variation.io_base_price ) || 0;
				}

				if ( typeof variation.io_tax_ratio !== 'undefined' ) {
					state.taxRatio = parseFloat( variation.io_tax_ratio ) || 1;
				}

				recalc();
			} );

			$form.on( 'reset_data', function () {
				state.variationId = 0;
				state.basePrice = defaultBase;
				state.taxRatio = defaultRatio;

				recalc();
			} );
		}

		recalc();
	}

	$( function () {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-io-addons]' ), initAddons );
	} );
} )( jQuery );

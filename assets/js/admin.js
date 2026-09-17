/**
 * Imprenta Online — Addons · editor del admin
 *
 * Editor genérico: cualquier grupo, opción, eje o campo nuevo se da de alta
 * desde acá. No hay tipos de terminado hardcodeados: el catálogo lo define
 * quien administra la tienda.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.ioAddonsAdmin || {};
	var i18n = cfg.i18n || {};
	var groupTypes = cfg.groupTypes || {};
	var fieldTypes = cfg.fieldTypes || {};

	/**
	 * Genera un id corto y único, compatible con sanitize_key() de PHP.
	 *
	 * @param {string} prefix Prefijo.
	 * @return {string} Id.
	 */
	function uid( prefix ) {
		return prefix + '_' + Math.random().toString( 36 ).slice( 2, 8 );
	}

	/**
	 * Crea un elemento.
	 *
	 * @param {string} tag      Etiqueta.
	 * @param {Object} attrs    Atributos.
	 * @param {Array}  children Hijos.
	 * @return {Element} Elemento.
	 */
	function el( tag, attrs, children ) {
		var node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( 'class' === key ) {
				node.className = attrs[ key ];
			} else if ( 'text' === key ) {
				node.textContent = attrs[ key ];
			} else if ( null !== attrs[ key ] && false !== attrs[ key ] ) {
				node.setAttribute( key, attrs[ key ] );
			}
		} );

		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );

		return node;
	}

	/**
	 * Envuelve un control con su etiqueta.
	 *
	 * @param {string}  labelText Texto.
	 * @param {Element} control   Control.
	 * @param {string}  extraCls  Clase extra.
	 * @return {Element} Campo.
	 */
	function field( labelText, control, extraCls ) {
		return el( 'label', { class: 'io-admin__field ' + ( extraCls || '' ) }, [
			el( 'span', { class: 'io-admin__field-label', text: labelText } ),
			control
		] );
	}

	/**
	 * Input de texto/número enlazado a una propiedad.
	 *
	 * @param {Object}   obj      Objeto de estado.
	 * @param {string}   key      Propiedad.
	 * @param {Object}   opts     Opciones.
	 * @param {Function} onChange Callback.
	 * @return {Element} Input.
	 */
	function textInput( obj, key, opts, onChange ) {
		opts = opts || {};

		var input = el( 'input', {
			type: opts.type || 'text',
			class: 'io-admin__input',
			placeholder: opts.placeholder || '',
			step: 'number' === opts.type ? ( opts.step || 'any' ) : false
		} );

		input.value = null === obj[ key ] || undefined === obj[ key ] ? '' : obj[ key ];

		input.addEventListener( 'input', function () {
			if ( 'number' === opts.type ) {
				obj[ key ] = '' === input.value ? ( opts.emptyAsNull ? null : 0 ) : parseFloat( input.value );
			} else {
				obj[ key ] = input.value;
			}

			if ( onChange ) {
				onChange();
			}
		} );

		return input;
	}

	/**
	 * Textarea enlazada.
	 *
	 * @param {Object} obj Objeto.
	 * @param {string} key Propiedad.
	 * @return {Element} Textarea.
	 */
	function textArea( obj, key ) {
		var input = el( 'textarea', { class: 'io-admin__input', rows: '2' } );

		input.value = obj[ key ] || '';
		input.addEventListener( 'input', function () {
			obj[ key ] = input.value;
		} );

		return input;
	}

	/**
	 * Checkbox enlazado.
	 *
	 * @param {Object}   obj       Objeto.
	 * @param {string}   key       Propiedad.
	 * @param {string}   labelText Texto.
	 * @param {Function} onChange  Callback.
	 * @return {Element} Checkbox.
	 */
	function checkbox( obj, key, labelText, onChange ) {
		var input = el( 'input', { type: 'checkbox' } );

		input.checked = !! obj[ key ];
		input.addEventListener( 'change', function () {
			obj[ key ] = input.checked;

			if ( onChange ) {
				onChange();
			}
		} );

		return el( 'label', { class: 'io-admin__check' }, [
			input,
			el( 'span', { text: labelText } )
		] );
	}

	/**
	 * Select enlazado.
	 *
	 * @param {Object}   obj      Objeto.
	 * @param {string}   key      Propiedad.
	 * @param {Array}    options  [{value,label}].
	 * @param {Function} onChange Callback.
	 * @return {Element} Select.
	 */
	function select( obj, key, options, onChange ) {
		var input = el( 'select', { class: 'io-admin__input' } );

		options.forEach( function ( option ) {
			var opt = el( 'option', { value: option.value, text: option.label } );

			if ( String( obj[ key ] ) === String( option.value ) ) {
				opt.selected = true;
			}

			input.appendChild( opt );
		} );

		input.addEventListener( 'change', function () {
			obj[ key ] = input.value;

			if ( onChange ) {
				onChange();
			}
		} );

		return input;
	}

	/**
	 * Selector de precio (importe + tipo).
	 *
	 * @param {Object} obj Objeto con price y price_type.
	 * @return {Element} Fila.
	 */
	function priceControls( obj ) {
		return el( 'div', { class: 'io-admin__row' }, [
			field( i18n.price || 'Precio', textInput( obj, 'price', { type: 'number' } ), 'io-admin__field--sm' ),
			field( i18n.priceType || 'Tipo', select( obj, 'price_type', [
				{ value: 'fixed', label: i18n.fixed || 'Importe fijo' },
				{ value: 'percentage', label: i18n.percentage || '% del producto' }
			] ), 'io-admin__field--sm' )
		] );
	}

	/**
	 * Selector de imagen con wp.media.
	 *
	 * @param {Object} obj Objeto con image_id.
	 * @return {Element} Control.
	 */
	function imagePicker( obj ) {
		var preview = el( 'span', { class: 'io-admin__thumb' } );
		var pick = el( 'button', { type: 'button', class: 'button button-small', text: i18n.selectImage || 'Elegir imagen' } );
		var clear = el( 'button', { type: 'button', class: 'button button-small button-link-delete', text: i18n.removeImage || 'Quitar' } );

		/**
		 * Refresca la miniatura.
		 */
		function refresh() {
			preview.innerHTML = '';

			if ( ! obj.image_id ) {
				clear.style.display = 'none';
				return;
			}

			clear.style.display = '';

			var img = el( 'img', { src: '', alt: '' } );

			wp.media.attachment( obj.image_id ).fetch().then( function () {
				var attachment = wp.media.attachment( obj.image_id ).toJSON();
				var sizes = attachment.sizes || {};
				img.src = ( sizes.thumbnail && sizes.thumbnail.url ) || attachment.url || '';
			} );

			preview.appendChild( img );
		}

		pick.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			var frame = wp.media( {
				title: i18n.selectImage || 'Elegir imagen',
				multiple: false,
				library: { type: 'image' }
			} );

			frame.on( 'select', function () {
				obj.image_id = frame.state().get( 'selection' ).first().toJSON().id;
				refresh();
			} );

			frame.open();
		} );

		clear.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			obj.image_id = 0;
			refresh();
		} );

		refresh();

		return el( 'div', { class: 'io-admin__image' }, [ preview, pick, clear ] );
	}

	/**
	 * Botón de borrado con confirmación.
	 *
	 * @param {string}   labelText Texto.
	 * @param {Function} onRemove  Callback.
	 * @return {Element} Botón.
	 */
	function removeButton( labelText, onRemove ) {
		var button = el( 'button', {
			type: 'button',
			class: 'button button-small button-link-delete io-admin__remove',
			text: labelText
		} );

		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			// eslint-disable-next-line no-alert
			if ( window.confirm( i18n.confirmRemove || '¿Seguro?' ) ) {
				onRemove();
			}
		} );

		return button;
	}

	/**
	 * Editor completo.
	 *
	 * @param {Element} root Contenedor .io-admin.
	 */
	function initEditor( root ) {
		var textarea = root.querySelector( '[data-io-config]' );
		var container = root.querySelector( '[data-io-groups]' );
		var addButton = root.querySelector( '[data-io-add-group]' );
		var productId = parseInt( root.getAttribute( 'data-product-id' ), 10 ) || 0;
		var isTemplate = !! cfg.isTemplate;

		var config;

		try {
			config = JSON.parse( textarea.value ) || {};
		} catch ( error ) {
			config = {};
		}

		if ( ! config.groups || ! Array.isArray( config.groups ) ) {
			config.groups = [];
		}

		var variations = [];
		var variationListeners = [];

		/**
		 * Serializa el estado al textarea oculto que viaja en el POST.
		 */
		function sync() {
			textarea.value = JSON.stringify( config );
		}

		/**
		 * Lista plana de ítems, para el selector de reglas condicionales.
		 *
		 * @param {Object} exclude Ítem a excluir.
		 * @return {Array} Opciones.
		 */
		function itemChoices( exclude ) {
			var choices = [ { value: '', label: i18n.conditionNone || 'Siempre visible' } ];

			config.groups.forEach( function ( group ) {
				( group.items || [] ).forEach( function ( item ) {
					if ( item === exclude ) {
						return;
					}

					choices.push( {
						value: item.id,
						label: ( group.title ? group.title + ' › ' : '' ) + ( item.title || item.id )
					} );
				} );
			} );

			return choices;
		}

		/**
		 * Bloque de reglas por variación.
		 *
		 * @param {Object} owner Grupo o ítem.
		 * @return {Element|null} Bloque.
		 */
		function variationRules( owner ) {
			if ( isTemplate ) {
				return el( 'p', { class: 'io-admin__hint', text: i18n.varTemplateNote || '' } );
			}

			if ( ! owner.variation_rules ) {
				owner.variation_rules = { mode: 'all', variation_ids: [] };
			}

			var rules = owner.variation_rules;
			var list = el( 'select', { class: 'io-admin__input', multiple: 'multiple', size: '5' } );

			/**
			 * Rellena el selector múltiple con las variaciones del producto.
			 */
			function fillList() {
				list.innerHTML = '';

				if ( ! variations.length ) {
					list.appendChild( el( 'option', { value: '', text: i18n.noVariations || '' } ) );
					list.disabled = true;
					return;
				}

				list.disabled = false;

				variations.forEach( function ( variation ) {
					var option = el( 'option', { value: variation.id, text: variation.label } );

					if ( ( rules.variation_ids || [] ).indexOf( variation.id ) !== -1 ) {
						option.selected = true;
					}

					list.appendChild( option );
				} );
			}

			list.addEventListener( 'change', function () {
				rules.variation_ids = Array.prototype.slice.call( list.selectedOptions ).map( function ( option ) {
					return parseInt( option.value, 10 );
				} ).filter( Boolean );
			} );

			var wrapper = el( 'div', { class: 'io-admin__variations' } );

			var modeSelect = select( rules, 'mode', [
				{ value: 'all', label: i18n.varAll || 'Todas' },
				{ value: 'include', label: i18n.varInclude || 'Solo estas' },
				{ value: 'exclude', label: i18n.varExclude || 'Todas excepto estas' }
			], function () {
				wrapper.style.display = 'all' === rules.mode ? 'none' : '';
			} );

			wrapper.appendChild( list );
			wrapper.style.display = 'all' === rules.mode ? 'none' : '';

			fillList();
			variationListeners.push( fillList );

			return el( 'div', { class: 'io-admin__block' }, [
				field( i18n.variations || 'Variaciones', modeSelect ),
				wrapper
			] );
		}

		/**
		 * Editor de un eje.
		 *
		 * @param {Object}   item Ítem dueño.
		 * @param {Object}   axis Eje.
		 * @return {Element} Bloque.
		 */
		function renderAxis( item, axis ) {
			var optionsWrap = el( 'div', { class: 'io-admin__options' } );

			/**
			 * Repinta los valores del eje.
			 */
			function renderOptions() {
				optionsWrap.innerHTML = '';

				axis.options.forEach( function ( option, index ) {
					optionsWrap.appendChild(
						el( 'div', { class: 'io-admin__option' }, [
							field( i18n.label || 'Etiqueta', textInput( option, 'label' ) ),
							priceControls( option ),
							imagePicker( option ),
							removeButton( '×', function () {
								axis.options.splice( index, 1 );
								renderOptions();
								sync();
							} )
						] )
					);
				} );

				var add = el( 'button', { type: 'button', class: 'button button-small', text: i18n.addOption || '+ Añadir valor' } );

				add.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					axis.options.push( { label: '', price: 0, price_type: 'fixed', image_id: 0 } );
					renderOptions();
					sync();
				} );

				optionsWrap.appendChild( add );
			}

			renderOptions();

			return el( 'div', { class: 'io-admin__axis' }, [
				el( 'div', { class: 'io-admin__row' }, [
					field( i18n.axisName || 'Nombre del eje', textInput( axis, 'name' ) ),
					checkbox( axis, 'required', i18n.axisRequired || 'Obligatorio' ),
					removeButton( '×', function () {
						var index = item.axes.indexOf( axis );

						if ( index !== -1 ) {
							item.axes.splice( index, 1 );
							render();
						}
					} )
				] ),
				optionsWrap
			] );
		}

		/**
		 * Editor de un ítem.
		 *
		 * @param {Object} group Grupo.
		 * @param {Object} item  Ítem.
		 * @return {Element} Bloque.
		 */
		function renderItem( group, item ) {
			if ( ! item.condition ) {
				item.condition = { addon_id: '', is: 'selected' };
			}

			if ( ! Array.isArray( item.axes ) ) {
				item.axes = [];
			}

			var axesWrap = el( 'div', { class: 'io-admin__axes' } );

			item.axes.forEach( function ( axis ) {
				if ( ! Array.isArray( axis.options ) ) {
					axis.options = [];
				}

				axesWrap.appendChild( renderAxis( item, axis ) );
			} );

			var addAxis = el( 'button', { type: 'button', class: 'button button-small', text: i18n.addAxis || '+ Añadir eje' } );

			addAxis.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				item.axes.push( { name: '', required: false, options: [] } );
				render();
			} );

			axesWrap.appendChild( addAxis );

			return el( 'div', { class: 'io-admin__item' }, [
				el( 'div', { class: 'io-admin__row io-admin__row--head' }, [
					field( i18n.title || 'Título', textInput( item, 'title' ) ),
					removeButton( i18n.removeItem || 'Quitar', function () {
						var index = group.items.indexOf( item );

						if ( index !== -1 ) {
							group.items.splice( index, 1 );
							render();
						}
					} )
				] ),
				field( i18n.description || 'Descripción', textInput( item, 'description' ) ),
				priceControls( item ),
				imagePicker( item ),
				el( 'div', { class: 'io-admin__row' }, [
					checkbox( item, 'mandatory', i18n.mandatory || 'Obligatoria' ),
					checkbox( item, 'included', i18n.included || 'Ya incluida' ),
					checkbox( item, 'default', i18n.defaultOn || 'Marcada por defecto' )
				] ),
				el( 'div', { class: 'io-admin__row' }, [
					checkbox( item, 'allow_qty', i18n.allowQty || 'Permite elegir cantidad', render ),
					item.allow_qty ? field( i18n.maxQty || 'Cantidad máxima', textInput( item, 'max_qty', { type: 'number', emptyAsNull: true } ), 'io-admin__field--sm' ) : null
				] ),
				el( 'div', { class: 'io-admin__block' }, [
					field( i18n.condition || 'Mostrar solo si…', select( item.condition, 'addon_id', itemChoices( item ) ) ),
					select( item.condition, 'is', [
						{ value: 'selected', label: i18n.isSelected || 'está seleccionada' },
						{ value: 'not_selected', label: i18n.isNotSelected || 'NO está seleccionada' }
					] )
				] ),
				variationRules( item ),
				el( 'div', { class: 'io-admin__block' }, [
					el( 'strong', { class: 'io-admin__block-title', text: i18n.axes || 'Ejes' } ),
					axesWrap
				] )
			] );
		}

		/**
		 * Editor de un campo de personalización.
		 *
		 * @param {Object} group Grupo.
		 * @param {Object} fieldData Campo.
		 * @return {Element} Bloque.
		 */
		function renderField( group, fieldData ) {
			if ( ! Array.isArray( fieldData.options ) ) {
				fieldData.options = [];
			}

			var optionsWrap = el( 'div', { class: 'io-admin__options' } );

			/**
			 * Repinta las opciones de un campo tipo lista.
			 */
			function renderOptions() {
				optionsWrap.innerHTML = '';
				optionsWrap.style.display = 'select' === fieldData.type ? '' : 'none';

				if ( 'select' !== fieldData.type ) {
					return;
				}

				fieldData.options.forEach( function ( option, index ) {
					optionsWrap.appendChild(
						el( 'div', { class: 'io-admin__option' }, [
							field( i18n.label || 'Etiqueta', textInput( option, 'label' ) ),
							priceControls( option ),
							removeButton( '×', function () {
								fieldData.options.splice( index, 1 );
								renderOptions();
								sync();
							} )
						] )
					);
				} );

				var add = el( 'button', { type: 'button', class: 'button button-small', text: i18n.addOption || '+ Añadir valor' } );

				add.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					fieldData.options.push( { label: '', price: 0, price_type: 'fixed' } );
					renderOptions();
					sync();
				} );

				optionsWrap.appendChild( add );
			}

			renderOptions();

			var typeOptions = Object.keys( fieldTypes ).map( function ( key ) {
				return { value: key, label: fieldTypes[ key ] };
			} );

			return el( 'div', { class: 'io-admin__item' }, [
				el( 'div', { class: 'io-admin__row io-admin__row--head' }, [
					field( i18n.label || 'Etiqueta', textInput( fieldData, 'label' ) ),
					field( i18n.type || 'Tipo', select( fieldData, 'type', typeOptions, renderOptions ) ),
					removeButton( i18n.removeItem || 'Quitar', function () {
						var index = group.fields.indexOf( fieldData );

						if ( index !== -1 ) {
							group.fields.splice( index, 1 );
							render();
						}
					} )
				] ),
				el( 'div', { class: 'io-admin__row' }, [
					field( i18n.placeholder || 'Texto de ayuda', textInput( fieldData, 'placeholder' ) ),
					field( i18n.maxlength || 'Máx. caracteres', textInput( fieldData, 'maxlength', { type: 'number' } ), 'io-admin__field--sm' ),
					checkbox( fieldData, 'required', i18n.required || 'Obligatorio' )
				] ),
				priceControls( fieldData ),
				optionsWrap
			] );
		}

		/**
		 * Editor de un grupo.
		 *
		 * @param {Object} group Grupo.
		 * @param {number} index Posición.
		 * @return {Element} Bloque.
		 */
		function renderGroup( group, index ) {
			var typeOptions = Object.keys( groupTypes ).map( function ( key ) {
				return { value: key, label: groupTypes[ key ] };
			} );

			var body = el( 'div', { class: 'io-admin__group-body' } );

			if ( 'group' === group.type ) {
				if ( ! Array.isArray( group.items ) ) {
					group.items = [];
				}

				body.appendChild( el( 'div', { class: 'io-admin__row' }, [
					checkbox( group, 'exclusive', i18n.exclusive || 'Una sola opción' ),
					checkbox( group, 'required', i18n.required || 'Obligatorio elegir' ),
					checkbox( group, 'free', i18n.free || 'Sin cargo', render ),
					group.free ? field( i18n.max || 'Tope', textInput( group, 'max', { type: 'number', emptyAsNull: true } ), 'io-admin__field--sm' ) : null
				] ) );

				var itemsWrap = el( 'div', { class: 'io-admin__items' } );

				group.items.forEach( function ( item ) {
					itemsWrap.appendChild( renderItem( group, item ) );
				} );

				var addItem = el( 'button', { type: 'button', class: 'button', text: i18n.addItem || '+ Añadir opción' } );

				addItem.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					group.items.push( {
						id: uid( 'i' ),
						title: '',
						description: '',
						image_id: 0,
						price: 0,
						price_type: 'fixed',
						mandatory: false,
						included: false,
						default: false,
						allow_qty: false,
						max_qty: null,
						condition: { addon_id: '', is: 'selected' },
						variation_rules: { mode: 'all', variation_ids: [] },
						axes: []
					} );
					render();
				} );

				body.appendChild( itemsWrap );
				body.appendChild( addItem );
			} else if ( 'fields' === group.type ) {
				if ( ! Array.isArray( group.fields ) ) {
					group.fields = [];
				}

				var fieldsWrap = el( 'div', { class: 'io-admin__items' } );

				group.fields.forEach( function ( fieldData ) {
					fieldsWrap.appendChild( renderField( group, fieldData ) );
				} );

				var addField = el( 'button', { type: 'button', class: 'button', text: i18n.addField || '+ Añadir campo' } );

				addField.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					group.fields.push( {
						id: uid( 'f' ),
						label: '',
						type: 'text',
						required: false,
						placeholder: '',
						maxlength: 0,
						price: 0,
						price_type: 'fixed',
						options: []
					} );
					render();
				} );

				body.appendChild( fieldsWrap );
				body.appendChild( addField );
			}

			return el( 'div', { class: 'io-admin__group' }, [
				el( 'div', { class: 'io-admin__group-head' }, [
					el( 'span', { class: 'io-admin__group-index', text: String( index + 1 ) } ),
					field( i18n.title || 'Título', textInput( group, 'title' ) ),
					field( i18n.type || 'Tipo', select( group, 'type', typeOptions, render ), 'io-admin__field--sm' ),
					removeButton( i18n.removeGroup || 'Quitar grupo', function () {
						config.groups.splice( index, 1 );
						render();
					} )
				] ),
				el( 'div', { class: 'io-admin__row' }, [
					field( i18n.subtitle || 'Subtítulo', textInput( group, 'subtitle' ) )
				] ),
				field( i18n.note || 'Nota', textArea( group, 'note' ) ),
				variationRules( group ),
				body
			] );
		}

		/**
		 * Repinta todo el editor desde el estado.
		 */
		function render() {
			variationListeners = [];
			container.innerHTML = '';

			config.groups.forEach( function ( group, index ) {
				container.appendChild( renderGroup( group, index ) );
			} );

			sync();
		}

		addButton.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			config.groups.push( {
				id: uid( 'g' ),
				title: i18n.newGroup || 'Grupo nuevo',
				subtitle: '',
				note: '',
				type: 'group',
				free: false,
				max: null,
				exclusive: false,
				required: false,
				variation_rules: { mode: 'all', variation_ids: [] },
				items: [],
				fields: []
			} );

			render();
		} );

		// Serializamos en cada cambio y también justo antes de enviar.
		$( root ).on( 'input change', 'input, select, textarea', sync );
		$( root ).closest( 'form' ).on( 'submit', sync );

		render();

		// Las variaciones se cargan una sola vez y refrescan los selectores ya pintados.
		if ( ! isTemplate && productId ) {
			$.post( cfg.ajaxUrl, {
				action: 'io_addons_get_variations',
				nonce: cfg.nonce,
				product_id: productId
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					return;
				}

				variations = response.data.variations || [];
				variationListeners.forEach( function ( refresh ) {
					refresh();
				} );
			} );
		}
	}

	/**
	 * Selector de "productos específicos" del editor de plantillas: input de
	 * búsqueda con resultados vía AJAX + chips de productos ya elegidos.
	 *
	 * @param {Element} root Contenedor [data-io-product-picker].
	 */
	function initProductPicker( root ) {
		var input = root.querySelector( '[data-io-product-search]' );
		var results = root.querySelector( '[data-io-product-results]' );
		var chips = root.querySelector( '[data-io-product-chips]' );
		var timer = null;
		var selected = {};

		Array.prototype.forEach.call( chips.querySelectorAll( '[data-id]' ), function ( chip ) {
			var id = chip.getAttribute( 'data-id' );
			selected[ id ] = true;

			var remove = chip.querySelector( '[data-io-product-remove]' );

			if ( remove ) {
				remove.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					delete selected[ id ];
					chip.parentNode.removeChild( chip );
				} );
			}
		} );

		/**
		 * Agrega un chip para un producto elegido (si no estaba ya).
		 *
		 * @param {string} id    Id de producto.
		 * @param {string} title Título.
		 */
		function addChip( id, title ) {
			if ( selected[ id ] ) {
				return;
			}

			selected[ id ] = true;

			var remove = el( 'button', { type: 'button', text: '×', 'aria-label': i18n.removeProduct || 'Quitar' } );
			var hidden = el( 'input', { type: 'hidden', name: 'io_addons_template_products[]', value: id } );
			var chip = el( 'li', { class: 'io-tpl-products__chip' }, [
				document.createTextNode( title + ' ' ),
				remove,
				hidden
			] );

			chip.setAttribute( 'data-id', id );

			remove.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				delete selected[ id ];
				chip.parentNode.removeChild( chip );
			} );

			chips.appendChild( chip );
		}

		/**
		 * Pinta la lista de resultados de búsqueda.
		 *
		 * @param {Array} products Productos.
		 */
		function renderResults( products ) {
			results.innerHTML = '';

			if ( ! products.length ) {
				results.appendChild( el( 'div', { class: 'io-tpl-products__empty', text: i18n.noProductResults || 'Sin resultados.' } ) );
				results.hidden = false;
				return;
			}

			products.forEach( function ( product ) {
				var option = el( 'button', { type: 'button', class: 'io-tpl-products__result', text: product.title } );

				option.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					addChip( String( product.id ), product.title );
					results.hidden = true;
					input.value = '';
				} );

				results.appendChild( option );
			} );

			results.hidden = false;
		}

		input.addEventListener( 'input', function () {
			var term = input.value.trim();

			if ( timer ) {
				clearTimeout( timer );
			}

			if ( term.length < 2 ) {
				results.hidden = true;
				return;
			}

			timer = setTimeout( function () {
				$.post( cfg.ajaxUrl, {
					action: 'io_addons_search_products',
					nonce: cfg.nonce,
					term: term
				} ).done( function ( response ) {
					if ( response && response.success ) {
						renderResults( response.data.products || [] );
					}
				} );
			}, 300 );
		} );

		$( document ).on( 'click', function ( event ) {
			if ( ! results.contains( event.target ) && event.target !== input ) {
				results.hidden = true;
			}
		} );
	}

	$( function () {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-io-admin]' ), initEditor );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-io-product-picker]' ), initProductPicker );
	} );
} )( jQuery );

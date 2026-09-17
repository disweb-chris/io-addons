<?php
/**
 * Renderizado en la ficha de producto.
 *
 * REGLA DE ORO: este plugin no escucha ni toca la galería de imágenes del
 * producto. Nada de .woocommerce-product-gallery, wc_product_gallery(),
 * woocommerce_show_product_images() ni del evento reset_image. El único contacto
 * con el ciclo de vida de variaciones es de solo lectura (found_variation /
 * reset_data sobre form.cart) para actualizar el precio base mostrado y aplicar
 * las reglas por variación. Sin AJAX, sin re-render de imágenes.
 *
 * @package IO_Addons
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_Addons_Frontend
 */
class IO_Addons_Frontend {

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render' ), 9 );
		add_filter( 'woocommerce_available_variation', array( $this, 'inject_variation_price' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Precio base limpio de un producto (sin impuestos aplicados a la vista).
	 *
	 * @param WC_Product $product Producto.
	 * @return float
	 */
	public static function get_base_price( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return 0.0;
		}

		if ( $product->is_type( 'variable' ) ) {
			$price = $product->get_variation_price( 'min', false );
		} else {
			$price = $product->get_price( 'edit' );
		}

		return '' === $price || null === $price ? 0.0 : (float) $price;
	}

	/**
	 * Relación entre el precio mostrado (con o sin IVA, según la tienda) y el crudo.
	 *
	 * Sirve para que el JS pinte los recargos con el mismo criterio fiscal que
	 * WooCommerce usa en el resto de la ficha, sin duplicar la lógica de impuestos.
	 *
	 * @param WC_Product $product Producto.
	 * @return float
	 */
	public static function get_tax_ratio( $product ) {
		if ( ! $product instanceof WC_Product || ! function_exists( 'wc_get_price_to_display' ) ) {
			return 1.0;
		}

		$raw = self::get_base_price( $product );

		if ( $raw <= 0 ) {
			return 1.0;
		}

		$display = (float) wc_get_price_to_display( $product, array( 'price' => $raw ) );

		if ( $display <= 0 ) {
			return 1.0;
		}

		return round( $display / $raw, 6 );
	}

	/**
	 * Agrega el precio base de cada variación al payload que WooCommerce ya manda al JS.
	 *
	 * Aditivo: no pisa ningún campo de core.
	 *
	 * @param array                $data      Datos de la variación.
	 * @param WC_Product_Variable  $product   Producto padre.
	 * @param WC_Product_Variation $variation Variación.
	 * @return array
	 */
	public function inject_variation_price( $data, $product, $variation ) {
		if ( ! $variation instanceof WC_Product ) {
			return $data;
		}

		$raw = (float) $variation->get_price( 'edit' );

		$data['io_base_price'] = $raw;
		$data['io_tax_ratio']  = self::get_tax_ratio( $variation );

		return $data;
	}

	/**
	 * ¿Hay que cargar los assets en esta página?
	 *
	 * @return bool
	 */
	protected function should_load() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return false;
		}

		$product = wc_get_product( get_the_ID() );

		if ( ! $product ) {
			return false;
		}

		return io_addons_config_has_content( io_addons_get_product_config( $product->get_id() ) );
	}

	/**
	 * Encola CSS y JS.
	 */
	public function enqueue_assets() {
		if ( ! $this->should_load() ) {
			return;
		}

		wp_enqueue_style( 'io-addons', IO_ADDONS_URL . 'assets/css/frontend.css', array(), IO_ADDONS_VERSION );
		wp_enqueue_script( 'io-addons', IO_ADDONS_URL . 'assets/js/frontend.js', array( 'jquery' ), IO_ADDONS_VERSION, true );

		wp_localize_script(
			'io-addons',
			'ioAddonsSettings',
			array(
				'currencyFormat' => array(
					'symbol'    => get_woocommerce_currency_symbol(),
					'decimals'  => io_addons_price_decimals(),
					'decimalSep' => wc_get_price_decimal_separator(),
					'thousandSep' => wc_get_price_thousand_separator(),
					'format'    => get_woocommerce_price_format(),
				),
				'i18n'           => array(
					'summaryTitle' => __( 'Resumen', 'io-addons' ),
					'basePrice'    => __( 'Producto', 'io-addons' ),
					'total'        => __( 'Total', 'io-addons' ),
					'included'     => __( 'Incluido', 'io-addons' ),
					'free'         => __( 'Sin cargo', 'io-addons' ),
				),
			)
		);
	}

	/**
	 * Imprime el bloque de addons dentro de form.cart.
	 *
	 * Se engancha en woocommerce_before_add_to_cart_button (prio 9), es decir
	 * dentro del formulario y en la columna de resumen. Nunca en la galería.
	 */
	public function render() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$config = io_addons_get_product_config( $product->get_id() );

		if ( ! io_addons_config_has_content( $config ) ) {
			return;
		}

		$base_price = self::get_base_price( $product );
		$tax_ratio  = self::get_tax_ratio( $product );

		echo '<div class="io-addons" data-io-addons data-base-price="' . esc_attr( $base_price ) . '" data-tax-ratio="' . esc_attr( $tax_ratio ) . '">';

		foreach ( $config['groups'] as $group ) {
			$this->render_group( $group );
		}

		$this->render_summary();

		echo '</div>';
	}

	/**
	 * Atributos data-* de reglas por variación.
	 *
	 * El servidor imprime la regla; el JS la evalúa en vivo al cambiar de variación.
	 *
	 * @param array $rules Reglas.
	 * @return string
	 */
	protected function variation_attrs( $rules ) {
		if ( empty( $rules['variation_ids'] ) || 'all' === $rules['mode'] ) {
			return '';
		}

		return ' data-var-mode="' . esc_attr( $rules['mode'] ) . '" data-var-ids="' . esc_attr( implode( ',', array_map( 'absint', $rules['variation_ids'] ) ) ) . '"';
	}

	/**
	 * Renderiza un grupo.
	 *
	 * @param array $group Grupo sanitizado.
	 */
	protected function render_group( $group ) {
		$classes = array( 'io-group', 'io-group--' . $group['type'] );

		if ( $group['free'] ) {
			$classes[] = 'is-free';
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"'
			. ' data-group-id="' . esc_attr( $group['id'] ) . '"'
			. ' data-exclusive="' . ( $group['exclusive'] ? '1' : '0' ) . '"'
			. ' data-free="' . ( $group['free'] ? '1' : '0' ) . '"'
			. ' data-max="' . esc_attr( null === $group['max'] ? '' : $group['max'] ) . '"'
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapado en variation_attrs().
			. $this->variation_attrs( $group['variation_rules'] )
			. '>';

		if ( '' !== $group['title'] || '' !== $group['subtitle'] ) {
			echo '<div class="io-group__head">';
			if ( '' !== $group['title'] ) {
				echo '<span class="io-group__title">' . esc_html( $group['title'] ) . '</span>';
			}
			if ( '' !== $group['subtitle'] ) {
				echo '<span class="io-group__subtitle">' . esc_html( $group['subtitle'] ) . '</span>';
			}
			if ( $group['free'] ) {
				echo '<span class="io-group__badge">' . esc_html__( 'Sin cargo', 'io-addons' ) . '</span>';
			}
			echo '</div>';
		}

		if ( '' !== $group['note'] ) {
			echo '<div class="io-group__note">' . wp_kses_post( wpautop( $group['note'] ) ) . '</div>';
		}

		if ( 'notice' === $group['type'] ) {
			echo '</div>';
			return;
		}

		if ( 'fields' === $group['type'] ) {
			echo '<div class="io-fields">';
			foreach ( $group['fields'] as $field ) {
				$this->render_field( $group, $field );
			}
			echo '</div>';
			echo '</div>';
			return;
		}

		echo '<div class="io-items">';
		foreach ( $group['items'] as $item ) {
			$this->render_item( $group, $item );
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Renderiza un ítem con su tarjeta y sus ejes.
	 *
	 * Los ejes van FUERA del <label> a propósito: si estuvieran dentro, tocar una
	 * opción de eje alternaría la selección del ítem.
	 *
	 * @param array $group Grupo.
	 * @param array $item  Ítem.
	 */
	protected function render_item( $group, $item ) {
		$input_type = $group['exclusive'] ? 'radio' : 'checkbox';
		$input_name = 'io_addons[items][' . $group['id'] . ']' . ( $group['exclusive'] ? '' : '[]' );
		$input_id   = 'io-item-' . $group['id'] . '-' . $item['id'];
		$checked    = $item['mandatory'] || $item['included'] || $item['default'];

		$classes = array( 'io-item-wrap' );
		if ( $item['mandatory'] ) {
			$classes[] = 'is-mandatory';
		}
		if ( $item['included'] ) {
			$classes[] = 'is-included';
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"'
			. ' data-item-id="' . esc_attr( $item['id'] ) . '"'
			. ' data-price="' . esc_attr( $item['price'] ) . '"'
			. ' data-price-type="' . esc_attr( $item['price_type'] ) . '"'
			. ' data-mandatory="' . ( $item['mandatory'] ? '1' : '0' ) . '"'
			. ' data-included="' . ( $item['included'] ? '1' : '0' ) . '"'
			. ' data-cond-target="' . esc_attr( $item['condition']['addon_id'] ) . '"'
			. ' data-cond-is="' . esc_attr( $item['condition']['is'] ) . '"'
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapado en variation_attrs().
			. $this->variation_attrs( $item['variation_rules'] )
			. '>';

		echo '<label class="io-item" for="' . esc_attr( $input_id ) . '">';

		printf(
			'<input type="%1$s" class="io-item__input" id="%2$s" name="%3$s" value="%4$s"%5$s />',
			esc_attr( $input_type ),
			esc_attr( $input_id ),
			esc_attr( $input_name ),
			esc_attr( $item['id'] ),
			$checked ? ' checked="checked"' : ''
		);

		echo '<span class="io-item__body">';

		if ( $item['image_id'] ) {
			$image = wp_get_attachment_image( $item['image_id'], array( 100, 100 ), false, array( 'class' => 'io-item__thumb' ) );
			if ( $image ) {
				echo wp_kses_post( $image );
			}
		}

		echo '<span class="io-item__text">';
		echo '<span class="io-item__title">' . esc_html( $item['title'] ) . '</span>';
		if ( '' !== $item['description'] ) {
			echo '<span class="io-item__desc">' . esc_html( $item['description'] ) . '</span>';
		}
		echo '</span>';

		// Los importes dinámicos los repinta el JS; los textos fijos no llevan el hook.
		if ( $item['included'] ) {
			echo '<span class="io-item__price is-static">' . esc_html__( 'Incluido', 'io-addons' ) . '</span>';
		} elseif ( $group['free'] || 0.0 === (float) $item['price'] ) {
			echo '<span class="io-item__price is-static">' . esc_html__( 'Sin cargo', 'io-addons' ) . '</span>';
		} else {
			echo '<span class="io-item__price" data-io-item-price></span>';
		}

		echo '</span>'; // .io-item__body
		echo '</label>';

		if ( ! empty( $item['axes'] ) ) {
			echo '<div class="io-axes">';
			foreach ( $item['axes'] as $axis_index => $axis ) {
				if ( empty( $axis['options'] ) ) {
					continue;
				}

				echo '<div class="io-axis" data-axis-index="' . esc_attr( $axis_index ) . '" data-required="' . ( $axis['required'] ? '1' : '0' ) . '">';

				if ( '' !== $axis['name'] ) {
					echo '<span class="io-axis__name">' . esc_html( $axis['name'] ) . '</span>';
				}

				echo '<div class="io-axis__options">';
				// Solo pre-seleccionamos el primer valor si no cobra: nunca hay que
				// cargarle un recargo a alguien que no eligió nada.
				$default_index = null;
				foreach ( $axis['options'] as $option_index => $option ) {
					if ( 0.0 === (float) $option['price'] ) {
						$default_index = $option_index;
						break;
					}
				}

				foreach ( $axis['options'] as $option_index => $option ) {
					$opt_id = 'io-axis-' . $item['id'] . '-' . $axis_index . '-' . $option_index;

					echo '<label class="io-pill" for="' . esc_attr( $opt_id ) . '" data-price="' . esc_attr( $option['price'] ) . '" data-price-type="' . esc_attr( $option['price_type'] ) . '">';
					printf(
						'<input type="radio" class="io-pill__input" id="%1$s" name="%2$s" value="%3$s"%4$s />',
						esc_attr( $opt_id ),
						esc_attr( 'io_addons[axes][' . $item['id'] . '][' . $axis_index . ']' ),
						esc_attr( $option_index ),
						$option_index === $default_index ? ' checked="checked"' : ''
					);

					if ( ! empty( $option['image_id'] ) ) {
						$swatch = wp_get_attachment_image( $option['image_id'], array( 48, 48 ), false, array( 'class' => 'io-pill__thumb' ) );
						if ( $swatch ) {
							echo wp_kses_post( $swatch );
						}
					}

					echo '<span class="io-pill__label">' . esc_html( $option['label'] ) . '</span>';

					if ( (float) $option['price'] > 0 ) {
						echo '<span class="io-pill__price" data-io-option-price></span>';
					}

					echo '</label>';
				}
				echo '</div>';
				echo '</div>';
			}
			echo '</div>';
		}

		echo '</div>'; // .io-item-wrap
	}

	/**
	 * Renderiza un campo de personalización.
	 *
	 * @param array $group Grupo.
	 * @param array $field Campo.
	 */
	protected function render_field( $group, $field ) {
		$name     = 'io_addons[fields][' . $group['id'] . '][' . $field['id'] . ']';
		$field_id = 'io-field-' . $group['id'] . '-' . $field['id'];

		echo '<div class="io-field" data-field-id="' . esc_attr( $field['id'] ) . '" data-price="' . esc_attr( $field['price'] ) . '" data-price-type="' . esc_attr( $field['price_type'] ) . '" data-type="' . esc_attr( $field['type'] ) . '">';

		echo '<label class="io-field__label" for="' . esc_attr( $field_id ) . '">';
		echo esc_html( $field['label'] );
		if ( $field['required'] ) {
			echo ' <abbr class="required" title="' . esc_attr__( 'Obligatorio', 'io-addons' ) . '">*</abbr>';
		}
		echo '</label>';

		$common = ' id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $name ) . '" class="io-field__input"';
		if ( $field['required'] ) {
			$common .= ' required="required"';
		}
		if ( '' !== $field['placeholder'] ) {
			$common .= ' placeholder="' . esc_attr( $field['placeholder'] ) . '"';
		}
		if ( $field['maxlength'] > 0 && in_array( $field['type'], array( 'text', 'textarea' ), true ) ) {
			$common .= ' maxlength="' . esc_attr( $field['maxlength'] ) . '"';
		}

		switch ( $field['type'] ) {
			case 'textarea':
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $common ya viene escapado campo a campo.
				echo '<textarea rows="3"' . $common . '></textarea>';
				break;

			case 'number':
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- idem.
				echo '<input type="number" step="any"' . $common . ' />';
				break;

			case 'select':
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- idem.
				echo '<select' . $common . '>';
				echo '<option value="">' . esc_html__( 'Elegir…', 'io-addons' ) . '</option>';
				foreach ( $field['options'] as $option ) {
					echo '<option value="' . esc_attr( $option['label'] ) . '" data-price="' . esc_attr( $option['price'] ) . '" data-price-type="' . esc_attr( $option['price_type'] ) . '">' . esc_html( $option['label'] ) . '</option>';
				}
				echo '</select>';
				break;

			default:
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- idem.
				echo '<input type="text"' . $common . ' />';
				break;
		}

		echo '</div>';
	}

	/**
	 * Caja de resumen. El JS la rellena.
	 */
	protected function render_summary() {
		echo '<div class="io-summary" data-io-summary>';
		echo '<div class="io-summary__head">' . esc_html__( 'Resumen', 'io-addons' ) . '</div>';
		echo '<div class="io-summary__lines" data-io-summary-lines></div>';
		echo '<div class="io-summary__total"><span>' . esc_html__( 'Total', 'io-addons' ) . '</span><span data-io-summary-total></span></div>';
		echo '</div>';
	}
}

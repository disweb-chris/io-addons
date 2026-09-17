<?php
/**
 * Motor de cálculo. Única fuente de verdad del precio.
 *
 * El JS del frontend replica esta fórmula solo para mostrar el total en vivo;
 * el precio que se cobra siempre sale de acá.
 *
 * @package IO_Addons
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_Addons_Pricing
 */
class IO_Addons_Pricing {

	/**
	 * ¿Un grupo/ítem aplica a la variación dada?
	 *
	 * Con $variation_id = 0 (producto simple, o variable sin variación elegida
	 * todavía) siempre devuelve true: el filtrado fino lo hace el JS en vivo.
	 *
	 * @param array $rules        Reglas de variación ya sanitizadas.
	 * @param int   $variation_id ID de variación.
	 * @return bool
	 */
	public static function applies_to_variation( $rules, $variation_id ) {
		$mode = isset( $rules['mode'] ) ? $rules['mode'] : 'all';

		if ( 'all' === $mode || empty( $rules['variation_ids'] ) ) {
			return true;
		}

		$variation_id = absint( $variation_id );
		if ( ! $variation_id ) {
			return true;
		}

		$in_list = in_array( $variation_id, array_map( 'absint', $rules['variation_ids'] ), true );

		return 'include' === $mode ? $in_list : ! $in_list;
	}

	/**
	 * Normaliza la selección cruda que llega del formulario.
	 *
	 * Formato esperado:
	 *   io_addons[items][<group_id>][]            = <item_id>
	 *   io_addons[axes][<item_id>][<axis_index>]  = <option_index>
	 *   io_addons[fields][<group_id>][<field_id>] = <valor>
	 *
	 * @param mixed $raw Datos crudos ($_POST['io_addons'] o equivalente).
	 * @return array
	 */
	public static function sanitize_selection( $raw ) {
		$selection = array(
			'items'  => array(),
			'axes'   => array(),
			'fields' => array(),
		);

		if ( ! is_array( $raw ) ) {
			return $selection;
		}

		if ( isset( $raw['items'] ) && is_array( $raw['items'] ) ) {
			foreach ( $raw['items'] as $group_id => $item_ids ) {
				$group_id = sanitize_key( $group_id );
				if ( '' === $group_id ) {
					continue;
				}
				$item_ids = is_array( $item_ids ) ? $item_ids : array( $item_ids );
				$clean    = array();
				foreach ( $item_ids as $item_id ) {
					$item_id = sanitize_key( $item_id );
					if ( '' !== $item_id ) {
						$clean[ $item_id ] = $item_id;
					}
				}
				if ( $clean ) {
					$selection['items'][ $group_id ] = array_values( $clean );
				}
			}
		}

		if ( isset( $raw['axes'] ) && is_array( $raw['axes'] ) ) {
			foreach ( $raw['axes'] as $item_id => $axes ) {
				$item_id = sanitize_key( $item_id );
				if ( '' === $item_id || ! is_array( $axes ) ) {
					continue;
				}
				$clean = array();
				foreach ( $axes as $axis_index => $option_index ) {
					if ( '' === $option_index || null === $option_index ) {
						continue;
					}
					$clean[ absint( $axis_index ) ] = absint( $option_index );
				}
				if ( $clean ) {
					$selection['axes'][ $item_id ] = $clean;
				}
			}
		}

		if ( isset( $raw['fields'] ) && is_array( $raw['fields'] ) ) {
			foreach ( $raw['fields'] as $group_id => $fields ) {
				$group_id = sanitize_key( $group_id );
				if ( '' === $group_id || ! is_array( $fields ) ) {
					continue;
				}
				$clean = array();
				foreach ( $fields as $field_id => $value ) {
					$field_id = sanitize_key( $field_id );
					if ( '' === $field_id ) {
						continue;
					}
					$clean[ $field_id ] = sanitize_textarea_field( wp_unslash( (string) $value ) );
				}
				if ( $clean ) {
					$selection['fields'][ $group_id ] = $clean;
				}
			}
		}

		return $selection;
	}

	/**
	 * Evalúa qué ítems quedan visibles según las reglas condicionales.
	 *
	 * Se resuelve iterativamente porque una condición puede depender de un ítem
	 * que a su vez tiene condición.
	 *
	 * @param array $config       Config sanitizada.
	 * @param array $selected_map Mapa item_id => true de lo que el cliente marcó.
	 * @param int   $variation_id Variación activa.
	 * @return array Mapa item_id => bool (visible).
	 */
	public static function resolve_visibility( $config, $selected_map, $variation_id ) {
		$items      = array();
		$visibility = array();

		foreach ( $config['groups'] as $group ) {
			$group_ok = self::applies_to_variation( $group['variation_rules'], $variation_id );
			foreach ( $group['items'] as $item ) {
				$items[ $item['id'] ]      = $item;
				$visibility[ $item['id'] ] = $group_ok && self::applies_to_variation( $item['variation_rules'], $variation_id );
			}
		}

		$max_passes = max( 1, count( $items ) );

		for ( $pass = 0; $pass < $max_passes; $pass++ ) {
			$changed = false;

			foreach ( $items as $item_id => $item ) {
				if ( ! $visibility[ $item_id ] ) {
					continue;
				}

				$target = $item['condition']['addon_id'];
				if ( '' === $target || ! isset( $visibility[ $target ] ) ) {
					continue;
				}

				// Un ítem oculto no puede satisfacer una condición de "seleccionado".
				$target_selected = ! empty( $selected_map[ $target ] ) && $visibility[ $target ];
				$expected        = 'selected' === $item['condition']['is'];

				if ( $target_selected !== $expected ) {
					$visibility[ $item_id ] = false;
					$changed                = true;
				}
			}

			if ( ! $changed ) {
				break;
			}
		}

		return $visibility;
	}

	/**
	 * Calcula el recargo total y las líneas a mostrar.
	 *
	 * @param array $config       Config sanitizada.
	 * @param array $selection    Selección ya sanitizada.
	 * @param float $base_price   Precio base limpio del producto/variación.
	 * @param int   $variation_id Variación activa.
	 * @return array {
	 *     @type float $extra  Recargo total.
	 *     @type array $lines  Líneas para carrito/pedido.
	 *     @type array $errors Errores de validación.
	 * }
	 */
	public static function calculate( $config, $selection, $base_price, $variation_id = 0 ) {
		$result = array(
			'extra'  => 0.0,
			'lines'  => array(),
			'errors' => array(),
		);

		if ( ! io_addons_config_has_content( $config ) ) {
			return $result;
		}

		$base_price = (float) $base_price;
		$decimals   = io_addons_price_decimals();

		// Mapa de lo que el cliente marcó + los obligatorios/incluidos, que van siempre.
		$selected_map = array();
		foreach ( $config['groups'] as $group ) {
			$chosen = isset( $selection['items'][ $group['id'] ] ) ? (array) $selection['items'][ $group['id'] ] : array();
			foreach ( $group['items'] as $item ) {
				if ( $item['mandatory'] || $item['included'] || in_array( $item['id'], $chosen, true ) ) {
					$selected_map[ $item['id'] ] = true;
				}
			}
		}

		$visibility = self::resolve_visibility( $config, $selected_map, $variation_id );
		$extra      = 0.0;

		foreach ( $config['groups'] as $group ) {
			if ( ! self::applies_to_variation( $group['variation_rules'], $variation_id ) ) {
				continue;
			}

			if ( 'fields' === $group['type'] ) {
				$values = isset( $selection['fields'][ $group['id'] ] ) ? (array) $selection['fields'][ $group['id'] ] : array();

				foreach ( $group['fields'] as $field ) {
					$value = isset( $values[ $field['id'] ] ) ? trim( (string) $values[ $field['id'] ] ) : '';

					if ( '' === $value ) {
						if ( $field['required'] ) {
							/* translators: %s: nombre del campo. */
							$result['errors'][] = sprintf( __( 'Completá el campo «%s».', 'io-addons' ), $field['label'] );
						}
						continue;
					}

					if ( $field['maxlength'] > 0 ) {
						$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $field['maxlength'] ) : substr( $value, 0, $field['maxlength'] );
					}

					$price      = $field['price'];
					$price_type = $field['price_type'];
					$label      = $value;

					if ( 'select' === $field['type'] ) {
						$option = null;
						foreach ( $field['options'] as $candidate ) {
							if ( $candidate['label'] === $value ) {
								$option = $candidate;
								break;
							}
						}
						if ( null === $option ) {
							/* translators: %s: nombre del campo. */
							$result['errors'][] = sprintf( __( 'La opción elegida en «%s» ya no está disponible.', 'io-addons' ), $field['label'] );
							continue;
						}
						$price      = $option['price'];
						$price_type = $option['price_type'];
						$label      = $option['label'];
					}

					$amount = self::resolve_amount( $price, $price_type, $base_price, $decimals );
					$extra += $amount;

					$result['lines'][] = array(
						'group'  => $group['title'],
						'item'   => $field['label'],
						'value'  => $label,
						'amount' => $amount,
						'kind'   => 'field',
					);
				}

				continue;
			}

			if ( 'group' !== $group['type'] ) {
				continue;
			}

			$chosen        = isset( $selection['items'][ $group['id'] ] ) ? (array) $selection['items'][ $group['id'] ] : array();
			$group_count   = 0;
			$free_used     = 0;
			$group_has_any = false;

			foreach ( $group['items'] as $item ) {
				if ( empty( $visibility[ $item['id'] ] ) ) {
					continue;
				}

				$group_has_any = true;
				$is_selected   = $item['mandatory'] || $item['included'] || in_array( $item['id'], $chosen, true );

				if ( ! $is_selected ) {
					continue;
				}

				++$group_count;

				// Grupo exclusivo: solo el primero marcado cuenta.
				if ( $group['exclusive'] && $group_count > 1 ) {
					continue;
				}

				// Grupo de opciones gratis: sin cargo hasta el tope configurado.
				$is_free = $group['free'];
				if ( $is_free && null !== $group['max'] ) {
					++$free_used;
					if ( $free_used > $group['max'] ) {
						/* translators: 1: nombre del grupo, 2: cantidad máxima. */
						$result['errors'][] = sprintf( __( 'En «%1$s» podés elegir hasta %2$d opción/es sin cargo.', 'io-addons' ), $group['title'], $group['max'] );
						continue;
					}
				}

				$amount = 0.0;
				if ( ! $item['included'] && ! $is_free ) {
					$amount = self::resolve_amount( $item['price'], $item['price_type'], $base_price, $decimals );
				}

				$axis_labels = array();
				$axes_chosen = isset( $selection['axes'][ $item['id'] ] ) ? (array) $selection['axes'][ $item['id'] ] : array();

				foreach ( $item['axes'] as $axis_index => $axis ) {
					if ( empty( $axis['options'] ) ) {
						continue;
					}

					$has_choice = isset( $axes_chosen[ $axis_index ] ) && isset( $axis['options'][ $axes_chosen[ $axis_index ] ] );

					if ( ! $has_choice ) {
						if ( $axis['required'] ) {
							/* translators: 1: nombre del eje, 2: nombre del ítem. */
							$result['errors'][] = sprintf( __( 'Elegí una opción de «%1$s» para «%2$s».', 'io-addons' ), $axis['name'], $item['title'] );
						}
						continue;
					}

					$option        = $axis['options'][ $axes_chosen[ $axis_index ] ];
					$axis_labels[] = ( '' !== $axis['name'] ? $axis['name'] . ': ' : '' ) . $option['label'];

					if ( ! $item['included'] && ! $is_free ) {
						$amount += self::resolve_amount( $option['price'], $option['price_type'], $base_price, $decimals );
					}
				}

				$extra += $amount;

				$result['lines'][] = array(
					'group'  => $group['title'],
					'item'   => $item['title'],
					'value'  => implode( ' · ', $axis_labels ),
					'amount' => $amount,
					'kind'   => $item['included'] ? 'included' : 'item',
				);
			}

			if ( $group['required'] && $group_has_any && 0 === $group_count ) {
				/* translators: %s: nombre del grupo. */
				$result['errors'][] = sprintf( __( 'Elegí una opción en «%s».', 'io-addons' ), $group['title'] );
			}
		}

		$result['extra'] = round( $extra, $decimals );

		return $result;
	}

	/**
	 * Resuelve un importe según su tipo de precio.
	 *
	 * @param float  $price      Valor configurado.
	 * @param string $price_type 'fixed' | 'percentage'.
	 * @param float  $base_price Precio base del producto.
	 * @param int    $decimals   Decimales de precio.
	 * @return float
	 */
	public static function resolve_amount( $price, $price_type, $base_price, $decimals ) {
		$price = (float) $price;

		if ( 'percentage' === $price_type ) {
			$price = ( (float) $base_price / 100 ) * $price;
		}

		return round( $price, $decimals );
	}

	/**
	 * Aplica el precio final al producto del cart item.
	 *
	 * set_price() por sí solo no alcanza: si el producto tiene _sale_price activo
	 * WooCommerce sigue cobrando el precio de oferta. Hay que sincronizar los tres.
	 *
	 * @param WC_Product $product Producto (clon del cart item).
	 * @param float      $final   Precio final.
	 */
	public static function apply_price_final( $product, $final ) {
		$final = (float) $final;

		if ( $final < 0 ) {
			$final = 0.0;
		}

		$product->set_price( $final );
		$product->set_regular_price( $final );
		$product->set_sale_price( $final );
	}
}

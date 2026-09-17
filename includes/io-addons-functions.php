<?php
/**
 * Helpers de configuración: sanitización y resolución por producto.
 *
 * Único punto de entrada de validación: io_addons_sanitize_config(). Todo lo que
 * escribe en el meta IO_ADDONS_META pasa por acá, venga del admin del producto o
 * del admin de la plantilla.
 *
 * @package IO_Addons
 */

defined( 'ABSPATH' ) || exit;

/**
 * Estructura vacía de config.
 *
 * @return array
 */
function io_addons_empty_config() {
	return array( 'groups' => array() );
}

/**
 * Tipos de grupo soportados.
 *
 * @return array
 */
function io_addons_group_types() {
	return array(
		'group'  => __( 'Opciones con recargo', 'io-addons' ),
		'fields' => __( 'Campos de personalización', 'io-addons' ),
		'notice' => __( 'Aviso / texto informativo', 'io-addons' ),
	);
}

/**
 * Tipos de campo soportados dentro de un grupo de tipo "fields".
 *
 * @return array
 */
function io_addons_field_types() {
	return array(
		'text'     => __( 'Texto corto', 'io-addons' ),
		'textarea' => __( 'Texto largo', 'io-addons' ),
		'number'   => __( 'Número', 'io-addons' ),
		'select'   => __( 'Lista desplegable', 'io-addons' ),
	);
}

/**
 * Normaliza un precio que puede venir con coma decimal o con separadores.
 *
 * @param mixed $value Valor crudo.
 * @return float
 */
function io_addons_sanitize_price( $value ) {
	if ( is_float( $value ) || is_int( $value ) ) {
		return round( (float) $value, 4 );
	}

	$value = (string) $value;
	$value = trim( str_replace( array( ' ', "\xc2\xa0" ), '', $value ) );

	if ( '' === $value ) {
		return 0.0;
	}

	// Si tiene coma y punto, el último separador que aparece es el decimal.
	$last_comma = strrpos( $value, ',' );
	$last_dot   = strrpos( $value, '.' );

	if ( false !== $last_comma && false !== $last_dot ) {
		if ( $last_comma > $last_dot ) {
			$value = str_replace( '.', '', $value );
			$value = str_replace( ',', '.', $value );
		} else {
			$value = str_replace( ',', '', $value );
		}
	} elseif ( false !== $last_comma ) {
		$value = str_replace( ',', '.', $value );
	}

	return round( (float) $value, 4 );
}

/**
 * Genera un id único y estable dentro de la config.
 *
 * Los ids se usan como referencia en las reglas condicionales, así que hay que
 * preservar el que ya venga guardado siempre que se pueda.
 *
 * @param mixed  $raw      Id propuesto.
 * @param string $prefix   Prefijo para el fallback.
 * @param array  $used     Ids ya usados (por referencia).
 * @return string
 */
function io_addons_unique_id( $raw, $prefix, array &$used ) {
	$id = sanitize_key( (string) $raw );

	if ( '' === $id ) {
		$id = $prefix . '_' . wp_generate_password( 6, false, false );
		$id = strtolower( $id );
	}

	$base = $id;
	$i    = 2;
	while ( isset( $used[ $id ] ) ) {
		$id = $base . '_' . $i;
		++$i;
	}

	$used[ $id ] = true;

	return $id;
}

/**
 * Sanitiza las reglas de aplicación por variación.
 *
 * @param mixed $raw Reglas crudas.
 * @return array
 */
function io_addons_sanitize_variation_rules( $raw ) {
	$rules = array(
		'mode'          => 'all',
		'variation_ids' => array(),
	);

	if ( ! is_array( $raw ) ) {
		return $rules;
	}

	$mode = isset( $raw['mode'] ) ? sanitize_key( $raw['mode'] ) : 'all';
	if ( ! in_array( $mode, array( 'all', 'include', 'exclude' ), true ) ) {
		$mode = 'all';
	}

	$ids = array();
	if ( isset( $raw['variation_ids'] ) && is_array( $raw['variation_ids'] ) ) {
		foreach ( $raw['variation_ids'] as $id ) {
			$id = absint( $id );
			if ( $id ) {
				$ids[ $id ] = $id;
			}
		}
	}
	$ids = array_values( $ids );

	// Sin IDs no hay nada que filtrar: degradar a 'all' evita reglas fantasma.
	if ( empty( $ids ) ) {
		$mode = 'all';
	}

	$rules['mode']          = $mode;
	$rules['variation_ids'] = $ids;

	return $rules;
}

/**
 * Sanitiza una regla condicional entre addons.
 *
 * @param mixed $raw Regla cruda.
 * @return array
 */
function io_addons_sanitize_condition( $raw ) {
	$condition = array(
		'addon_id' => '',
		'is'       => 'selected',
	);

	if ( ! is_array( $raw ) ) {
		return $condition;
	}

	$addon_id = isset( $raw['addon_id'] ) ? sanitize_key( $raw['addon_id'] ) : '';
	$is       = isset( $raw['is'] ) ? sanitize_key( $raw['is'] ) : 'selected';

	if ( ! in_array( $is, array( 'selected', 'not_selected' ), true ) ) {
		$is = 'selected';
	}

	$condition['addon_id'] = $addon_id;
	$condition['is']       = $is;

	return $condition;
}

/**
 * Sanitiza los ejes (dimensiones internas) de un ítem.
 *
 * @param mixed $raw Ejes crudos.
 * @return array
 */
function io_addons_sanitize_axes( $raw ) {
	$axes = array();

	if ( ! is_array( $raw ) ) {
		return $axes;
	}

	foreach ( $raw as $axis ) {
		if ( ! is_array( $axis ) ) {
			continue;
		}

		$name    = isset( $axis['name'] ) ? sanitize_text_field( (string) $axis['name'] ) : '';
		$options = array();

		if ( isset( $axis['options'] ) && is_array( $axis['options'] ) ) {
			foreach ( $axis['options'] as $option ) {
				if ( ! is_array( $option ) ) {
					continue;
				}

				$label = isset( $option['label'] ) ? sanitize_text_field( (string) $option['label'] ) : '';
				if ( '' === $label ) {
					continue;
				}

				$price_type = isset( $option['price_type'] ) ? sanitize_key( $option['price_type'] ) : 'fixed';
				if ( ! in_array( $price_type, array( 'fixed', 'percentage' ), true ) ) {
					$price_type = 'fixed';
				}

				$options[] = array(
					'label'      => $label,
					'price'      => io_addons_sanitize_price( isset( $option['price'] ) ? $option['price'] : 0 ),
					'price_type' => $price_type,
					'image_id'   => isset( $option['image_id'] ) ? absint( $option['image_id'] ) : 0,
				);
			}
		}

		if ( '' === $name && empty( $options ) ) {
			continue;
		}

		$axes[] = array(
			'name'     => $name,
			'required' => ! empty( $axis['required'] ),
			'options'  => $options,
		);
	}

	return $axes;
}

/**
 * Sanitiza los campos de un grupo de tipo "fields".
 *
 * @param mixed $raw  Campos crudos.
 * @param array $used Ids ya usados (por referencia).
 * @return array
 */
function io_addons_sanitize_fields( $raw, array &$used ) {
	$fields = array();

	if ( ! is_array( $raw ) ) {
		return $fields;
	}

	$allowed_types = array_keys( io_addons_field_types() );

	foreach ( $raw as $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}

		$label = isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '';
		if ( '' === $label ) {
			continue;
		}

		$type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
		if ( ! in_array( $type, $allowed_types, true ) ) {
			$type = 'text';
		}

		$price_type = isset( $field['price_type'] ) ? sanitize_key( $field['price_type'] ) : 'fixed';
		if ( ! in_array( $price_type, array( 'fixed', 'percentage' ), true ) ) {
			$price_type = 'fixed';
		}

		$options = array();
		if ( 'select' === $type && isset( $field['options'] ) && is_array( $field['options'] ) ) {
			foreach ( $field['options'] as $option ) {
				if ( ! is_array( $option ) ) {
					continue;
				}
				$opt_label = isset( $option['label'] ) ? sanitize_text_field( (string) $option['label'] ) : '';
				if ( '' === $opt_label ) {
					continue;
				}
				$opt_price_type = isset( $option['price_type'] ) ? sanitize_key( $option['price_type'] ) : 'fixed';
				if ( ! in_array( $opt_price_type, array( 'fixed', 'percentage' ), true ) ) {
					$opt_price_type = 'fixed';
				}
				$options[] = array(
					'label'      => $opt_label,
					'price'      => io_addons_sanitize_price( isset( $option['price'] ) ? $option['price'] : 0 ),
					'price_type' => $opt_price_type,
				);
			}
		}

		$fields[] = array(
			'id'          => io_addons_unique_id( isset( $field['id'] ) ? $field['id'] : '', 'f', $used ),
			'label'       => $label,
			'type'        => $type,
			'required'    => ! empty( $field['required'] ),
			'placeholder' => isset( $field['placeholder'] ) ? sanitize_text_field( (string) $field['placeholder'] ) : '',
			'maxlength'   => isset( $field['maxlength'] ) ? absint( $field['maxlength'] ) : 0,
			'price'       => io_addons_sanitize_price( isset( $field['price'] ) ? $field['price'] : 0 ),
			'price_type'  => $price_type,
			'options'     => $options,
		);
	}

	return $fields;
}

/**
 * Sanitiza la configuración completa de addons.
 *
 * Único punto de entrada de validación. Devuelve siempre una estructura válida.
 *
 * @param mixed $config Config cruda (array o JSON string).
 * @return array
 */
function io_addons_sanitize_config( $config ) {
	if ( is_string( $config ) ) {
		$decoded = json_decode( wp_unslash( $config ), true );
		$config  = is_array( $decoded ) ? $decoded : array();
	}

	$clean = io_addons_empty_config();

	if ( ! is_array( $config ) || empty( $config['groups'] ) || ! is_array( $config['groups'] ) ) {
		return $clean;
	}

	$used_ids     = array();
	$group_types  = array_keys( io_addons_group_types() );

	foreach ( $config['groups'] as $group ) {
		if ( ! is_array( $group ) ) {
			continue;
		}

		$type = isset( $group['type'] ) ? sanitize_key( $group['type'] ) : 'group';
		if ( ! in_array( $type, $group_types, true ) ) {
			$type = 'group';
		}

		$clean_group = array(
			'id'              => io_addons_unique_id( isset( $group['id'] ) ? $group['id'] : '', 'g', $used_ids ),
			'title'           => isset( $group['title'] ) ? sanitize_text_field( (string) $group['title'] ) : '',
			'subtitle'        => isset( $group['subtitle'] ) ? sanitize_text_field( (string) $group['subtitle'] ) : '',
			'note'            => isset( $group['note'] ) ? wp_kses_post( (string) $group['note'] ) : '',
			'type'            => $type,
			'free'            => ! empty( $group['free'] ),
			'max'             => isset( $group['max'] ) && '' !== $group['max'] && null !== $group['max'] ? absint( $group['max'] ) : null,
			'exclusive'       => ! empty( $group['exclusive'] ),
			'required'        => ! empty( $group['required'] ),
			'variation_rules' => io_addons_sanitize_variation_rules( isset( $group['variation_rules'] ) ? $group['variation_rules'] : null ),
			'items'           => array(),
			'fields'          => array(),
		);

		if ( 'fields' === $type ) {
			$clean_group['fields'] = io_addons_sanitize_fields( isset( $group['fields'] ) ? $group['fields'] : array(), $used_ids );
		} elseif ( 'group' === $type && isset( $group['items'] ) && is_array( $group['items'] ) ) {
			foreach ( $group['items'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$title = isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '';
				if ( '' === $title ) {
					continue;
				}

				$price_type = isset( $item['price_type'] ) ? sanitize_key( $item['price_type'] ) : 'fixed';
				if ( ! in_array( $price_type, array( 'fixed', 'percentage' ), true ) ) {
					$price_type = 'fixed';
				}

				$clean_group['items'][] = array(
					'id'              => io_addons_unique_id( isset( $item['id'] ) ? $item['id'] : '', 'i', $used_ids ),
					'title'           => $title,
					'description'     => isset( $item['description'] ) ? sanitize_text_field( (string) $item['description'] ) : '',
					'image_id'        => isset( $item['image_id'] ) ? absint( $item['image_id'] ) : 0,
					'price'           => io_addons_sanitize_price( isset( $item['price'] ) ? $item['price'] : 0 ),
					'price_type'      => $price_type,
					'mandatory'       => ! empty( $item['mandatory'] ),
					'included'        => ! empty( $item['included'] ),
					'default'         => ! empty( $item['default'] ),
					'condition'       => io_addons_sanitize_condition( isset( $item['condition'] ) ? $item['condition'] : null ),
					'variation_rules' => io_addons_sanitize_variation_rules( isset( $item['variation_rules'] ) ? $item['variation_rules'] : null ),
					'axes'            => io_addons_sanitize_axes( isset( $item['axes'] ) ? $item['axes'] : array() ),
				);
			}
		}

		// Un grupo sin contenido no aporta nada (salvo los avisos, que son solo texto).
		if ( 'notice' === $type ) {
			if ( '' === $clean_group['note'] && '' === $clean_group['title'] ) {
				continue;
			}
		} elseif ( empty( $clean_group['items'] ) && empty( $clean_group['fields'] ) ) {
			continue;
		}

		$clean['groups'][] = $clean_group;
	}

	// Segunda pasada: descartar condiciones que apunten a ids inexistentes.
	$item_ids = array();
	foreach ( $clean['groups'] as $group ) {
		foreach ( $group['items'] as $item ) {
			$item_ids[ $item['id'] ] = true;
		}
	}

	foreach ( $clean['groups'] as $g_index => $group ) {
		foreach ( $group['items'] as $i_index => $item ) {
			$target = $item['condition']['addon_id'];
			if ( '' !== $target && ( ! isset( $item_ids[ $target ] ) || $target === $item['id'] ) ) {
				$clean['groups'][ $g_index ]['items'][ $i_index ]['condition'] = array(
					'addon_id' => '',
					'is'       => 'selected',
				);
			}
		}
	}

	/**
	 * Permite ajustar la config ya sanitizada antes de guardarla.
	 *
	 * @param array $clean Config sanitizada.
	 */
	return apply_filters( 'io_addons_sanitize_config', $clean );
}

/**
 * ¿La config tiene contenido real?
 *
 * @param mixed $config Config.
 * @return bool
 */
function io_addons_config_has_content( $config ) {
	return is_array( $config ) && ! empty( $config['groups'] ) && is_array( $config['groups'] );
}

/**
 * Lee la config cruda guardada en un post (producto o plantilla).
 *
 * @param int $post_id Post ID.
 * @return array
 */
function io_addons_get_raw_config( $post_id ) {
	$config = get_post_meta( $post_id, IO_ADDONS_META, true );

	if ( is_string( $config ) && '' !== $config ) {
		$decoded = json_decode( $config, true );
		$config  = is_array( $decoded ) ? $decoded : array();
	}

	return is_array( $config ) ? $config : io_addons_empty_config();
}

/**
 * Resuelve qué config de addons le corresponde a un producto.
 *
 * Cascada:
 *   1. Config propia del producto (si tiene contenido real).
 *   2. Plantilla auto-aplicada por categoría.
 *   3. Plantilla genérica global (salvo opt-out del producto).
 *   4. Vacío.
 *
 * @param int $product_id Product ID (padre, no variación).
 * @return array
 */
function io_addons_get_product_config( $product_id ) {
	static $cache = array();

	$product_id = absint( $product_id );
	if ( ! $product_id ) {
		return io_addons_empty_config();
	}

	if ( isset( $cache[ $product_id ] ) ) {
		return $cache[ $product_id ];
	}

	// Si nos pasan una variación, subimos al padre: la config vive en el padre.
	$parent_id = wp_get_post_parent_id( $product_id );
	if ( $parent_id && 'product_variation' === get_post_type( $product_id ) ) {
		$product_id = $parent_id;
		if ( isset( $cache[ $product_id ] ) ) {
			return $cache[ $product_id ];
		}
	}

	$config = io_addons_get_raw_config( $product_id );

	if ( ! io_addons_config_has_content( $config ) ) {
		$config = IO_Addons_Templates::get_config_for_product( $product_id );
	}

	$config = io_addons_sanitize_config( $config );

	/**
	 * Config final resuelta para un producto.
	 *
	 * @param array $config     Config.
	 * @param int   $product_id Product ID.
	 */
	$config = apply_filters( 'io_addons_product_config', $config, $product_id );

	$cache[ $product_id ] = $config;

	return $config;
}

/**
 * Cantidad de decimales de precio configurada en WooCommerce.
 *
 * @return int
 */
function io_addons_price_decimals() {
	return function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
}

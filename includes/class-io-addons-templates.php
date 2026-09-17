<?php
/**
 * Plantillas reutilizables de addons.
 *
 * Un CPT con exactamente la misma estructura de config que un producto. Permite
 * "todos los productos de la categoría Cuadernos llevan anillado y laminado",
 * con opt-out por producto para la plantilla genérica.
 *
 * @package IO_Addons
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_Addons_Templates
 */
class IO_Addons_Templates {

	const META_CATEGORIES = '_io_addons_template_categories';
	const META_GENERIC    = '_io_addons_template_generic';
	const META_PRIORITY   = '_io_addons_template_priority';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	/**
	 * Registra el CPT de plantillas.
	 */
	public static function register_post_type() {
		register_post_type(
			IO_ADDONS_TEMPLATE_CPT,
			array(
				'labels'              => array(
					'name'               => __( 'Plantillas de addons', 'io-addons' ),
					'singular_name'      => __( 'Plantilla de addons', 'io-addons' ),
					'add_new'            => __( 'Añadir plantilla', 'io-addons' ),
					'add_new_item'       => __( 'Añadir plantilla de addons', 'io-addons' ),
					'edit_item'          => __( 'Editar plantilla de addons', 'io-addons' ),
					'new_item'           => __( 'Nueva plantilla', 'io-addons' ),
					'view_item'          => __( 'Ver plantilla', 'io-addons' ),
					'search_items'       => __( 'Buscar plantillas', 'io-addons' ),
					'not_found'          => __( 'No hay plantillas todavía.', 'io-addons' ),
					'not_found_in_trash' => __( 'No hay plantillas en la papelera.', 'io-addons' ),
					'menu_name'          => __( 'Addons', 'io-addons' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=product',
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'hierarchical'        => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'rewrite'             => false,
				'query_var'           => false,
			)
		);
	}

	/**
	 * Devuelve la config de plantilla que le corresponde a un producto.
	 *
	 * Primero busca una plantilla asociada a alguna de sus categorías; si no hay,
	 * cae en la plantilla genérica (salvo que el producto tenga opt-out).
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function get_config_for_product( $product_id ) {
		$templates = self::get_templates();

		if ( empty( $templates ) ) {
			return io_addons_empty_config();
		}

		$term_ids = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		$term_ids = is_wp_error( $term_ids ) ? array() : array_map( 'absint', $term_ids );

		// Incluir categorías ancestro: una plantilla en la categoría padre alcanza a las hijas.
		$expanded = $term_ids;
		foreach ( $term_ids as $term_id ) {
			$ancestors = get_ancestors( $term_id, 'product_cat', 'taxonomy' );
			if ( $ancestors ) {
				$expanded = array_merge( $expanded, array_map( 'absint', $ancestors ) );
			}
		}
		$expanded = array_unique( $expanded );

		foreach ( $templates as $template ) {
			if ( empty( $template['categories'] ) ) {
				continue;
			}
			if ( array_intersect( $expanded, $template['categories'] ) ) {
				return $template['config'];
			}
		}

		if ( get_post_meta( $product_id, IO_ADDONS_META_NO_GENERIC, true ) ) {
			return io_addons_empty_config();
		}

		foreach ( $templates as $template ) {
			if ( $template['generic'] ) {
				return $template['config'];
			}
		}

		return io_addons_empty_config();
	}

	/**
	 * Lista de plantillas publicadas, ordenadas por prioridad.
	 *
	 * @return array
	 */
	public static function get_templates() {
		static $templates = null;

		if ( null !== $templates ) {
			return $templates;
		}

		$posts = get_posts(
			array(
				'post_type'        => IO_ADDONS_TEMPLATE_CPT,
				'post_status'      => 'publish',
				'numberposts'      => 100,
				'orderby'          => 'menu_order title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		$templates = array();

		foreach ( $posts as $post ) {
			$categories = get_post_meta( $post->ID, self::META_CATEGORIES, true );
			$categories = is_array( $categories ) ? array_map( 'absint', $categories ) : array();

			$templates[] = array(
				'id'         => $post->ID,
				'title'      => $post->post_title,
				'categories' => $categories,
				'generic'    => (bool) get_post_meta( $post->ID, self::META_GENERIC, true ),
				'priority'   => (int) get_post_meta( $post->ID, self::META_PRIORITY, true ),
				'config'     => io_addons_get_raw_config( $post->ID ),
			);
		}

		usort(
			$templates,
			function ( $a, $b ) {
				if ( $a['priority'] === $b['priority'] ) {
					return $a['id'] <=> $b['id'];
				}
				return $b['priority'] <=> $a['priority'];
			}
		);

		return $templates;
	}
}

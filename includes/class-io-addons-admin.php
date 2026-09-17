<?php
/**
 * Administración.
 *
 * El editor es genérico a propósito: no hay tipos de terminado hardcodeados en
 * PHP. Cualquier grupo, ítem, eje o campo nuevo se da de alta desde esta pantalla
 * sin tocar código.
 *
 * @package IO_Addons
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_Addons_Admin
 */
class IO_Addons_Admin {

	const NONCE = 'io_addons_save_config';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_product', array( $this, 'save_product' ), 10, 2 );
		add_action( 'save_post_' . IO_ADDONS_TEMPLATE_CPT, array( $this, 'save_template' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_io_addons_get_variations', array( $this, 'ajax_get_variations' ) );
		add_action( 'wp_ajax_io_addons_search_products', array( $this, 'ajax_search_products' ) );
	}

	/**
	 * Pantallas donde vive el editor.
	 *
	 * @return array
	 */
	protected function screens() {
		return array( 'product', IO_ADDONS_TEMPLATE_CPT );
	}

	/**
	 * Registra los meta boxes.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'io-addons-config',
			__( 'Opciones de acabado (addons)', 'io-addons' ),
			array( $this, 'render_config_box' ),
			$this->screens(),
			'normal',
			'high'
		);

		add_meta_box(
			'io-addons-template-settings',
			__( 'Aplicación de la plantilla', 'io-addons' ),
			array( $this, 'render_template_box' ),
			IO_ADDONS_TEMPLATE_CPT,
			'side',
			'default'
		);

		add_meta_box(
			'io-addons-product-settings',
			__( 'Addons: plantillas', 'io-addons' ),
			array( $this, 'render_product_settings_box' ),
			'product',
			'side',
			'low'
		);
	}

	/**
	 * Editor de configuración (lo construye el JS a partir del JSON).
	 *
	 * @param WP_Post $post Post actual.
	 */
	public function render_config_box( $post ) {
		wp_nonce_field( self::NONCE, 'io_addons_nonce' );

		$config = io_addons_sanitize_config( io_addons_get_raw_config( $post->ID ) );

		echo '<div class="io-admin" data-io-admin data-post-type="' . esc_attr( $post->post_type ) . '" data-product-id="' . esc_attr( $post->ID ) . '">';
		echo '<p class="io-admin__intro">' . esc_html__( 'Creá acá los grupos de acabado (anillado, encuadernado, laminado, plastificado, o cualquier terminado nuevo). No hace falta programar nada para agregar un tipo nuevo.', 'io-addons' ) . '</p>';
		echo '<div class="io-admin__groups" data-io-groups></div>';
		echo '<p class="io-admin__actions">';
		echo '<button type="button" class="button button-primary" data-io-add-group>' . esc_html__( '+ Añadir grupo', 'io-addons' ) . '</button>';
		echo '</p>';
		echo '<textarea name="io_addons_config" data-io-config hidden>' . esc_textarea( wp_json_encode( $config ) ) . '</textarea>';
		echo '</div>';
	}

	/**
	 * Ajustes de aplicación de una plantilla.
	 *
	 * @param WP_Post $post Post actual.
	 */
	public function render_template_box( $post ) {
		$selected      = get_post_meta( $post->ID, IO_Addons_Templates::META_CATEGORIES, true );
		$selected      = is_array( $selected ) ? array_map( 'absint', $selected ) : array();
		$generic       = (bool) get_post_meta( $post->ID, IO_Addons_Templates::META_GENERIC, true );
		$priority      = (int) get_post_meta( $post->ID, IO_Addons_Templates::META_PRIORITY, true );
		$product_ids   = get_post_meta( $post->ID, IO_Addons_Templates::META_PRODUCTS, true );
		$product_ids   = is_array( $product_ids ) ? array_map( 'absint', $product_ids ) : array();

		echo '<p><label><strong>' . esc_html__( 'Productos específicos', 'io-addons' ) . '</strong></label></p>';
		echo '<div class="io-tpl-products" data-io-product-picker>';
		echo '<input type="text" class="io-admin__input" data-io-product-search autocomplete="off" placeholder="' . esc_attr__( 'Buscar producto por nombre…', 'io-addons' ) . '" />';
		echo '<div class="io-tpl-products__results" data-io-product-results hidden></div>';
		echo '<ul class="io-tpl-products__chips" data-io-product-chips>';
		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			printf(
				'<li class="io-tpl-products__chip" data-id="%1$d">%2$s <button type="button" data-io-product-remove aria-label="%3$s">×</button><input type="hidden" name="io_addons_template_products[]" value="%1$d" /></li>',
				absint( $product_id ),
				esc_html( $product->get_name() ),
				esc_attr__( 'Quitar', 'io-addons' )
			);
		}
		echo '</ul>';
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Se aplica a estos productos puntuales, tengan o no marcada alguna de las categorías de abajo. Tiene prioridad sobre la asociación por categoría y sobre la genérica.', 'io-addons' ) . '</p>';

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		echo '<p><label for="io-addons-categories"><strong>' . esc_html__( 'Categorías a las que se aplica', 'io-addons' ) . '</strong></label></p>';
		echo '<select id="io-addons-categories" name="io_addons_template_categories[]" multiple size="8" style="width:100%">';

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				printf(
					'<option value="%1$d"%2$s>%3$s</option>',
					absint( $term->term_id ),
					in_array( (int) $term->term_id, $selected, true ) ? ' selected="selected"' : '',
					esc_html( $term->name )
				);
			}
		}

		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Los productos de estas categorías (y sus subcategorías) usan esta plantilla si no tienen configuración propia.', 'io-addons' ) . '</p>';

		echo '<p><label><input type="checkbox" name="io_addons_template_generic" value="1"' . checked( $generic, true, false ) . ' /> ';
		echo esc_html__( 'Usar como plantilla genérica', 'io-addons' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Se aplica a cualquier producto sin configuración propia ni plantilla por categoría, salvo que el producto tenga el opt-out marcado.', 'io-addons' ) . '</p>';

		echo '<p><label for="io-addons-priority"><strong>' . esc_html__( 'Prioridad', 'io-addons' ) . '</strong></label><br />';
		echo '<input type="number" id="io-addons-priority" name="io_addons_template_priority" value="' . esc_attr( $priority ) . '" style="width:100%" /></p>';
		echo '<p class="description">' . esc_html__( 'A mayor número, antes se evalúa la plantilla.', 'io-addons' ) . '</p>';
	}

	/**
	 * Opt-out de plantilla genérica a nivel de producto.
	 *
	 * @param WP_Post $post Post actual.
	 */
	public function render_product_settings_box( $post ) {
		$disabled = (bool) get_post_meta( $post->ID, IO_ADDONS_META_NO_GENERIC, true );

		echo '<p><label><input type="checkbox" name="io_addons_disable_generic" value="1"' . checked( $disabled, true, false ) . ' /> ';
		echo esc_html__( 'No aplicar la plantilla genérica a este producto', 'io-addons' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'La configuración propia del producto y las plantillas por categoría siguen teniendo prioridad sobre la genérica.', 'io-addons' ) . '</p>';
	}

	/**
	 * ¿Podemos guardar en este post?
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return bool
	 */
	protected function can_save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return false;
		}

		if ( ! isset( $_POST['io_addons_nonce'] ) ) {
			return false;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['io_addons_nonce'] ) ), self::NONCE ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Guarda la config (común a productos y plantillas).
	 *
	 * @param int $post_id Post ID.
	 */
	protected function save_config( $post_id ) {
		if ( ! isset( $_POST['io_addons_config'] ) ) {
			return;
		}

		// No usamos sanitize_text_field acá porque el valor es un JSON completo:
		// la validación real la hace io_addons_sanitize_config(), campo por campo.
		$raw    = wp_unslash( $_POST['io_addons_config'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$config = io_addons_sanitize_config( is_string( $raw ) ? json_decode( $raw, true ) : $raw );

		if ( io_addons_config_has_content( $config ) ) {
			update_post_meta( $post_id, IO_ADDONS_META, $config );
		} else {
			delete_post_meta( $post_id, IO_ADDONS_META );
		}
	}

	/**
	 * Guardado en producto.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public function save_product( $post_id, $post ) {
		if ( ! $this->can_save( $post_id, $post ) ) {
			return;
		}

		$this->save_config( $post_id );

		if ( isset( $_POST['io_addons_disable_generic'] ) ) {
			update_post_meta( $post_id, IO_ADDONS_META_NO_GENERIC, 1 );
		} else {
			delete_post_meta( $post_id, IO_ADDONS_META_NO_GENERIC );
		}
	}

	/**
	 * Guardado en plantilla.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public function save_template( $post_id, $post ) {
		if ( ! $this->can_save( $post_id, $post ) ) {
			return;
		}

		$this->save_config( $post_id );

		$products = array();
		if ( isset( $_POST['io_addons_template_products'] ) && is_array( $_POST['io_addons_template_products'] ) ) {
			$products = array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['io_addons_template_products'] ) ) ) ) );
		}
		update_post_meta( $post_id, IO_Addons_Templates::META_PRODUCTS, $products );

		$categories = array();
		if ( isset( $_POST['io_addons_template_categories'] ) && is_array( $_POST['io_addons_template_categories'] ) ) {
			$categories = array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['io_addons_template_categories'] ) ) ) );
		}
		update_post_meta( $post_id, IO_Addons_Templates::META_CATEGORIES, $categories );

		if ( isset( $_POST['io_addons_template_generic'] ) ) {
			update_post_meta( $post_id, IO_Addons_Templates::META_GENERIC, 1 );
		} else {
			delete_post_meta( $post_id, IO_Addons_Templates::META_GENERIC );
		}

		$priority = isset( $_POST['io_addons_template_priority'] ) ? (int) $_POST['io_addons_template_priority'] : 0;
		update_post_meta( $post_id, IO_Addons_Templates::META_PRIORITY, $priority );
	}

	/**
	 * Encola los assets del editor.
	 *
	 * @param string $hook Hook de la pantalla.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, $this->screens(), true ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'io-addons-admin', IO_ADDONS_URL . 'assets/css/admin.css', array(), IO_ADDONS_VERSION );
		wp_enqueue_script( 'io-addons-admin', IO_ADDONS_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable' ), IO_ADDONS_VERSION, true );

		wp_localize_script(
			'io-addons-admin',
			'ioAddonsAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'io_addons_admin' ),
				'isTemplate'  => IO_ADDONS_TEMPLATE_CPT === $screen->post_type,
				'groupTypes'  => io_addons_group_types(),
				'fieldTypes'  => io_addons_field_types(),
				'i18n'        => array(
					'group'          => __( 'Grupo', 'io-addons' ),
					'newGroup'       => __( 'Grupo nuevo', 'io-addons' ),
					'title'          => __( 'Título', 'io-addons' ),
					'subtitle'       => __( 'Subtítulo', 'io-addons' ),
					'note'           => __( 'Nota', 'io-addons' ),
					'type'           => __( 'Tipo', 'io-addons' ),
					'exclusive'      => __( 'Una sola opción (radio)', 'io-addons' ),
					'required'       => __( 'Obligatorio elegir una opción', 'io-addons' ),
					'free'           => __( 'Opciones sin cargo', 'io-addons' ),
					'max'            => __( 'Tope de opciones sin cargo', 'io-addons' ),
					'items'          => __( 'Opciones', 'io-addons' ),
					'addItem'        => __( '+ Añadir opción', 'io-addons' ),
					'removeItem'     => __( 'Quitar opción', 'io-addons' ),
					'removeGroup'    => __( 'Quitar grupo', 'io-addons' ),
					'price'          => __( 'Precio', 'io-addons' ),
					'priceType'      => __( 'Tipo de precio', 'io-addons' ),
					'fixed'          => __( 'Importe fijo', 'io-addons' ),
					'percentage'     => __( '% del precio del producto', 'io-addons' ),
					'description'    => __( 'Descripción', 'io-addons' ),
					'image'          => __( 'Imagen', 'io-addons' ),
					'selectImage'    => __( 'Elegir imagen', 'io-addons' ),
					'removeImage'    => __( 'Quitar', 'io-addons' ),
					'mandatory'      => __( 'Obligatoria (no se puede desmarcar)', 'io-addons' ),
					'included'       => __( 'Ya incluida en el precio', 'io-addons' ),
					'defaultOn'      => __( 'Marcada por defecto', 'io-addons' ),
					'axes'           => __( 'Ejes (color, grosor, acabado…)', 'io-addons' ),
					'addAxis'        => __( '+ Añadir eje', 'io-addons' ),
					'axisName'       => __( 'Nombre del eje', 'io-addons' ),
					'axisRequired'   => __( 'Obligatorio', 'io-addons' ),
					'addOption'      => __( '+ Añadir valor', 'io-addons' ),
					'label'          => __( 'Etiqueta', 'io-addons' ),
					'condition'      => __( 'Mostrar solo si…', 'io-addons' ),
					'conditionNone'  => __( 'Siempre visible', 'io-addons' ),
					'isSelected'     => __( 'está seleccionada', 'io-addons' ),
					'isNotSelected'  => __( 'NO está seleccionada', 'io-addons' ),
					'variations'     => __( 'Aplicar a variaciones', 'io-addons' ),
					'varAll'         => __( 'Todas las variaciones', 'io-addons' ),
					'varInclude'     => __( 'Solo estas variaciones', 'io-addons' ),
					'varExclude'     => __( 'Todas excepto estas', 'io-addons' ),
					'noVariations'   => __( 'Este producto no tiene variaciones. Guardá las variaciones primero.', 'io-addons' ),
					'varTemplateNote' => __( 'Las reglas por variación se configuran en cada producto, no en la plantilla.', 'io-addons' ),
					'fields'         => __( 'Campos', 'io-addons' ),
					'addField'       => __( '+ Añadir campo', 'io-addons' ),
					'placeholder'    => __( 'Texto de ayuda', 'io-addons' ),
					'maxlength'      => __( 'Máx. caracteres', 'io-addons' ),
					'confirmRemove'  => __( '¿Seguro que querés quitarlo?', 'io-addons' ),
					'loading'        => __( 'Cargando…', 'io-addons' ),
					'noProductResults' => __( 'No se encontraron productos.', 'io-addons' ),
					'searchMinChars' => __( 'Escribí al menos 2 letras…', 'io-addons' ),
					'removeProduct'  => __( 'Quitar', 'io-addons' ),
				),
			)
		);
	}

	/**
	 * Devuelve las variaciones de un producto para el selector del editor.
	 */
	public function ajax_get_variations() {
		check_ajax_referer( 'io_addons_admin', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $product_id || ! current_user_can( 'edit_post', $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'io-addons' ) ), 403 );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			wp_send_json_success( array( 'variations' => array() ) );
		}

		$variations = array();

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation ) {
				continue;
			}

			$attributes = array();
			foreach ( $variation->get_variation_attributes() as $value ) {
				if ( '' !== $value ) {
					$attributes[] = $value;
				}
			}

			$variations[] = array(
				'id'    => $variation_id,
				'label' => $attributes ? implode( ' / ', $attributes ) : '#' . $variation_id,
			);
		}

		wp_send_json_success( array( 'variations' => $variations ) );
	}

	/**
	 * Busca productos por nombre para el selector de "productos específicos" de una plantilla.
	 */
	public function ajax_search_products() {
		check_ajax_referer( 'io_addons_admin', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'io-addons' ) ), 403 );
		}

		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

		if ( mb_strlen( $term ) < 2 ) {
			wp_send_json_success( array( 'products' => array() ) );
		}

		// Palabras a exigir en el título, sin acentos y en minúsculas: el 's' de
		// WP_Query puede devolver de más si hay un plugin de búsqueda/relevancia
		// activo (SEO, indexador, etc.) que reordena o amplía por relevancia en
		// vez de hacer un LIKE literal. Filtramos en PHP como garantía.
		$needles = array_filter( explode( ' ', remove_accents( mb_strtolower( $term ) ) ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				's'              => $term,
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$candidates = $query->posts;

		// Si la búsqueda por frase completa no encontró nada (título real distinto
		// al orden exacto de palabras, ej. "Impresión a Todo Color"), reintentamos
		// con la primera palabra sola para tener candidatos de sobra y filtrar acá.
		if ( empty( $candidates ) && count( $needles ) > 1 ) {
			$broader = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					's'              => reset( $needles ),
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);
			$candidates = $broader->posts;
		}

		$products = array();

		foreach ( $candidates as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$haystack = remove_accents( mb_strtolower( $product->get_name() ) );
			$matches  = true;

			foreach ( $needles as $needle ) {
				if ( false === mb_strpos( $haystack, $needle ) ) {
					$matches = false;
					break;
				}
			}

			if ( ! $matches ) {
				continue;
			}

			$products[] = array(
				'id'    => $product_id,
				'title' => $product->get_name(),
			);

			if ( count( $products ) >= 20 ) {
				break;
			}
		}

		wp_send_json_success( array( 'products' => $products ) );
	}
}

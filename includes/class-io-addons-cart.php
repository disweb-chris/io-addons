<?php
/**
 * Carrito y pedido.
 *
 * El precio final se aplica en los tres momentos del ciclo de vida del carrito
 * (alta, restauración desde sesión y recálculo de totales) sincronizando price,
 * regular_price y sale_price: set_price() por sí solo no alcanza si el producto
 * tiene una oferta activa.
 *
 * Toda la persistencia de pedido usa la API CRUD de WooCommerce, nunca $wpdb.
 *
 * @package IO_Addons
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_Addons_Cart
 */
class IO_Addons_Cart {

	const CART_KEY  = 'io_addons';
	const ORDER_KEY = '_io_addons_data';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 10, 4 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item', array( $this, 'add_cart_item' ), 20, 2 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'get_cart_item_from_session' ), 20, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'before_calculate_totals' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'get_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_item_meta' ), 10, 4 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'reconcile_missing_extras' ), 40, 2 );
		add_filter( 'woocommerce_order_again_cart_item_data', array( $this, 'order_again_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_order_itemmeta' ) );
	}

	/**
	 * Precio base limpio de lo que realmente se agrega al carrito.
	 *
	 * @param int $product_id   Producto padre.
	 * @param int $variation_id Variación (0 si no aplica).
	 * @return float
	 */
	protected function base_price_for( $product_id, $variation_id = 0 ) {
		$target = $variation_id ? $variation_id : $product_id;
		$object = wc_get_product( $target );

		if ( ! $object ) {
			return 0.0;
		}

		$price = $object->get_price( 'edit' );

		return '' === $price || null === $price ? 0.0 : (float) $price;
	}

	/**
	 * Lee y sanitiza la selección enviada en el formulario.
	 *
	 * @return array
	 */
	protected function read_posted_selection() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce no usa nonce en add-to-cart; los datos se sanitizan en sanitize_selection().
		$raw = isset( $_POST['io_addons'] ) ? wp_unslash( $_POST['io_addons'] ) : array();

		return IO_Addons_Pricing::sanitize_selection( $raw );
	}

	/**
	 * Valida la selección antes de agregar al carrito.
	 *
	 * @param bool $passed       Resultado previo.
	 * @param int  $product_id   Producto.
	 * @param int  $quantity     Cantidad.
	 * @param int  $variation_id Variación.
	 * @return bool
	 */
	public function validate( $passed, $product_id, $quantity, $variation_id = 0 ) {
		if ( ! $passed ) {
			return $passed;
		}

		$config = io_addons_get_product_config( $product_id );

		if ( ! io_addons_config_has_content( $config ) ) {
			return $passed;
		}

		$selection = $this->read_posted_selection();
		$base      = $this->base_price_for( $product_id, $variation_id );
		$result    = IO_Addons_Pricing::calculate( $config, $selection, $base, $variation_id );

		if ( ! empty( $result['errors'] ) ) {
			foreach ( array_unique( $result['errors'] ) as $error ) {
				wc_add_notice( $error, 'error' );
			}
			return false;
		}

		return $passed;
	}

	/**
	 * Guarda la selección en el cart item.
	 *
	 * Solo va la selección (no los importes calculados): así el hash que
	 * WooCommerce usa para la clave del cart item queda estable y dos selecciones
	 * distintas del mismo producto siguen siendo dos líneas distintas.
	 *
	 * @param array $cart_item_data Datos.
	 * @param int   $product_id     Producto.
	 * @param int   $variation_id   Variación.
	 * @return array
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id = 0 ) {
		$config = io_addons_get_product_config( $product_id );

		if ( ! io_addons_config_has_content( $config ) ) {
			return $cart_item_data;
		}

		$selection = $this->read_posted_selection();

		$cart_item_data[ self::CART_KEY ] = array(
			'selection'    => $selection,
			'variation_id' => absint( $variation_id ),
		);

		return $cart_item_data;
	}

	/**
	 * Calcula y cachea el precio, y lo aplica al agregar al carrito.
	 *
	 * @param array  $cart_item Cart item.
	 * @param string $cart_key  Clave.
	 * @return array
	 */
	public function add_cart_item( $cart_item, $cart_key = '' ) {
		return $this->prepare_cart_item( $cart_item, true );
	}

	/**
	 * Reaplica el precio al reconstruir el carrito desde sesión.
	 *
	 * @param array  $cart_item Cart item.
	 * @param array  $values    Valores de sesión.
	 * @param string $key       Clave.
	 * @return array
	 */
	public function get_cart_item_from_session( $cart_item, $values, $key = '' ) {
		if ( isset( $values[ self::CART_KEY ] ) && ! isset( $cart_item[ self::CART_KEY ] ) ) {
			$cart_item[ self::CART_KEY ] = $values[ self::CART_KEY ];
		}

		// El objeto producto viene fresco de la base: el precio todavía está limpio.
		return $this->prepare_cart_item( $cart_item, true );
	}

	/**
	 * Reaplica el precio en cada recálculo de totales.
	 *
	 * Acá NO se refresca el precio base: a esta altura el producto del cart item
	 * ya tiene el precio final aplicado, así que releerlo duplicaría el recargo.
	 *
	 * @param WC_Cart $cart Carrito.
	 */
	public function before_calculate_totals( $cart ) {
		if ( ! $cart instanceof WC_Cart || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_key => $cart_item ) {
			$prepared = $this->prepare_cart_item( $cart_item, false );

			if ( isset( $prepared[ self::CART_KEY ] ) ) {
				$cart->cart_contents[ $cart_key ][ self::CART_KEY ] = $prepared[ self::CART_KEY ];
			}
		}
	}

	/**
	 * Núcleo compartido: calcula el recargo y aplica el precio final.
	 *
	 * @param array $cart_item     Cart item.
	 * @param bool  $refresh_base  Si hay que releer el precio base del producto.
	 * @return array
	 */
	protected function prepare_cart_item( $cart_item, $refresh_base ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) || ! isset( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
			return $cart_item;
		}

		$product      = $cart_item['data'];
		$product_id   = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : $product->get_id();
		$variation_id = isset( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;

		$config = io_addons_get_product_config( $product_id );

		if ( ! io_addons_config_has_content( $config ) ) {
			return $cart_item;
		}

		$data = $cart_item[ self::CART_KEY ];

		if ( $refresh_base || ! isset( $data['_base_price'] ) ) {
			$price               = $product->get_price( 'edit' );
			$data['_base_price'] = '' === $price || null === $price ? 0.0 : (float) $price;
		}

		$selection = isset( $data['selection'] ) ? $data['selection'] : array();
		$result    = IO_Addons_Pricing::calculate( $config, $selection, $data['_base_price'], $variation_id );

		$data['_extra'] = $result['extra'];
		$data['_lines'] = $result['lines'];

		$cart_item[ self::CART_KEY ] = $data;

		IO_Addons_Pricing::apply_price_final( $product, (float) $data['_base_price'] + (float) $data['_extra'] );

		return $cart_item;
	}

	/**
	 * Líneas "Grupo: Opción (+$X)" en carrito y checkout.
	 *
	 * @param array $item_data Datos ya acumulados.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function get_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item[ self::CART_KEY ]['_lines'] ) ) {
			return $item_data;
		}

		foreach ( $cart_item[ self::CART_KEY ]['_lines'] as $line ) {
			$item_data[] = array(
				'key'   => $this->line_key( $line ),
				'value' => $this->line_value( $line ),
			);
		}

		return $item_data;
	}

	/**
	 * Etiqueta de una línea.
	 *
	 * @param array $line Línea.
	 * @return string
	 */
	protected function line_key( $line ) {
		$group = isset( $line['group'] ) ? $line['group'] : '';

		return '' !== $group ? $group : $line['item'];
	}

	/**
	 * Valor legible de una línea, con su recargo.
	 *
	 * @param array $line Línea.
	 * @return string
	 */
	protected function line_value( $line ) {
		$parts = array();
		$group = isset( $line['group'] ) ? $line['group'] : '';

		if ( '' !== $group ) {
			$parts[] = $line['item'];
		}

		if ( ! empty( $line['value'] ) ) {
			$parts[] = $line['value'];
		}

		$value = implode( ' · ', array_filter( $parts ) );

		if ( '' === $value ) {
			$value = $line['item'];
		}

		if ( ! empty( $line['qty'] ) && (int) $line['qty'] > 1 ) {
			/* translators: %d: cantidad. */
			$value .= ' ' . sprintf( __( '×%d', 'io-addons' ), (int) $line['qty'] );
		}

		if ( ! empty( $line['amount'] ) && (float) $line['amount'] > 0 ) {
			$value .= ' (+' . wp_strip_all_tags( wc_price( (float) $line['amount'] ) ) . ')';
		} elseif ( isset( $line['kind'] ) && 'included' === $line['kind'] ) {
			$value .= ' (' . __( 'incluido', 'io-addons' ) . ')';
		}

		return $value;
	}

	/**
	 * Persiste la selección como order item meta, vía API CRUD.
	 *
	 * @param WC_Order_Item_Product $item          Ítem del pedido.
	 * @param string                $cart_item_key Clave del cart item.
	 * @param array                 $values        Cart item.
	 * @param WC_Order              $order         Pedido.
	 */
	public function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values[ self::CART_KEY ] ) ) {
			return;
		}

		$data = $values[ self::CART_KEY ];

		// Estructura completa, oculta de la vista del cliente: es la fuente de verdad
		// para reconciliar y para reconstruir el pedido más adelante.
		$item->add_meta_data( self::ORDER_KEY, $data, true );

		if ( empty( $data['_lines'] ) ) {
			return;
		}

		foreach ( $data['_lines'] as $line ) {
			$item->add_meta_data( $this->line_key( $line ), $this->line_value( $line ) );
		}
	}

	/**
	 * Oculta la estructura interna en el admin del pedido.
	 *
	 * @param array $keys Metas ocultas.
	 * @return array
	 */
	public function hidden_order_itemmeta( $keys ) {
		$keys[] = self::ORDER_KEY;

		return $keys;
	}

	/**
	 * Red de seguridad antes de que la pasarela cobre.
	 *
	 * Recalcula desde la selección guardada lo que cada línea DEBERÍA costar y
	 * corrige el pedido si no coincide.
	 *
	 * @param WC_Order $order Pedido.
	 * @param array    $data  Datos del checkout.
	 */
	public function reconcile_missing_extras( $order, $data = array() ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$changed  = false;
		$decimals = io_addons_price_decimals();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$stored = $item->get_meta( self::ORDER_KEY, true );

			if ( empty( $stored ) || ! is_array( $stored ) || ! isset( $stored['_base_price'] ) ) {
				continue;
			}

			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$config       = io_addons_get_product_config( $product_id );

			if ( ! io_addons_config_has_content( $config ) ) {
				continue;
			}

			$selection = isset( $stored['selection'] ) ? $stored['selection'] : array();
			$result    = IO_Addons_Pricing::calculate( $config, $selection, (float) $stored['_base_price'], $variation_id );

			$quantity = max( 1, (int) $item->get_quantity() );
			$expected = round( ( (float) $stored['_base_price'] + (float) $result['extra'] ) * $quantity, $decimals );
			$current  = round( (float) $item->get_total(), $decimals );

			if ( abs( $expected - $current ) < 0.01 ) {
				continue;
			}

			$item->set_subtotal( $expected );
			$item->set_total( $expected );

			$changed = true;
		}

		if ( ! $changed ) {
			return;
		}

		$order->calculate_taxes();
		$order->calculate_totals( false );
	}

	/**
	 * Reconstruye la selección al usar "Pedir de nuevo".
	 *
	 * @param array                 $cart_item_data Datos.
	 * @param WC_Order_Item_Product $item           Ítem del pedido.
	 * @param WC_Order              $order          Pedido.
	 * @return array
	 */
	public function order_again_cart_item_data( $cart_item_data, $item, $order ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return $cart_item_data;
		}

		$stored = $item->get_meta( self::ORDER_KEY, true );

		if ( empty( $stored ) || ! is_array( $stored ) || empty( $stored['selection'] ) ) {
			return $cart_item_data;
		}

		$cart_item_data[ self::CART_KEY ] = array(
			'selection'    => $stored['selection'],
			'variation_id' => isset( $stored['variation_id'] ) ? absint( $stored['variation_id'] ) : 0,
		);

		return $cart_item_data;
	}
}

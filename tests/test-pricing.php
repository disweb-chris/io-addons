<?php
/** Banco de pruebas del motor de precios, con stubs mínimos de WordPress/WooCommerce. */

define( 'ABSPATH', '/tmp/' );
define( 'IO_ADDONS_META', '_io_addons_config' );

function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function absint( $n ) { return abs( (int) $n ); }
function wp_kses_post( $s ) { return (string) $s; }
function wp_generate_password( $len = 12, $a = true, $b = false ) { return substr( str_shuffle( 'abcdefghijklmnopqrstuvwxyz0123456789' ), 0, $len ); }
function apply_filters( $tag, $value ) { return $value; }
function __( $t, $d = '' ) { return $t; }
function wc_get_price_decimals() { return 2; }
function get_post_meta( $id, $key, $single = false ) { return ''; }
function wp_get_post_parent_id( $id ) { return 0; }
function get_post_type( $id ) { return 'product'; }

require_once __DIR__ . '/../includes/io-addons-functions.php';
require_once __DIR__ . '/../includes/class-io-addons-pricing.php';

$pass = 0;
$fail = 0;

function check( $label, $actual, $expected ) {
	global $pass, $fail;
	$ok = is_float( $expected ) || is_float( $actual )
		? abs( (float) $actual - (float) $expected ) < 0.0001
		: $actual === $expected;
	if ( $ok ) {
		$pass++;
		echo "  ok   $label\n";
	} else {
		$fail++;
		echo "  FAIL $label — esperado: " . var_export( $expected, true ) . " · obtenido: " . var_export( $actual, true ) . "\n";
	}
}

/* ------------------------------------------------------------------ */
echo "\n[1] Sanitización\n";

$raw = array(
	'groups' => array(
		array(
			'id'    => 'anillado',
			'title' => 'Anillado',
			'type'  => 'group',
			'exclusive' => '1',
			'items' => array(
				array( 'id' => 'wireo', 'title' => 'Wire-O', 'price' => '350,50', 'price_type' => 'fixed' ),
				array( 'id' => 'espiral', 'title' => 'Espiral', 'price' => '1.250,00' ),
				array( 'title' => '' ), // sin título: se descarta
			),
		),
		array( 'id' => 'vacio', 'title' => 'Vacío', 'type' => 'group', 'items' => array() ), // se descarta
	),
);

$config = io_addons_sanitize_config( $raw );
check( 'grupos válidos conservados', count( $config['groups'] ), 1 );
check( 'ítems sin título descartados', count( $config['groups'][0]['items'] ), 2 );
check( 'precio con coma decimal', $config['groups'][0]['items'][0]['price'], 350.50 );
check( 'precio con miles y coma', $config['groups'][0]['items'][1]['price'], 1250.00 );
check( 'exclusive castea a bool', $config['groups'][0]['exclusive'], true );
check( 'price_type por defecto', $config['groups'][0]['items'][1]['price_type'], 'fixed' );

// Condición que apunta a un id inexistente se limpia.
$raw2 = array( 'groups' => array( array(
	'id' => 'g1', 'title' => 'G', 'type' => 'group',
	'items' => array( array( 'id' => 'a', 'title' => 'A', 'condition' => array( 'addon_id' => 'fantasma', 'is' => 'selected' ) ) ),
) ) );
$c2 = io_addons_sanitize_config( $raw2 );
check( 'condición huérfana se limpia', $c2['groups'][0]['items'][0]['condition']['addon_id'], '' );

// Reglas de variación sin IDs degradan a 'all'.
$raw3 = array( 'groups' => array( array(
	'id' => 'g1', 'title' => 'G', 'type' => 'group',
	'variation_rules' => array( 'mode' => 'include', 'variation_ids' => array() ),
	'items' => array( array( 'id' => 'a', 'title' => 'A' ) ),
) ) );
$c3 = io_addons_sanitize_config( $raw3 );
check( 'regla de variación vacía degrada a all', $c3['groups'][0]['variation_rules']['mode'], 'all' );

/* ------------------------------------------------------------------ */
echo "\n[2] Precio fijo y porcentual\n";

$config = io_addons_sanitize_config( array( 'groups' => array(
	array(
		'id' => 'anillado', 'title' => 'Anillado', 'type' => 'group', 'exclusive' => true,
		'items' => array(
			array( 'id' => 'wireo', 'title' => 'Wire-O', 'price' => 350, 'axes' => array(
				array( 'name' => 'Color', 'required' => true, 'options' => array(
					array( 'label' => 'Negro', 'price' => 0 ),
					array( 'label' => 'Dorado', 'price' => 120 ),
				) ),
			) ),
			array( 'id' => 'espiral', 'title' => 'Espiral', 'price' => 200 ),
		),
	),
	array(
		'id' => 'encuad', 'title' => 'Encuadernado', 'type' => 'group',
		'items' => array(
			array( 'id' => 'premium', 'title' => 'Premium', 'price' => 15, 'price_type' => 'percentage' ),
		),
	),
) ) );

$base = 1000.0;

$sel = IO_Addons_Pricing::sanitize_selection( array( 'items' => array( 'anillado' => array( 'wireo' ) ), 'axes' => array( 'wireo' => array( 0 => 1 ) ) ) );
$r = IO_Addons_Pricing::calculate( $config, $sel, $base );
check( 'fijo + eje con precio', $r['extra'], 470.0 );
check( 'sin errores', count( $r['errors'] ), 0 );

$sel = IO_Addons_Pricing::sanitize_selection( array( 'items' => array( 'encuad' => array( 'premium' ) ) ) );
$r = IO_Addons_Pricing::calculate( $config, $sel, $base );
check( 'porcentual 15% de 1000', $r['extra'], 150.0 );

$sel = IO_Addons_Pricing::sanitize_selection( array(
	'items' => array( 'anillado' => array( 'espiral' ), 'encuad' => array( 'premium' ) ),
) );
$r = IO_Addons_Pricing::calculate( $config, $sel, $base );
check( 'fijo + porcentual combinados', $r['extra'], 350.0 );

// Grupo exclusivo: dos marcados, solo cuenta el primero.
$sel = IO_Addons_Pricing::sanitize_selection( array( 'items' => array( 'anillado' => array( 'wireo', 'espiral' ) ), 'axes' => array( 'wireo' => array( 0 => 0 ) ) ) );
$r = IO_Addons_Pricing::calculate( $config, $sel, $base );
check( 'grupo exclusivo cobra una sola opción', $r['extra'], 350.0 );

// Eje obligatorio sin elegir.
$sel = IO_Addons_Pricing::sanitize_selection( array( 'items' => array( 'anillado' => array( 'wireo' ) ) ) );
$r = IO_Addons_Pricing::calculate( $config, $sel, $base );
check( 'eje obligatorio sin elegir da error', count( $r['errors'] ), 1 );

/* ------------------------------------------------------------------ */
echo "\n[3] Reglas condicionales\n";

$config = io_addons_sanitize_config( array( 'groups' => array(
	array( 'id' => 'imp', 'title' => 'Impresión', 'type' => 'group', 'exclusive' => true, 'items' => array(
		array( 'id' => 'color', 'title' => 'A color', 'price' => 500 ),
		array( 'id' => 'bn', 'title' => 'Blanco y negro', 'price' => 0 ),
	) ),
	array( 'id' => 'lam', 'title' => 'Laminado', 'type' => 'group', 'items' => array(
		array( 'id' => 'brillante', 'title' => 'Brillante', 'price' => 300, 'condition' => array( 'addon_id' => 'color', 'is' => 'selected' ) ),
	) ),
) ) );

$sel = IO_Addons_Pricing::sanitize_selection( array( 'items' => array( 'imp' => array( 'color' ), 'lam' => array( 'brillante' ) ) ) );
$r = IO_Addons_Pricing::calculate( $config, $sel, 1000.0 );
check( 'condición cumplida: cobra ambos', $r['extra'], 800.0 );

// Intento de forzar el addon condicionado sin cumplir la condición.
$sel = IO_Addons_Pricing::sanitize_selection( array( 'items' => array( 'imp' => array( 'bn' ), 'lam' => array( 'brillante' ) ) ) );
$r = IO_Addons_Pricing::calculate( $config, $sel, 1000.0 );
check( 'condición incumplida: el ítem oculto no se cobra', $r['extra'], 0.0 );
check( 'condición incumplida: no aparece en líneas', count( $r['lines'] ), 1 );

/* ------------------------------------------------------------------ */
echo "\n[4] Reglas por variación\n";

$config = io_addons_sanitize_config( array( 'groups' => array(
	array(
		'id' => 'anillado', 'title' => 'Anillado', 'type' => 'group',
		'variation_rules' => array( 'mode' => 'include', 'variation_ids' => array( 101, 102 ) ),
		'items' => array( array( 'id' => 'wireo', 'title' => 'Wire-O', 'price' => 350 ) ),
	),
	array(
		'id' => 'troq', 'title' => 'Troquelado', 'type' => 'group',
		'items' => array( array(
			'id' => 'esquinas', 'title' => 'Esquinas redondeadas', 'price' => 90,
			'variation_rules' => array( 'mode' => 'exclude', 'variation_ids' => array( 103 ) ),
		) ),
	),
) ) );

$sel = IO_Addons_Pricing::sanitize_selection( array( 'items' => array( 'anillado' => array( 'wireo' ), 'troq' => array( 'esquinas' ) ) ) );

$r = IO_Addons_Pricing::calculate( $config, $sel, 1000.0, 101 );
check( 'variación incluida: cobra ambos', $r['extra'], 440.0 );

$r = IO_Addons_Pricing::calculate( $config, $sel, 1000.0, 999 );
check( 'variación fuera del include: no cobra el grupo', $r['extra'], 90.0 );

$r = IO_Addons_Pricing::calculate( $config, $sel, 1000.0, 103 );
check( 'variación excluida a nivel de ítem', $r['extra'], 0.0 );

$r = IO_Addons_Pricing::calculate( $config, $sel, 1000.0, 0 );
check( 'sin variación elegida: no filtra (lo hace el JS)', $r['extra'], 440.0 );

/* ------------------------------------------------------------------ */
echo "\n[5] Obligatorios, incluidos y grupos sin cargo\n";

$config = io_addons_sanitize_config( array( 'groups' => array(
	array( 'id' => 'g', 'title' => 'G', 'type' => 'group', 'items' => array(
		array( 'id' => 'obl', 'title' => 'Obligatorio', 'price' => 100, 'mandatory' => true ),
		array( 'id' => 'inc', 'title' => 'Incluido', 'price' => 500, 'included' => true ),
	) ),
	array( 'id' => 'free', 'title' => 'Extras', 'type' => 'group', 'free' => true, 'max' => 2, 'items' => array(
		array( 'id' => 'e1', 'title' => 'Extra 1', 'price' => 80 ),
		array( 'id' => 'e2', 'title' => 'Extra 2', 'price' => 80 ),
		array( 'id' => 'e3', 'title' => 'Extra 3', 'price' => 80 ),
	) ),
) ) );

$r = IO_Addons_Pricing::calculate( $config, IO_Addons_Pricing::sanitize_selection( array() ), 1000.0 );
check( 'obligatorio se cobra aunque no venga en el POST', $r['extra'], 100.0 );

$sel = IO_Addons_Pricing::sanitize_selection( array( 'items' => array( 'free' => array( 'e1', 'e2' ) ) ) );
$r = IO_Addons_Pricing::calculate( $config, $sel, 1000.0 );
check( 'grupo sin cargo no suma', $r['extra'], 100.0 );
check( 'dentro del tope: sin errores', count( $r['errors'] ), 0 );

$sel = IO_Addons_Pricing::sanitize_selection( array( 'items' => array( 'free' => array( 'e1', 'e2', 'e3' ) ) ) );
$r = IO_Addons_Pricing::calculate( $config, $sel, 1000.0 );
check( 'pasarse del tope da error', count( $r['errors'] ), 1 );

/* ------------------------------------------------------------------ */
echo "\n[6] Campos de personalización\n";

$config = io_addons_sanitize_config( array( 'groups' => array(
	array( 'id' => 'pers', 'title' => 'Personalización', 'type' => 'fields', 'fields' => array(
		array( 'id' => 'tapa', 'label' => 'Nombre en tapa', 'type' => 'text', 'required' => true, 'price' => 250, 'maxlength' => 5 ),
		array( 'id' => 'papel', 'label' => 'Papel', 'type' => 'select', 'options' => array(
			array( 'label' => 'Obra 90g', 'price' => 0 ),
			array( 'label' => 'Ilustración 150g', 'price' => 10, 'price_type' => 'percentage' ),
		) ),
	) ),
) ) );

$sel = IO_Addons_Pricing::sanitize_selection( array( 'fields' => array( 'pers' => array( 'tapa' => 'Christian', 'papel' => 'Ilustración 150g' ) ) ) );
$r = IO_Addons_Pricing::calculate( $config, $sel, 1000.0 );
check( 'campo de texto con precio + select porcentual', $r['extra'], 350.0 );
check( 'maxlength recorta el valor guardado', $r['lines'][0]['value'], 'Chris' );

$sel = IO_Addons_Pricing::sanitize_selection( array( 'fields' => array( 'pers' => array( 'papel' => 'Obra 90g' ) ) ) );
$r = IO_Addons_Pricing::calculate( $config, $sel, 1000.0 );
check( 'campo obligatorio vacío da error', count( $r['errors'] ), 1 );

$sel = IO_Addons_Pricing::sanitize_selection( array( 'fields' => array( 'pers' => array( 'tapa' => 'Ana', 'papel' => 'Papel inventado' ) ) ) );
$r = IO_Addons_Pricing::calculate( $config, $sel, 1000.0 );
check( 'opción de select inexistente se rechaza', count( $r['errors'] ), 1 );
check( 'opción inexistente no suma', $r['extra'], 250.0 );

/* ------------------------------------------------------------------ */
echo "\n[7] Casos borde\n";

$r = IO_Addons_Pricing::calculate( io_addons_empty_config(), array(), 1000.0 );
check( 'config vacía no rompe', $r['extra'], 0.0 );

$sel = IO_Addons_Pricing::sanitize_selection( array( 'items' => array( 'g' => array( 'inexistente' ) ) ) );
$r = IO_Addons_Pricing::calculate( $config, $sel, 1000.0 );
check( 'ítem inexistente se ignora', $r['extra'], 0.0 );

$config = io_addons_sanitize_config( array( 'groups' => array(
	array( 'id' => 'g', 'title' => 'G', 'type' => 'group', 'items' => array(
		array( 'id' => 'p', 'title' => 'P', 'price' => 33.333, 'price_type' => 'percentage' ),
	) ),
) ) );
$sel = IO_Addons_Pricing::sanitize_selection( array( 'items' => array( 'g' => array( 'p' ) ) ) );
$r = IO_Addons_Pricing::calculate( $config, $sel, 99.99 );
check( 'porcentual redondea a 2 decimales', $r['extra'], 33.33 );

echo "\n----------------------------------------\n";
echo "  $pass pruebas OK · $fail fallos\n";
echo "----------------------------------------\n";

exit( $fail > 0 ? 1 : 0 );

# Imprenta Online — Addons

Plugin propio de opciones de acabado con recargo de precio para **WooCommerce + XStore**, hecho a medida para [imprentaonline.ar](https://imprentaonline.ar).

Reemplaza a YITH WooCommerce Advanced Product Options, cuyo submódulo `color-label-variations/` rompía la galería nativa de imágenes en todos los productos variables del sitio.

## Regla de oro

**Este plugin no toca la galería de imágenes del producto.** Ni un selector, ni un hook, ni un listener.

- Nada de `.woocommerce-product-gallery`, `wc_product_gallery()`, `woocommerce_show_product_images()` ni del evento `reset_image`.
- Cero llamadas AJAX en el frontend.
- Los únicos eventos de WooCommerce que se escuchan son `found_variation` y `reset_data`, sobre `form.cart`, **de solo lectura**: para leer el precio base de la variación y mostrar/ocultar nodos propios del plugin según las reglas por variación.

Si en el futuro hace falta mostrar una miniatura de muestra de un acabado, va **dentro de la tarjeta del addon** (`.io-item__thumb`), nunca en el contenedor de galería del producto.

## Qué hace

- **Grupos de opciones** con recargo: anillado, encuadernado, laminado, plastificado — o cualquier terminado nuevo.
- **Ejes** dentro de cada opción: color de espiral, grosor de laminado, tipo de acabado…
- **Precio fijo o porcentual** (`% del precio del producto`), en ítems, ejes y campos.
- **Reglas condicionales** entre addons: "laminado brillante solo si se eligió impresión a color".
- **Reglas por variación**: un grupo o una opción puntual puede restringirse a variaciones concretas (`todas` / `solo estas` / `todas excepto estas`).
- **Campos de personalización** (texto, texto largo, número, lista) con precio opcional.
- **Plantillas por categoría** + plantilla genérica con opt-out por producto.
- **Resumen en vivo** del precio mientras el cliente elige.

## Arquitectura

Un único `postmeta` por producto (`_io_addons_config`), array PHP. **Sin tablas SQL propias.**

```
io-addons.php                          Bootstrap, constantes, declaración HPOS
includes/io-addons-functions.php       Sanitización (punto único) + resolución de config
includes/class-io-addons-pricing.php   Motor de cálculo — única fuente de verdad del precio
includes/class-io-addons-templates.php CPT de plantillas + cascada de resolución
includes/class-io-addons-admin.php     Meta boxes + AJAX de variaciones
includes/class-io-addons-frontend.php  Render en la ficha de producto
includes/class-io-addons-cart.php      Carrito, precio y pedido
assets/                                CSS y JS planos, sin build step
tests/test-pricing.php                 Banco de pruebas del motor de precios
```

### Flujo de guardado

`JS → textarea con JSON → json_decode() → io_addons_sanitize_config() → update_post_meta()`

Todo lo que escribe en el meta pase por `io_addons_sanitize_config()`. Un solo punto de validación.

### Resolución de config

1. Config propia del producto (si tiene contenido real).
2. Plantilla que tiene a este producto marcado como "producto específico" — gana a la asociación por categoría sin importar la prioridad manual de la plantilla.
3. Plantilla asociada a alguna de sus categorías (o categorías ancestro).
4. Plantilla genérica, salvo opt-out del producto.
5. Vacío.

La asociación por "productos específicos" sirve para el caso "algunos productos de la categoría sí, el resto no": se busca el producto por nombre desde el editor de la plantilla (`_io_addons_template_products`) sin necesidad de reorganizar categorías ni tocar código.

### Precio

`IO_Addons_Pricing::calculate()` es la única fórmula. El JS la replica **solo para mostrar el total en vivo**; lo que se cobra siempre lo calcula el servidor.

`apply_price_final()` sincroniza los tres campos:

```php
$product->set_price( $final );
$product->set_regular_price( $final );
$product->set_sale_price( $final );
```

`set_price()` solo no alcanza: si el producto tiene `_sale_price` activo, WooCommerce sigue cobrando el precio de oferta.

Se aplica en los tres momentos del ciclo de vida del carrito:

| Hook | Prio | Refresca el precio base |
|---|---|---|
| `woocommerce_add_cart_item` | 20 | sí |
| `woocommerce_get_cart_item_from_session` | 20 | sí |
| `woocommerce_before_calculate_totals` | 20 | no — usa `_base_price` cacheado |

El precio base cacheado en `cart_item['io_addons']['_base_price']` es lo que da idempotencia: en `before_calculate_totals` el producto ya tiene el precio final aplicado, así que releerlo duplicaría el recargo.

### Red de seguridad

`reconcile_missing_extras()` (en `woocommerce_checkout_create_order`, prio 40) recalcula desde la selección guardada lo que cada línea debería costar y corrige el pedido si no coincide — última verificación antes de que la pasarela cobre.

### HPOS

Compatibilidad declarada explícitamente. Toda la persistencia de pedido usa la API CRUD de WooCommerce (`$item->add_meta_data()`, `$order->update_meta_data()`), nunca `$wpdb` directo.

## Extensibilidad

El catálogo de acabados **no está hardcodeado**. Desde *Producto → Opciones de acabado (addons)* se crea cualquier grupo, opción, eje o campo nuevo sin tocar código: barniz UV, troquelado, esquinas redondeadas, perforado, numerado correlativo, estampado en caliente, relieve, cosido a hilo, ojetes, gramajes de papel…

Filtros disponibles:

- `io_addons_sanitize_config` — ajustar la config ya sanitizada antes de guardarla.
- `io_addons_product_config` — ajustar la config resuelta de un producto.

## Paleta

```css
--io-blue:   #2E509E;  /* acento secundario: estados de selección, foco */
--io-white:  #F9FAFB;  /* fondo de tarjetas y superficies claras */
--io-orange: #FF6B00;  /* acento principal: precios, badges, bordes */
--io-black:  #1D1D1A;  /* cabeceras de grupo y caja de resumen */
```

## Pruebas

```bash
php tests/test-pricing.php
```

33 casos sobre sanitización, precio fijo y porcentual, grupos exclusivos, reglas condicionales, reglas por variación, obligatorios/incluidos, topes sin cargo, campos de personalización y casos borde. No requiere WordPress: usa stubs.

## Validación manual pendiente

Antes de dar por cerrada la primera versión, en el sitio real:

1. Abrir un **producto variable** con el plugin activo y confirmar que la galería de XStore carga sin retraso ni reemplazo por el placeholder (era el bug de YITH, a ~1.5 s de la carga).
2. Cambiar de variación varias veces y confirmar que la galería sigue intacta y que los addons con reglas por variación se muestran/ocultan correctamente.
3. Agregar al carrito con addons y verificar el precio en carrito, checkout y pedido — con un producto **en oferta**, que es el caso que rompe si `apply_price_final()` no sincroniza los tres campos.

## Requisitos

WordPress 6.0+ · WooCommerce 7.0+ · PHP 7.4+

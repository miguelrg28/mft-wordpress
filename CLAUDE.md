# MFT WordPress: tema del sitio

Este repo contiene el tema de WordPress que sirve **www.masflowteam.net** (eventos,
tours, tienda, itinerarios y blog sobre WooCommerce). Va dirigido a quien haga
cambios con IA: léelo antes de tocar nada.

## Estructura
- `theme/mft/` se despliega en `wp-content/themes/mft/`.
  - `functions.php` solo carga los archivos de `inc/`.
  - `inc/setup.php`: menús, estilos y scripts, contador del carrito.
  - `inc/content.php`: logo, contacto y slides del hero (los datos se editan en el admin de WP).
  - `inc/catalog.php`: a qué sección va cada producto según su categoría, boletos y precios.
  - `inc/icons.php`: íconos SVG (`mft_icon('calendar')`).
  - `inc/analytics.php`: GA4, AdSense y Meta Pixel.
  - Plantillas: `front-page.php` (Home), `page-<slug>.php` (Eventos, Tours, MFTSports,
    Tienda…), `page.php`, `single.php`, `home.php` (blog), `template-parts/`.
  - `assets/css/mft.css`: todos los estilos. Los colores son variables `--mft-*` al inicio.
  - `assets/js/mft.js`: carrusel y menú móvil, sin librerías.
- `plugins/mft-azul-api/` se despliega en `wp-content/plugins/mft-azul-api/`: pago con
  tarjeta vía la API de AZUL con 3D Secure (ver la cabecera del archivo). Las credenciales,
  el certificado y la llave de AZUL van en `wp-config.php` y en `~/azul/` del servidor,
  **nunca en el repo**.
- **No hay paso de compilación.** Se edita el PHP/CSS/JS y listo.

## Reglas
1. **Todo cambio se prueba primero en staging** (`staging.masflowteam.net`), nunca directo en producción.
2. **Pagos: máxima precaución.** No toques el checkout, AZUL, FooEvents, los fees ni
   las páginas `[azul_payment]` / `[azul_response]` sin probar una compra completa en
   staging con AZUL en modo pruebas. Nunca hagas órdenes de prueba en producción.
3. Los textos y datos que el equipo cambia seguido (slides, contacto, logo, productos,
   itinerarios) viven en el admin de WordPress, no en el código.
4. Mantén el estilo: tarjetas oscuras (`.mft-card`), acento `--mft-accent-primary` solo
   en botones y estados, títulos en Montserrat y texto en Inter.
5. Escapa todo lo que se imprime (`esc_html`, `esc_url`, `esc_attr`).
6. Antes de desplegar, revisa en móvil (2 columnas en listados) y en escritorio.

## Desplegar
- Rama `staging` → staging; rama `main` → producción (GitHub Actions).
- Para deshacer un cambio en producción: revierte el commit y vuelve a desplegar.

# mft-wordpress

Tema de WordPress de Mas Flow Team (`theme/mft/`). Reemplaza al front en Next.js:
WordPress pasa a servir todo `www.masflowteam.net` con WooCommerce nativo.
Las reglas para hacer cambios están en [CLAUDE.md](CLAUDE.md).

## Staging (una sola vez)
1. **DNS (Cloudflare):** registro A `staging` → IP del VPS, nube gris.
2. **CloudPanel:** *Add Site → Create a WordPress Site*
   - Dominio: `staging.masflowteam.net`, PHP 8.3, usuario `masflowteam-staging`.
   - Emite el certificado de Let's Encrypt.
3. **`wp-config.php` de staging** (CloudPanel → el sitio → *File Manager*), antes de `/* That's all, stop editing! */`:
   ```php
   define('MFT_HEADLESS', false);   // WordPress muestra su propio tema
   define('DISABLE_WP_CRON', true);
   define('WP_ENVIRONMENT_TYPE', 'staging');
   ```
4. **Copiar producción a staging** (como root, en el VPS):
   ```bash
   bash staging-refresh.sh
   ```
   Se puede repetir cuando haga falta traer datos frescos.
5. **AZUL en modo pruebas** en staging antes de probar cualquier pago.
6. **Instalar el tema:** copia `theme/mft` a `wp-content/themes/mft` de staging (o deja que lo
   haga GitHub Actions, ver abajo) y actívalo en *Apariencia → Temas*.
7. **Páginas y enlaces permanentes:** copia este repo al VPS y, en la carpeta del WordPress de staging:
   ```bash
   wp eval-file ~/mft-wordpress/scripts/setup-pages.php
   ```
   Crea las páginas que usa el tema (`events`, `tours`, `itinerarios`, `contacto`,
   `print-center`, políticas…), deja `blog` como página de entradas, productos en
   `/event/<slug>/` y entradas en `/blog/<slug>/`. No toca páginas ya publicadas.
8. **Checkout clásico:** la página de checkout debe tener `[woocommerce_checkout]` (no el
   bloque de checkout): AZUL, FooEvents y los fees solo funcionan con el clásico.

## Despliegue automático (GitHub Actions)
`.github/workflows/deploy.yml` revisa la sintaxis PHP y copia `theme/mft` al VPS:
rama `staging` → staging, rama `main` → producción. Secrets del repo: `VPS_HOST`,
`VPS_KNOWN_HOSTS`, `STAGING_SSH_KEY` y `PROD_SSH_KEY` (una llave por usuario de sitio;
la pública va en CloudPanel → el sitio → *SSH/FTP*).

## Probar en local (opcional)
Con Docker se puede levantar WordPress + WooCommerce con `MFT_HEADLESS` en false, montar
`theme/mft` y el plugin, y crear productos de prueba. Así se probó el tema antes de staging.

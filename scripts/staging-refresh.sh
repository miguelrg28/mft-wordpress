#!/bin/bash
# Copia el WordPress de producción (admin.masflowteam.net) a staging
# (staging.masflowteam.net) para probar el tema sin tocar producción.
# Correr en el VPS como root:  bash staging-refresh.sh
#
# Requisitos: el sitio de staging ya creado en CloudPanel (Create a WordPress Site),
# con su base de datos y su wp-config.php propios. Este script NO toca el
# wp-config.php de staging ni nada de producción (solo lee).
#
# Cada vez que se corre, staging queda igual que producción (se pierde lo que
# hubiera en staging).
set -euo pipefail

PROD_USER=masflowteam-admin
PROD_DIR=/home/$PROD_USER/htdocs/admin.masflowteam.net
PROD_URL=https://admin.masflowteam.net

STG_USER=${STG_USER:-masflowteam-staging}
STG_DIR=/home/$STG_USER/htdocs/staging.masflowteam.net
STG_URL=https://staging.masflowteam.net

# En staging nada sale hacia afuera: sin correos (mu-plugin), sin sincronizar con
# Mailchimp/Trello/YOURLS y sin Site Kit.
DEACTIVATE="mailchimp-for-woocommerce wootrello mft-shortlinks google-site-kit"

as_prod() { runuser -u "$PROD_USER" -- bash -c "cd '$PROD_DIR' && $1"; }
as_stg() { runuser -u "$STG_USER" -- bash -c "cd '$STG_DIR' && $1"; }

[ "$PROD_DIR" != "$STG_DIR" ] || { echo "staging y producción son la misma carpeta" >&2; exit 1; }
[ -f "$STG_DIR/wp-config.php" ] || { echo "falta $STG_DIR/wp-config.php: crea primero el sitio en CloudPanel" >&2; exit 1; }
grep -q "MFT_HEADLESS" "$STG_DIR/wp-config.php" || {
  echo "falta define('MFT_HEADLESS', false); en $STG_DIR/wp-config.php (ver README)" >&2; exit 1; }

dump=/home/$PROD_USER/staging-dump.sql
echo "== 1. Exportar la base de producción (solo lectura)"
as_prod "wp db export '$dump' --single-transaction --quiet"
install -o "$STG_USER" -g "$STG_USER" -m 600 "$dump" "/home/$STG_USER/staging-dump.sql"
rm -f "$dump"

echo "== 2. Copiar archivos (sin wp-config.php)"
rsync -a --delete --exclude wp-config.php --exclude 'wp-content/cache/' \
  --exclude 'wp-content/debug.log' "$PROD_DIR/" "$STG_DIR/"
chown -R "$STG_USER:$STG_USER" "$STG_DIR"

echo "== 3. Candado de staging (sin correos ni tareas programadas)"
mkdir -p "$STG_DIR/wp-content/mu-plugins"
cat > "$STG_DIR/wp-content/mu-plugins/mft-staging-guard.php" <<'PHP'
<?php
/**
 * Plugin Name: MFT Staging Guard
 * Description: Staging: no salen correos y no corren tareas en segundo plano.
 */
add_filter('pre_wp_mail', function ($return, $atts) {
    error_log('[MFT Staging Guard] correo bloqueado para: ' . (is_array($atts['to']) ? implode(',', $atts['to']) : $atts['to']));
    return true;
}, 1, 2);
add_filter('action_scheduler_allow_async_request_runner', '__return_false');
add_action('action_scheduler_pre_init', function () {
    add_filter('action_scheduler_queue_runner_concurrent_batches', '__return_zero');
});
add_action('admin_notices', function () {
    echo '<div class="notice notice-warning"><p><strong>STAGING:</strong> no salen correos ni corren tareas programadas.</p></div>';
});
PHP
chown -R "$STG_USER:$STG_USER" "$STG_DIR/wp-content/mu-plugins"

echo "== 4. Importar y cambiar el dominio"
as_stg "wp db reset --yes --quiet && wp db import ~/staging-dump.sql --quiet && rm ~/staging-dump.sql"
as_stg "wp search-replace '$PROD_URL' '$STG_URL' --all-tables-with-prefix --skip-columns=guid --report-changed-only"
as_stg "wp option update blog_public 0 --skip-plugins --skip-themes"

echo "== 5. Plugins que hablan con servicios externos"
as_stg "wp plugin deactivate $DEACTIVATE --skip-themes || true"

echo "== 6. Caché y enlaces"
as_stg "wp cache flush && wp rewrite flush"

echo "== Listo: $STG_URL (recuerda: AZUL en modo pruebas antes de probar pagos)"

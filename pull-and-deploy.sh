#!/usr/bin/env bash
# =============================================================================
# pull-and-deploy.sh
#
# Ejecutar en el servidor para hacer git pull y lanzar post-deploy.
# Se ejecuta en CADA DEPLOY despues de que dev haga git push al repo de deploy.
#
# Uso:
#   bash /var/www/pbstud_test/pull-and-deploy.sh
#   bash /var/www/pbstud_test/pull-and-deploy.sh --skip-migrations
#   bash /var/www/pbstud_test/pull-and-deploy.sh --skip-cache
#
# Opciones:
#   --skip-migrations   Omite doctrine:migrations:migrate
#   --skip-cache        Omite cache:clear y cache:warmup
#   --dry-run           Muestra lo que se haria sin ejecutar
# =============================================================================
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/pbstud_test}"
DEPLOY_USER="${DEPLOY_USER:-devpbs}"
WEB_USER="${WEB_USER:-nginx}"
COMPOSER="${COMPOSER:-$(command -v composer 2>/dev/null || echo /usr/local/bin/composer)}"
LOG_FILE="$APP_ROOT/var/log/deploy.log"

# Asegurar que el directorio de logs existe antes de cualquier log()
# var/log puede ser un symlink a shared/var/log — si el symlink esta roto
# tee fallaria con set -euo pipefail antes de hacer cualquier cosa util.
mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true

# Verificar que composer existe antes de continuar
if [[ ! -x "$COMPOSER" ]]; then
    echo "[ERROR] composer no encontrado en: $COMPOSER"
    echo "        Instala composer: https://getcomposer.org/download/"
    echo "        O define la variable: COMPOSER=/ruta/a/composer bash pull-and-deploy.sh"
    exit 1
fi

SKIP_MIGRATIONS=false
SKIP_CACHE=false
DRY_RUN=false

for arg in "$@"; do
    case "$arg" in
        --skip-migrations) SKIP_MIGRATIONS=true ;;
        --skip-cache)      SKIP_CACHE=true ;;
        --dry-run)         DRY_RUN=true ;;
    esac
done

# Colores
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

log() { echo -e "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"; }
ok()  { log "${GREEN}[OK]${NC} $*"; }
err() { log "${RED}[ERROR]${NC} $*"; exit 1; }
warn(){ log "${YELLOW}[WARN]${NC} $*"; }
run() {
    if $DRY_RUN; then echo "[DRY-RUN] $*"; else eval "$@"; fi
}

# Detectar runtime user (PHP-FPM)
detect_runtime_user() {
    local detected=""
    if [[ -f "/etc/php-fpm.d/www.conf" ]]; then
        detected="$(awk -F= '/^[[:space:]]*user[[:space:]]*=/{gsub(/[[:space:]]/,"",$2); print $2; exit}' /etc/php-fpm.d/www.conf 2>/dev/null || true)"
    fi
    [[ -z "$detected" ]] && detected="$(ps -eo user,comm 2>/dev/null | awk '$2=="php-fpm"{print $1; exit}' || true)"
    [[ -z "$detected" ]] && detected="$WEB_USER"
    echo "$detected"
}

RUNTIME_USER="$(detect_runtime_user)"

log "========================================================"
log "  pbstudio-deploy — pull-and-deploy starting"
log "========================================================"
log "APP_ROOT=$APP_ROOT | RUNTIME_USER=$RUNTIME_USER | DRY_RUN=$DRY_RUN"

# Verificaciones previas
[[ -d "$APP_ROOT/.git" ]]     || err "No es un repo git: $APP_ROOT"
[[ -f "$APP_ROOT/bin/console" ]] || err "No se encuentra bin/console en $APP_ROOT"

# Guardar commit actual (para posible rollback)
# IMPORTANTE: git >= 2.35.2 rechaza correr como root en un repo de otro usuario
# ("dubious ownership"). Usar sudo -u DEPLOY_USER para ambas llamadas a rev-parse.
PREVIOUS_COMMIT="$(sudo -u "$DEPLOY_USER" git -C "$APP_ROOT" rev-parse HEAD)"
log "Commit anterior: $PREVIOUS_COMMIT"

# Git pull — IMPORTANTE: debe correr como DEPLOY_USER (no como root) para que
# los archivos nuevos que Git cree tengan owner devpbs:nginx (644/755) y no
# root:root (lo que impediria a nginx leerlos).
# Este script se invoca con 'sudo bash pull-and-deploy.sh', por eso es necesario
# bajar explicitamente al DEPLOY_USER para esta operacion.
log "Ejecutando git pull origin main..."
run sudo -u "$DEPLOY_USER" git -C "$APP_ROOT" pull origin main --ff-only

NEW_COMMIT="$(sudo -u "$DEPLOY_USER" git -C "$APP_ROOT" rev-parse HEAD)"
log "Commit nuevo: $NEW_COMMIT"

# En dry-run git pull no corrio, por lo que PREVIOUS_COMMIT == NEW_COMMIT siempre.
# Solo cancelar si hay cero cambios en un deploy real.
if ! $DRY_RUN && [[ "$PREVIOUS_COMMIT" == "$NEW_COMMIT" ]]; then
    warn "Sin cambios nuevos en el repo. Deploy cancelado."
    exit 0
fi

# Nota: los permisos de los archivos de codigo NO se tocan en cada deploy.
# Bootstrap los fija una sola vez. git pull hereda umask de devpbs (022).

# Asegurar que var/cache/ existe con el owner correcto (solo si falta)
if [[ ! -d "$APP_ROOT/var/cache" ]]; then
    log "var/cache no existe — creando con owner $RUNTIME_USER..."
    run sudo mkdir -p "$APP_ROOT/var/cache"
    run sudo chown "$RUNTIME_USER:$RUNTIME_USER" "$APP_ROOT/var/cache"
    run sudo chmod 700 "$APP_ROOT/var/cache"
fi

# Composer install --no-dev (el vendor no va en el repo de deploy)
# Corre como DEPLOY_USER para que vendor/ quede devpbs:nginx (consistente con bootstrap).
# Con umask 022, composer crea dirs 755 y files 644 — nginx puede leerlos.
log "Ejecutando composer install --no-dev (composer: $COMPOSER)..."
run sudo -u "$DEPLOY_USER" "$COMPOSER" install --no-dev --optimize-autoloader --no-scripts --prefer-dist --no-interaction --working-dir="$APP_ROOT"
ok "Composer completado"

# Migraciones
if ! $SKIP_MIGRATIONS; then
    log "Ejecutando migraciones..."
    run sudo -u "$RUNTIME_USER" \
        APP_ENV=prod APP_DEBUG=0 \
        php "$APP_ROOT/bin/console" doctrine:migrations:migrate --no-interaction --env=prod
    ok "Migraciones completadas"
else
    warn "Migraciones omitidas (--skip-migrations)"
fi

# Cache — en Symfony 6+ cache:clear hace warmup atomico:
# construye el nuevo cache en un dir temporal y hace swap con el anterior.
# No hay ventana sin cache. Se ejecuta como runtime user para que los archivos
# generados ya tengan el owner correcto (PHP-FPM puede leerlos sin chown extra).
if ! $SKIP_CACHE; then
    log "Rebuild de cache (atomic clear + warmup)..."
    run sudo -u "$RUNTIME_USER" \
        APP_ENV=prod APP_DEBUG=0 \
        php "$APP_ROOT/bin/console" cache:clear --env=prod
    ok "Cache lista (atomic warmup completado)"
else
    warn "Cache omitida (--skip-cache)"
fi

# Smoke test
log "Smoke test: bin/console about..."
if run sudo -u "$RUNTIME_USER" APP_ENV=prod APP_DEBUG=0 php "$APP_ROOT/bin/console" about --env=prod; then
    ok "Smoke test OK"
else
    warn "Smoke test devolvio error — revisa var/log/prod.log"
fi

# Recargar PHP-FPM para invalidar opcache
# SIN reload, el servidor seguiria sirviendo el codigo compilado en cache (el anterior)
# durante el TTL de opcache (default: 60s). Con reload, el cambio es inmediato.
log "Recargando PHP-FPM (invalidar opcache)..."
if run sudo systemctl reload php-fpm; then
    ok "PHP-FPM recargado — opcache limpio"
else
    warn "No se pudo recargar PHP-FPM — el codigo nuevo puede tardar hasta el TTL de opcache en activarse"
fi

log "========================================================"
ok "Deploy completado. Commit: $NEW_COMMIT"
log "  Para hacer rollback:"
log "    sudo -u $DEPLOY_USER git -C $APP_ROOT checkout $PREVIOUS_COMMIT"
log "    sudo bash $APP_ROOT/pull-and-deploy.sh --skip-migrations"
log "    # Para restaurar main: sudo -u $DEPLOY_USER git -C $APP_ROOT checkout main"
log "========================================================"

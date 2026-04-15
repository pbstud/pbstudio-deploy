#!/usr/bin/env bash
# =============================================================================
# validate-post-deploy.sh
#
# Pruebas de humo post-deploy en el servidor.
# Correr despues de pull-and-deploy.sh para confirmar que todo funciona.
#
# Uso:
#   bash /var/www/pbstud_test/validate-post-deploy.sh [app_url]
#
# Ejemplo:
#   bash /var/www/pbstud_test/validate-post-deploy.sh http://172.20.3.24
# =============================================================================
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/pbstud_test}"
APP_URL="${1:-http://172.20.3.24}"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
ok()  { echo -e "${GREEN}[OK]${NC}  $*"; }
err() { echo -e "${RED}[FAIL]${NC} $*"; FAIL=1; }
warn(){ echo -e "${YELLOW}[WARN]${NC} $*"; }

FAIL=0

# Detectar runtime user
RUNTIME_USER=""
if [[ -f "/etc/php-fpm.d/www.conf" ]]; then
    RUNTIME_USER="$(awk -F= '/^[[:space:]]*user[[:space:]]*=/{gsub(/[[:space:]]/,"",$2); print $2; exit}' /etc/php-fpm.d/www.conf 2>/dev/null || true)"
fi
[[ -z "$RUNTIME_USER" ]] && RUNTIME_USER="nginx"

echo ""
echo "=========================================================="
echo "  pbstudio — Validacion post-deploy"
echo "  APP_ROOT: $APP_ROOT"
echo "  APP_URL:  $APP_URL"
echo "=========================================================="
echo ""

# 1. bin/console about
echo "[1/6] symfony console about..."
if sudo -u "$RUNTIME_USER" APP_ENV=prod APP_DEBUG=0 php "$APP_ROOT/bin/console" about --env=prod 2>&1; then
    ok "console about OK"
else
    err "console about fallo — revisa logs"
fi

# 2. Migraciones pendientes
# Se usa doctrine:migrations:list (no :status) porque en DMB 3.x :status solo muestra
# estadísticas resumidas — el texto "Not migrated" aparece en :list por cada version.
# grep -ci 'not.*migrat' captura tanto "Not migrated" (DMB 2.x) como "not yet migrated" (DMB 3.x).
echo ""
echo "[2/6] Verificando migraciones pendientes..."
PENDING="$(sudo -u "$RUNTIME_USER" APP_ENV=prod APP_DEBUG=0 php "$APP_ROOT/bin/console" doctrine:migrations:list --env=prod 2>&1 | grep -ci 'not.*migrat' || true)"
if [[ "$PENDING" -eq 0 ]]; then
    ok "Sin migraciones pendientes"
else
    err "Hay $PENDING migracion(es) pendiente(s)"
fi

# 3. Cache generada
echo ""
echo "[3/6] Verificando cache prod..."
if [[ -d "$APP_ROOT/var/cache/prod" ]]; then
    ok "var/cache/prod existe"
else
    err "var/cache/prod no existe — el warmup pudo haber fallado"
fi

# 4. HTTP check — pagina publica
echo ""
echo "[4/6] HTTP check — pagina publica ($APP_URL/)..."
HTTP_CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$APP_URL/" || true)"
if [[ "$HTTP_CODE" =~ ^(200|301|302)$ ]]; then
    ok "HTTP $HTTP_CODE — Pagina publica responde"
else
    err "HTTP $HTTP_CODE — Respuesta inesperada en $APP_URL/"
fi

# 5. HTTP check — backend login
echo ""
echo "[5/6] HTTP check — backend ($APP_URL/backend/login)..."
HTTP_CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$APP_URL/backend/login" || true)"
if [[ "$HTTP_CODE" =~ ^(200|301|302)$ ]]; then
    ok "HTTP $HTTP_CODE — Backend login responde"
else
    err "HTTP $HTTP_CODE — Respuesta inesperada en $APP_URL/backend/login"
fi

# 6. Ultimas lineas del log de prod
echo ""
echo "[6/6] Ultimas lineas de var/log/prod.log..."
if [[ -f "$APP_ROOT/var/log/prod.log" ]]; then
    tail -20 "$APP_ROOT/var/log/prod.log"
    # El log esta en formato JSON: "level_name":"CRITICAL" o "level_name":"ERROR"
    # Solo se escanean las ultimas 200 lineas (entradas recientes) para evitar
    # que errores historicos antiguos disparen falsos positivos.
    ERRORS="$(tail -200 "$APP_ROOT/var/log/prod.log" | grep -cE '"level_name":"(CRITICAL|ERROR)"' || true)"
    if [[ "$ERRORS" -gt 0 ]]; then
        warn "$ERRORS entrada(s) CRITICAL/ERROR recientes en prod.log — revisa el log completo"
    else
        ok "Sin errores criticos recientes en prod.log"
    fi
else
    warn "var/log/prod.log no existe todavia"
fi

echo ""
echo "=========================================================="
if [[ "$FAIL" -eq 0 ]]; then
    ok "Validacion completa — todo OK"
else
    echo -e "${RED}[FAIL]${NC} Hay $FAIL check(s) fallidos — revisa arriba"
    exit 1
fi
echo "=========================================================="

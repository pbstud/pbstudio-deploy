# PBStudio81

Aplicacion web para gestion de reservas, sesiones, paquetes y pagos de estudio.

## Resumen rapido

- Backend: Symfony 6.4 + Doctrine ORM
- Frontend: Twig + JavaScript + Webpack Encore
- PHP objetivo: >= 8.2
- Base de datos: MySQL
- Calidad actual: 28 tests, 67 assertions
- Documentacion central: [DOCUMENTACION/README.md](DOCUMENTACION/README.md)

## Inicio rapido

### 1) Requisitos

- PHP 8.2 o superior
- Composer 2.x
- Node.js 20+
- npm 10+
- MySQL 5.7+
- Extensiones PHP: pdo_mysql, gd, exif, intl, mbstring, openssl, fileinfo

### 2) Instalacion

```bash
git clone <url-del-repositorio>
cd PBStudio81
composer install
npm install
```

### 3) Configuracion local

Crear .env.local con los valores de entorno:

```dotenv
APP_ENV=dev
APP_DEBUG=1
DATABASE_URL="mysql://usuario:password@127.0.0.1:3306/pbstudio"
```

### 4) Base de datos

```bash
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction
```

### 5) Assets + servidor local

```bash
npm run dev
php -d memory_limit=512M -S 127.0.0.1:8000 -t public
```

## Comandos clave

```bash
# Estado general
php bin/console about

# Comandos del dominio
php bin/console list app

# Tests
php bin/phpunit

# Validaciones tecnicas
php bin/console lint:container
php bin/console debug:router
php bin/console doctrine:schema:validate
```

## Cronjobs recomendados

### Cierre automatico de sesiones

Comando: app:session:autoclosing

```cron
*/30 * * * * cd /ruta/a/PBStudio81 ; php bin/console app:session:autoclosing --env=prod
```

### Expiracion de transacciones

Comando: app:transaction:checkexpiration

```cron
0 0 * * * cd /ruta/a/PBStudio81 ; php bin/console app:transaction:checkexpiration --env=prod
```

### Expiracion de lista de espera

Comando: app:waiting-list:expire

```cron
*/30 * * * * cd /ruta/a/PBStudio81 ; php bin/console app:waiting-list:expire --env=prod
```

## Modulos funcionales

- Reservaciones y seleccion de asientos
- Gestion de sesiones y calendario
- Gestion de usuarios y staff
- Transacciones, cupones y pagos
- Lista de espera
- Auditoria de cambios y cancelaciones

## Documentacion tecnica

- Indice documental: [DOCUMENTACION/README.md](DOCUMENTACION/README.md)
- Plan tecnico y backlog: [DOCUMENTACION/PLAN_ACCION.md](DOCUMENTACION/PLAN_ACCION.md)
- Progreso consolidado: [DOCUMENTACION/progreso.md](DOCUMENTACION/progreso.md)
- Auditoria tecnica integral: [DOCUMENTACION/AUDITORIA_TECNICA_INTEGRAL.md](DOCUMENTACION/AUDITORIA_TECNICA_INTEGRAL.md)
- Runbook de despliegue: [DOCUMENTACION/GUIA_DESPLIEGUE_NUEVO_SERVIDOR.md](DOCUMENTACION/GUIA_DESPLIEGUE_NUEVO_SERVIDOR.md)

## Estado del proyecto

El estado funcional y tecnico vigente se mantiene en:

- [DOCUMENTACION/RESUMEN.md](DOCUMENTACION/RESUMEN.md)
- [DOCUMENTACION/RESUMEN_EJECUTIVO_DG_SPRINT_Y_HISTORICO_12-03-2026.md](DOCUMENTACION/RESUMEN_EJECUTIVO_DG_SPRINT_Y_HISTORICO_12-03-2026.md)

## Licencia

Uso interno. Repositorio de licencia propietaria.

# Seed Dashboard - Repoblado DB

## Objetivo
Agrupar y estandarizar el flujo de repoblado del dashboard para pruebas de performance con datos cercanos a produccion.

## Estructura agrupada

### Comandos
- `src/Command/SeedDashboard/SeedDashboardStep1SessionsCommand.php`
- `src/Command/SeedDashboard/SeedDashboardStep2TransactionsCommand.php`
- `src/Command/SeedDashboard/SeedDashboardStep3ReservationsCommand.php`
- `src/Command/SeedDashboard/SeedDashboardRollbackCommand.php`

### Estado / IDs (json)
- `seed_dashboard/data/sessions.jsonl`
- `seed_dashboard/data/transactions.jsonl`
- `seed_dashboard/data/reservations.jsonl`
- `seed_dashboard/data/users.json`
- `seed_dashboard/data/meta.json`
- `seed_dashboard/data/.gitignore`

## Flujo recomendado

1. Generar sesiones

```bash
php bin/console app:seed:dashboard:step1-sessions --reset-file --no-debug
```

2. Generar transacciones para usuarios activos

```bash
php bin/console app:seed:dashboard:step2-transactions --reset-file --users=100 --no-debug
```

3. Generar reservas y asistencias

```bash
php bin/console app:seed:dashboard:step3-reservations --no-debug
```

4. Medir dashboard

```bash
php bin/console app:benchmark:dashboard --no-debug
```

## Rollback

```bash
php bin/console app:seed:dashboard:rollback --no-debug
```

El rollback borra por IDs usando los archivos json/jsonl del directorio `seed_dashboard/data`.

## Notas
- Los comandos se ejecutan por etapas para evitar picos de memoria.
- Los archivos json/jsonl permiten reanudar, auditar y revertir rapidamente.
- Mas adelante se puede parametrizar periodos cortos con `--from` y `--users` para corridas frecuentes.

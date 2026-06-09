# Instrucciones del proyecto PBStudio81

## Ejecución de comandos (Bash)

- **No ejecutes comandos por iniciativa propia.** Solo ejecuta un comando de terminal (build, tests, migraciones, instalaciones, scripts, etc.) cuando el usuario lo pida explícitamente. Para entender o modificar el código, se evaluaria si es mas ahorrativo ejecutar comandos o hacerlo inline.
- Antes de proponer un comando, explica brevemente qué hace y por qué es necesario.

## Manejo de comandos denegados

- Si el usuario **niega** un comando (por ejemplo un `build`, un test o una instalación), **no detengas la tarea**: trátalo como un paso omitido y continúa con el resto del trabajo usando las herramientas disponibles (lectura/edición de archivos).
- No vuelvas a pedir el mismo comando que ya fue denegado.
- Al terminar, indica claramente qué pasos quedaron pendientes por la denegación (p. ej. "no se ejecutó el build / no se corrieron los tests"), para que el usuario los haga manualmente si quiere.

# Pruebas pendientes en Windows

El desarrollo se hizo en Linux, sin Windows, sin BlueStacks, sin Android SDK y sin claves reales.
Lo siguiente **no está verificado** y debe probarse en la PC dedicada antes de operar.
Marque cada punto y guarde `dxagent-cli.exe doctor` y los logs como evidencia.

## A. Instalación y Windows
- [ ] `build.ps1` compila y `pytest` pasa en Windows (también lo ejecuta GitHub Actions en `windows-latest`).
- [ ] El instalador instala, crea accesos directos y la clave `HKCU\…\Run`.
- [ ] Inicio automático tras reiniciar la PC: la interfaz aparece en la bandeja y lanza el servicio.
- [ ] Cerrar la ventana → el agente sigue procesando (icono en la bandeja).
- [ ] Matar `DiamantesExpressAgent.exe service` desde el Administrador de tareas → la interfaz lo relanza en ≤ 30 s.
- [ ] Secretos guardados aparecen en *Administrador de credenciales → Credenciales de Windows* (`DiamantesExpressAgent`)
      y **no** en `settings.json` ni en los logs.
- [ ] "Impedir suspensión" evita la suspensión con el agente activo.

## B. BlueStacks y ADB (las heurísticas de detección deben confirmarse)
- [ ] `doctor` detecta la carpeta de instalación (registro `HKLM\SOFTWARE\BlueStacks_nxt` o rutas por defecto).
- [ ] Se encuentra `bluestacks.conf` y se listan las instancias con su puerto ADB.
      Si no, configure `bluestacks_conf_path`/`adb_serial` y reporte la ruta real para corregir la heurística.
- [ ] Con BlueStacks cerrado, `dxagent-cli.exe screenshot` lo abre con `--instance` y obtiene la captura.
- [ ] Resolución y orientación correctas en vertical y horizontal.
- [ ] `current_package` identifica la app en primer plano.
- [ ] Desactivar ADB en BlueStacks durante la operación → estado *Degradado*, sin pedidos reclamados.
- [ ] Congelamiento: detectar con `IsHungAppWindow`/ADB y verificar que el reinicio controlado funciona y que se
      **bloquea** si hay un pedido en `committing`/`verifying`.

## C. App de simulación en BlueStacks
- [ ] Compilar e instalar `android-sim` (Android Studio).
- [ ] `uiautomator dump` expone los `resource-id` esperados (`btn_start`, `field_player_id`, …).
- [ ] Modo SIMULACIÓN + `device_backend=adb`: 20 pedidos con el backend simulado → todos `completed`.
- [ ] `--es outcome failure` → `failed/DELIVERY_REJECTED`; `--es outcome uncertain` → `needs_review`.
- [ ] Modo SUPERVISADO: la aprobación aparece en el panel y registra el nombre del operador.

## D. OpenAI
- [ ] *Verificar clave* funciona.
- [ ] `dxagent-cli.exe analyze-screen --image prueba.png` devuelve un `ScreenAnalysis` válido y registra uso real
      (`input_tokens`, `cached_input_tokens`, `output_tokens`, `request_id`). Comparar con el panel de uso de OpenAI.
- [ ] Verificar los precios de `pricing.yaml` en la página oficial y completar `verified_at`.
- [ ] Con `device_backend=adb` y la jerarquía UI deshabilitada (o en el juego), la IA localiza elementos con la
      confianza mínima configurada; si no, el flujo se detiene con `SCREEN_UNRECOGNIZED`.

## E. Horizons
- [ ] `doctor` muestra el contrato oficial con 5 operaciones y `audit_log.integro=true`.
- [ ] `check-api` con el dominio publicado y un token `dxa_` real devuelve el modo del servidor (no reclama tareas).
- [ ] Servidor en `simulation` + *Automatización → Simular*: el agente reclama, ejecuta en la app ficticia y la tarea
      queda `completed` con `usage` y `evidence` visibles en el panel.
- [ ] Servidor en `off` o pausado: el agente muestra "En espera del servidor" y no reclama.
- [ ] Dos agentes con tokens distintos: una tarea la reclama uno solo.
- [ ] Desconectar Internet tras reclamar: no se envía nada; al volver, el resultado se sincroniza sin duplicarse.
- [ ] Reiniciar la PC durante una tarea: tras el arranque, la tarea queda en `manual_review` (`uncertain`) si se
      había pulsado "enviar", o vuelve a `pending` al vencer el lease si no.
- [ ] Revocar el token desde el panel → el agente pasa a *Error de credenciales*.
- [ ] Confirmar con Horizons si `supervised` debe permitir la entrega real con aprobación humana local
      (ver la tabla de modos en HORIZONS.md).

## F. Free Fire (FASE 9, solo tras A–E)
- [ ] Confirmar que los Términos de Servicio permiten la operación asistida en la cuenta usada.
- [ ] Completar la plantilla `freefire_gift_supervised.template.yaml` con valores observados (paquete instalado,
      descripciones de pantallas, regla del ID, catálogo de regalos). Ningún valor se debe suponer.
- [ ] Ejecutar solo en **SUPERVISADO**, con aprobación humana en cada envío, durante el período de validación.
- [ ] PRODUCCIÓN AUTORIZADA únicamente con `authorization.authorized=true`, referencia y fecha de validación,
      y DiamantesExpress en modo `production` (confirmado por el SUPER_ADMIN).

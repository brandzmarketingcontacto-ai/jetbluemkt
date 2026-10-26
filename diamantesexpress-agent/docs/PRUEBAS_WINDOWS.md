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
- [ ] Obtener el OpenAPI real y cargarlo (o configurar `route_overrides`). `doctor` debe mostrar 12 operaciones.
- [ ] Emparejamiento, heartbeat, reclamación atómica (dos agentes, un solo ganador), progreso, consumo
      deduplicado por `record_id`, evidencias, cierre y liberación.
- [ ] Revocar el token desde el panel → el agente pasa a *Error de credenciales*.

## F. Free Fire (FASE 9, solo tras A–E)
- [ ] Confirmar que los Términos de Servicio permiten la operación asistida en la cuenta usada.
- [ ] Completar la plantilla `freefire_gift_supervised.template.yaml` con valores observados (paquete instalado,
      descripciones de pantallas, regla del ID, catálogo de regalos). Ningún valor se debe suponer.
- [ ] Ejecutar solo en **SUPERVISADO**, con aprobación humana en cada envío, durante el período de validación.
- [ ] PRODUCCIÓN AUTORIZADA únicamente con `authorization.authorized=true`, referencia y fecha de validación,
      y `production_authorized=true` habilitado en Horizons para ese agente.

# DiamantesExpress Agent

Aplicación nativa para Windows 11 que recibe pedidos de la tienda **DiamantesExpress** (Hostinger
Horizons), controla un emulador **BlueStacks 5** por **ADB**, usa **OpenAI** (Responses API) para
interpretar pantallas cuando hace falta y reporta al backend el resultado verificado de cada entrega
junto con los tokens y el costo consumidos.

- Interfaz nativa **PySide6** (no web), oscura y minimalista, con bandeja del sistema.
- **Servicio de ejecución independiente** de la ventana: cerrar la interfaz no detiene el agente.
- Tres modos: **SIMULACIÓN**, **SUPERVISADO** (aprobación humana del envío) y **PRODUCCIÓN AUTORIZADA**
  (bloqueada salvo autorización validada en el flujo **y** en el backend).
- Persistencia **SQLite** con checkpoints: una operación transaccional nunca se repite.
- Ejecutable **.exe** con PyInstaller e instalador Inno Setup.

## Estado de la versión 0.1.0

| Capacidad | Estado |
|---|---|
| Interfaz, configuración, secretos en Windows Credential Manager | Implementado. UI probada en modo offscreen (Linux). Credential Manager: pendiente de prueba en Windows. |
| Cliente API Horizons (contrato OpenAPI **provisional**) | Implementado y probado contra el backend simulado (en memoria y por HTTP real). **Pendiente**: contrato real de Horizons. |
| Emparejamiento, heartbeat, reclamación atómica, progreso, consumo, evidencias, cierre | Implementado y probado contra el backend simulado. |
| BlueStacks: detección, instancias, inicio, espera de arranque, reinicio seguro | Implementado. Probado con instalación y procesos simulados. **Pendiente**: validar en Windows real. |
| ADB: conexión, captura, resolución/orientación, app activa, toques, texto, apertura de apps autorizadas | Implementado. Probado con un `adb` falso (argumentos y parseo). **Pendiente**: BlueStacks real. |
| OpenAI Responses API con salida estructurada (Pydantic) y registro de uso real | Implementado. Probado con un cliente falso que imita `responses.parse`. **Pendiente**: llamada real con su clave. |
| Motor de flujo, política, aprobaciones, verificación independiente, recuperación, watchdog | Implementado y probado (47 pruebas, incluidas las 16 exigidas). |
| App Android de simulación | Simulador interno probado. Código fuente APK en `android-sim/` **no compilado** aquí (sin Android SDK). |
| Flujo supervisado de Free Fire | **Plantilla** con valores `<VERIFICAR>`: el motor se niega a cargarla hasta completarla y validarla. |
| EXE de Windows | Spec y scripts listos; bundle verificado en Linux. El `.exe` se compila en Windows (`build.ps1`) o en GitHub Actions (`windows-latest`). |

Nada de lo marcado como pendiente se declara funcionando: ver [docs/PRUEBAS_WINDOWS.md](docs/PRUEBAS_WINDOWS.md).

## Inicio rápido (desarrollo)

```powershell
cd diamantesexpress-agent
python -m venv .venv; .\.venv\Scripts\Activate.ps1
pip install -r requirements-dev.txt
$env:PYTHONPATH = "src"
python -m pytest -q                       # 47 pruebas, ninguna hace envíos reales
python -m dxagent simulate --orders 100   # simulación integral aislada
python -m dxagent doctor                  # diagnóstico (no consume tokens)
python -m dxagent ui                      # interfaz (inicia el servicio)
```

Probar la aplicación completa sin Horizons, con el backend simulado por HTTP:

```powershell
python -m dxagent mock-backend --jobs 10          # en una terminal
$env:DIAMANTESEXPRESS_API_URL = "http://127.0.0.1:8787/api/agent/v1"
$env:DXAGENT_ALLOW_INSECURE_HTTP_LOCALHOST = "true"
$env:DIAMANTESEXPRESS_AGENT_ID = "agent-test"
$env:DIAMANTESEXPRESS_AGENT_TOKEN = "test-token-123456"
python -m dxagent ui
```

## Compilar el ejecutable

```powershell
.\packaging\build.ps1              # pruebas + dist\DiamantesExpressAgent\DiamantesExpressAgent.exe
.\packaging\build.ps1 -Installer   # además dist\installer\DiamantesExpressAgent-Setup.exe (Inno Setup 6)
```

Se generan dos ejecutables en la misma carpeta: `DiamantesExpressAgent.exe` (ventana y servicio) y
`dxagent-cli.exe` (comandos de consola: `doctor`, `simulate`, `pair`, `set-secret`, `screenshot`,
`analyze-screen`, `emergency-stop`…).

## Documentación

- [Instalación en la PC dedicada](docs/INSTALACION.md)
- [Manual de conexión con Hostinger Horizons](docs/HORIZONS.md)
- [Arquitectura](docs/ARQUITECTURA.md)
- [Sistema de logs](docs/LOGS.md)
- [Guía de recuperación](docs/RECUPERACION.md)
- [Pruebas pendientes en Windows](docs/PRUEBAS_WINDOWS.md)
- [App Android de simulación](android-sim/README.md)

## Principios de seguridad

- La IA solo **describe** pantallas y **sugiere**; el motor ejecuta únicamente pasos definidos en el flujo,
  tras validarlos con `ExecutionPolicy` (lista permitida, límites, coordenadas, texto seguro, paquetes autorizados).
- Destinatario y producto se verifican en pantalla **antes** del envío; el resultado se verifica con evidencia
  **después**. Un pedido nunca se marca completado solo por haber pulsado un botón.
- El paso transaccional se ejecuta una sola vez (checkpoint durable previo). Ante cualquier duda: `needs_review`.
- El backend solo puede pausar/reanudar el agente; no existe control remoto arbitrario de la PC.
- Secretos en el Administrador de credenciales de Windows; redacción automática en logs; solo HTTPS.
- No se modifican archivos, memoria ni protecciones de aplicaciones externas. Sin mecanismos de evasión.

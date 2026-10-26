# Arquitectura

```
┌──────────────── Sesión de usuario de Windows (escritorio activo) ────────────────┐
│                                                                                  │
│  DiamantesExpressAgent.exe ui          DiamantesExpressAgent.exe service         │
│  ┌─────────────────────┐   SQLite WAL  ┌──────────────────────────────────────┐  │
│  │ DesktopUI (PySide6) │◄─ estado ────►│ AgentService (asyncio)               │  │
│  │  - lee en un QThread│── comandos ──►│  control · heartbeat · sync · work   │  │
│  │  - bandeja          │  aprobaciones │  · watchdog                          │  │
│  │  - relanza servicio │               │                                      │  │
│  └─────────────────────┘               │ JobQueueManager ─► HorizonsApiClient ─┼──► HTTPS DiamantesExpress
│                                        │ WorkflowEngine                       │  │
│                                        │  ├ ExecutionPolicy / ApprovalManager │  │
│                                        │  ├ ScreenObserver (jerarquía UI →    │  │
│                                        │  │   coordenadas calibradas → IA)    │  │
│                                        │  ├ OpenAIVisionAgent ─ UsageTracker ─┼──► OpenAI Responses API
│                                        │  └ EvidenceManager                   │  │
│                                        │ RecoveryManager · Watchdog           │  │
│                                        │ BlueStacksManager ─ ADBController ───┼──► BlueStacks 5 (HD-Player)
│                                        └──────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────────────┘
```

## Componentes (carpeta `src/dxagent`)

| Componente | Archivo |
|---|---|
| DesktopUI | `ui/main_window.py`, `ui/settings_page.py`, `ui/data_worker.py`, `ui/theme.py` |
| AgentService | `service/agent.py`, `service/bootstrap.py` |
| HorizonsApiClient | `api/client.py`, `api/contract.py`, `api/errors.py` (simulador: `api/mock_backend.py`) |
| JobQueueManager | `service/job_queue.py` (+ `service/sync.py`: outbox) |
| BlueStacksManager | `android/bluestacks.py` |
| ADBController | `android/adb.py`, `android/device.py` (simulador: `android/mock_device.py`) |
| AndroidAppManager | `android/app_manager.py` |
| OpenAIVisionAgent | `ai/vision.py`, `ai/schemas.py` |
| WorkflowEngine | `engine/workflow.py`, `engine/observer.py`, `engine/workflow_def.py` |
| ExecutionPolicy | `engine/policy.py` |
| ApprovalManager | `engine/approval.py` |
| UsageTracker | `ai/usage.py`, `ai/pricing.py` |
| EvidenceManager | `engine/evidence.py` |
| RecoveryManager | `engine/recovery.py` |
| Watchdog | `service/watchdog.py` |
| LocalDatabase | `database.py` |
| SettingsManager | `settings.py` |
| Windows | `winplatform.py` (inicio automático, suspensión, instancia única, sesión interactiva) |

## Ciclo de un pedido

1. `listPendingJobs` → validación Pydantic → descarte de duplicados (trabajo o pedido ya conocido).
2. `claimJob` atómico → bloqueo local por **instancia Android** y por **cuenta del juego**.
3. Validación contra las reglas del flujo (formato de ID, producto configurado).
4. Pasos previos (abrir app, navegar, escribir destinatario, elegir producto). Reintentos acotados.
5. `verify_fields`: lo que se ve en pantalla debe coincidir con el pedido.
6. `getJob`: el pedido sigue asignado y no fue cancelado.
7. Aprobación según el modo (humana en SUPERVISADO, registrada con nombre de operador y usuario de Windows).
8. Checkpoint durable `commit_started` → **un único toque** → `commit_sent`.
9. `verify_result`: pantalla de éxito + destinatario/producto del resultado + referencia; IA opcional
   como segunda verificación independiente. Evidencias guardadas y enviadas.
10. Resultado al outbox → sincronización idempotente con Horizons.

## Uso de OpenAI solo cuando aporta valor

BlueStacks, ADB, apertura de apps y comprobaciones técnicas no usan IA. En cada pantalla el observador intenta:
1) jerarquía UI (`uiautomator`, gratis y exacta), 2) coordenadas calibradas y validadas para la resolución
actual, 3) **solo entonces** OpenAI. Las capturas en blanco no se envían a la IA. Cada solicitud pasa antes
por el control de presupuesto (por pedido, diario y número de solicitudes).

## Escalabilidad futura

- Varias instancias: un `AgentService` por instancia (`BLUESTACKS_INSTANCE`, `account_key` y
  `DXAGENT_HOME` distintos) o un supervisor que cree varios `WorkflowEngine`; los bloqueos por instancia y
  por cuenta ya existen.
- Varios PCs: cada agente tiene su `agent_id` y token; la reclamación atómica del backend reparte el trabajo.
- Nuevos flujos: archivos YAML en `config/workflows/` sin cambiar código.

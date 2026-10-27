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
│                                        │ Emulador ─ ADBController ────────────┼──► BlueStacks 5 o LDPlayer 9
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
| BlueStacksManager / LDPlayerManager | `android/bluestacks.py`, `android/ldplayer.py`; selección en `android/emulator.py` |
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
| LocalAuditLog | `audit.py` (`logs\\audit.jsonl`, encadenado por hash) |
| SettingsManager | `settings.py` |
| Windows | `winplatform.py` (inicio automático, suspensión, instancia única, sesión interactiva) |

## Ciclo de una tarea (contrato oficial de Horizons)

1. `POST /agent/heartbeat` → modo y pausa del servidor. Solo se reclama si son compatibles con el modo local.
2. `POST /agent/tasks/claim` (CAS + lease) → validación Pydantic → descarte de duplicados (tarea o pedido ya
   procesado: se reenvía el resultado, nunca se repite la entrega) → bloqueo local por instancia y cuenta.
3. Plan: `simulate=true` → flujo de simulación; tarea real → flujo real validado + modo efectivo.
4. Pasos previos (abrir app, navegar, escribir destinatario, elegir producto), con progreso `processing`
   (renueva el lease) y un vigilante que mantiene el lease durante las esperas.
5. `verify_fields`: lo que se ve en pantalla debe coincidir con la tarea.
6. Progreso en vivo justo antes de enviar: si Horizons responde 409, no se envía.
7. Aprobación según el modo (humana en SUPERVISADO, con nombre del operador).
8. Checkpoint durable `commit_started` → **un único toque** → `commit_sent` → progreso `verifying`.
9. `verify_result`: pantalla de éxito + datos del resultado + referencia (→ `giftCode`); IA opcional como segunda
   verificación. Evidencias en disco; sus hashes en el resultado.
10. `POST /agent/tasks/{id}/result` con `usage` real de OpenAI, desde el outbox (idempotente, FIFO, con backoff).
11. Al arrancar: recuperación local + `GET /agent/tasks/current` para reconciliar.

## Uso de OpenAI solo cuando aporta valor

El arranque del emulador, ADB, apertura de apps y comprobaciones técnicas no usan IA. En cada pantalla el observador intenta:
1) jerarquía UI (`uiautomator`, gratis y exacta), 2) coordenadas calibradas y validadas para la resolución
actual, 3) **solo entonces** OpenAI. Las capturas en blanco no se envían a la IA. Cada solicitud pasa antes
por el control de presupuesto (por pedido, diario y número de solicitudes).

## Escalabilidad futura

- Varias instancias: un `AgentService` por instancia (`BLUESTACKS_INSTANCE`, `account_key` y
  `DXAGENT_HOME` distintos) o un supervisor que cree varios `WorkflowEngine`; los bloqueos por instancia y
  por cuenta ya existen.
- Varios PCs: cada agente tiene su `agent_id` y token; la reclamación atómica del backend reparte el trabajo.
- Nuevos flujos: archivos YAML en `config/workflows/` sin cambiar código.

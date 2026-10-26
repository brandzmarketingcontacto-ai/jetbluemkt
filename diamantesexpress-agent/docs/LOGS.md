# Sistema de logs

## Ubicación

`%LOCALAPPDATA%\DiamantesExpressAgent\logs\`

| Archivo | Proceso |
|---|---|
| `service.jsonl` | Servicio de ejecución (el más importante) |
| `ui.jsonl` | Interfaz gráfica |
| `simulate.jsonl` | Comando `simulate` |

Rotación: 5 MB por archivo, 10 copias (`service.jsonl.1` … `.10`).

## Formato

Una línea JSON por evento:

```json
{"ts": "2026-10-26T21:56:22.481+00:00", "level": "INFO", "logger": "dxagent.service.agent",
 "msg": "Uso OpenAI registrado", "model": "gpt-4.1-mini", "total_tokens": 1350, "cost_usd": 0.00066, "job_id": "job-1"}
```

Campos fijos: `ts` (UTC), `level`, `logger`, `msg`; campos adicionales según el evento; `exc` con la traza en errores.

## Protección de secretos

Antes de escribirse, cada línea pasa por un filtro que reemplaza por `***`:
- los secretos cargados (token del agente, clave OpenAI),
- patrones `sk-…`, `Bearer …`, `token=`, `api_key=`, `password=`, `authorization:`.

## Consultar

- Interfaz → pestaña **Registros**: filtro por archivo, nivel y texto (número de pedido, código de error).
- PowerShell: `Get-Content service.jsonl -Tail 50 -Wait`
- Filtrar errores: `Select-String '"level": "ERROR"' service.jsonl`

## Otras fuentes de diagnóstico

- **Actividad reciente** (panel) y tabla `events` de `agent.db`: eventos de negocio en español.
- **Evidencias**: `evidence\<job_id>\` con capturas PNG, jerarquía UI XML y resúmenes, con SHA-256 en `agent.db`.
- `dxagent-cli.exe doctor`: estado del entorno en JSON.

## Códigos de error frecuentes

| Código | Significado |
|---|---|
| `SCREEN_UNRECOGNIZED` | Ni la jerarquía UI ni la IA identificaron la pantalla. Flujo detenido. |
| `RECIPIENT_NOT_FOUND` | La app indicó que el destinatario no existe. |
| `DATA_MISMATCH` | Lo visible antes del envío no coincide con el pedido. No se envió. |
| `APPROVAL_REJECTED` / `APPROVAL_EXPIRED` | Decisión humana negativa / sin respuesta. No se envió. |
| `BACKEND_UNREACHABLE` | No se pudo revalidar el pedido antes del envío. No se envió. |
| `COMMIT_UNCERTAIN`, `RESULT_UNCERTAIN`, `RESTART_DURING_COMMIT` | Resultado incierto → **revisión manual**. |
| `DAILY_BUDGET`, `ORDER_BUDGET`, `ORDER_REQUESTS`, `PRICING_MISSING` | Límites de IA. |
| `MAX_ACTIONS`, `MAX_DURATION`, `MAX_CONSECUTIVE_ERRORS` | Límites de ejecución. |
| `EMERGENCY_STOP` | Botón de emergencia. |

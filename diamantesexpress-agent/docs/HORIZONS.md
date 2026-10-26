# Manual de conexión con Hostinger Horizons

El agente **no** accede a la base de datos de Horizons. Todo pasa por la API segura de DiamantesExpress.
El contrato que espera el agente está en
`src/dxagent/resources/openapi/horizons-agent-api.provisional.yaml` (OpenAPI 3.1). Es una **propuesta**:
ninguna de esas rutas existe todavía. Entréguelo a Horizons como especificación o pídales su OpenAPI real.

## Cómo se adapta el agente al contrato real

El cliente resuelve cada operación por su `operationId`, no por la ruta. Opciones:

1. **Horizons implementa el contrato provisional** → no hay que cambiar nada.
2. **Horizons publica su propio OpenAPI con los mismos `operationId`** → copie el archivo a
   `%LOCALAPPDATA%\DiamantesExpressAgent\config\openapi\horizons-agent-api.provisional.yaml`
   o indique su ruta en *Configuración → Contrato OpenAPI*.
3. **Rutas distintas** → `route_overrides` en `settings.json` (o `DXAGENT_ROUTE_OVERRIDES`):
   ```json
   {"claimJob": "POST /tareas/{job_id}/reclamar", "completeJob": "PUT /tareas/{job_id}/resultado"}
   ```
Si los formatos de cuerpo difieren, el ajuste se hace en `src/dxagent/api/client.py` (un método por operación).

## Operaciones requeridas

| operationId | Uso | Requisitos para Horizons |
|---|---|---|
| `pairAgent` | Emparejamiento con código de un solo uso | Devuelve `agent_id` y `agent_token`. El código expira. |
| `sendHeartbeat` | Estado cada N s | Upsert por agente. Puede responder `requested_state: paused/running` (nada más). |
| `getAgentConfig` | Configuración remota | `production_authorized` (por defecto **false**). |
| `listPendingJobs` | Consulta de pedidos | Solo pedidos **pagados** y listos para entrega. |
| `claimJob` | Reclamación **atómica** | Un único agente gana (`UPDATE … WHERE status='pending'`); el resto recibe 409. Idempotente por `Idempotency-Key`. |
| `getJob` | Revalidación antes del envío | Si el pedido fue cancelado/reembolsado, `status` ≠ `claimed`. |
| `reportProgress` | Progreso por paso | Idempotente. |
| `reportUsage` | Consumo OpenAI | **Deduplicar por `record_id`**. |
| `uploadEvidence` | Capturas/jerarquía UI | Multipart: `metadata` (JSON) + `file`. |
| `completeJob` | Resultado final | `completed` / `failed` / `needs_review`. Solo `completed` lleva verificación exitosa. 409 si el trabajo ya no es del agente. |
| `releaseJob` | Devolver sin ejecutar | Vuelve a `pending`. Siempre con `pre_commit` implícito: no se entregó nada. |
| `reportAlert` | Alertas | Severidad `info/warning/critical`. Mostrar en el panel de superadministrador. |

Todas (salvo `pairAgent`) usan `Authorization: Bearer <token>`. Un 401/403 deja al agente en
*Error de credenciales* hasta que se vuelva a emparejar (revocación inmediata desde el panel).

## Semántica de estados que Horizons debe respetar

- `needs_review` significa **"puede haberse entregado"**: no reintentar ni reembolsar automáticamente;
  un humano revisa la evidencia.
- `failed` con `pre_commit=true`: no se ejecutó el envío (datos inválidos, destinatario inexistente, rechazo humano).
- `failed` con `pre_commit=false` y `error_code=DELIVERY_REJECTED`: la app informó rechazo explícito.
- `released`: no se ejecutó nada; el pedido puede reasignarse.

## Seguridad recomendada en Horizons

- Token por agente, revocable, con expiración; almacenar solo su hash.
- La clave de OpenAI **nunca** se guarda ni se expone en Horizons: vive solo en la PC del agente.
- Limitar la frecuencia de `claimJob` y registrar IP y versión del agente.
- Validar `Idempotency-Key` durante al menos 7 días.

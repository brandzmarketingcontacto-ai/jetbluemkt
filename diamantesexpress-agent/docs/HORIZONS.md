# Conexión con DiamantesExpress (Hostinger Horizons)

El agente usa el **contrato oficial** publicado por Horizons (`GET /automation/openapi`, versión **1.1.0**; compatible con 1.0.0),
incluido en `src/dxagent/resources/openapi/diamantesexpress-agent-api.json`. El resumen del MVP está
en [horizons/MVP.md](horizons/MVP.md). El agente no accede a la base de datos de Horizons: todas las
llamadas son **HTTPS salientes** iniciadas por la PC (el sandbox de Hostinger no recibe conexiones entrantes).

## Configuración

| Variable | Valor |
|---|---|
| `DX_API_BASE_URL` | `https://<dominio-publicado>/hcgi/api` |
| `DX_AGENT_TOKEN` | `dxa_...` creado en **Automatización → Tokens del agente** (se muestra una sola vez) |
| `DX_AGENT_NAME` | Nombre de la PC enviado en el heartbeat, p. ej. `PC-WINDOWS-01` |
| `ADB_PATH` / `ADB_DEVICE_SERIAL` | Ruta de adb y serial (p. ej. `127.0.0.1:5555` o `emulator-5554`) |
| `AGENT_POLL_SECONDS` / `AGENT_HEARTBEAT_SECONDS` | 10 / 30 |
| `AGENT_MODE` | `simulation` \| `supervised` \| `production` (modo **local**; el servidor manda) |
| `OPENAI_API_KEY` | Solo en la PC; nunca en Horizons |

El token se guarda en el **Administrador de credenciales de Windows** (DPAPI) desde *Configuración → Claves y
secretos* o con `dxagent-cli.exe set-secret agent-token`. No hay emparejamiento por código: el token se pega una vez.
Para comprobar la conexión sin reclamar tareas: `dxagent-cli.exe check-api` o el botón *Probar conexión*.

## Rutas que usa el agente

| Método | Ruta | Uso en el agente |
|---|---|---|
| POST | `/agent/heartbeat` | Cada `AGENT_HEARTBEAT_SECONDS`. Envía `hostname` y `appVersion`; lee `mode`, `paused`, `accepted`, `leaseSeconds`, `maxAttempts`, `pollAfterSeconds`. **v1.1:** envía `diamondBalance` (leído en la pantalla del juego) y lee `friendRetryMinutes`, `friendWaitMaxHours`, `senderAccountName/Id` y `balanceThreshold`. |
| POST | `/agent/tasks/claim` | Solo si el modo del servidor es compatible con el local (ver abajo). `task=null` + `reason` (`automation_off`, `paused`, `spend_limit_reached`, `empty`, **`insufficient_balance`** con `needed`/`balance`) se muestra en la interfaz. Con `insufficient_balance` el agente queda «En espera del servidor» y lanza una alerta crítica. |
| POST | `/agent/tasks/{id}/progress` | `processing`/`verifying` por paso; renueva el lease. Un vigilante renueva el lease mientras se espera una aprobación. **Antes de pulsar "enviar"** se hace una llamada en vivo: si responde 409 (tarea de otro agente o terminada) **no se envía**. |
| POST | `/agent/tasks/{id}/result` | `completed` \| `failed` \| `uncertain`, con `giftCode` (referencia leída en pantalla), `evidence` (hashes sha256 y metadatos, nunca imágenes) y `usage` (`prompt_tokens`, `completion_tokens`, `cached_tokens`, `cost_usd` reales). **v1.1:** `friendStatus` en la fase de amistad y `deliveredQuantity` en la de regalo (`completed` = cantidad pedida, `failed` = 0, `uncertain` = se omite). Reintentos idempotentes. |
| POST | `/agent/tasks/{id}/defer` | **v1.1.** Regalo a un jugador que aún no aceptó la amistad: `reason=not_friend_yet`, `retryAfterSeconds = friendRetryMinutes × 60`. La tarea vuelve a `waiting_friend` y **no consume** `maxAttempts`. Con un contrato 1.0.0 (sin esta ruta) la tarea se libera dejando vencer el lease. |
| GET | `/agent/tasks/current` | Al arrancar: tareas que el servidor nos atribuye. Si no hay registro local de una → `uncertain` (revisión manual). |

Las rutas `/automation/*` (JWT de SUPER_ADMIN) son de la consola y el agente **no** las usa.
Si Horizons cambia una ruta: `route_overrides` en `settings.json`, p. ej.
`{"claimTask": "POST /agent/v2/tasks/claim"}` (nombres: `heartbeat`, `claimTask`, `currentTasks`, `reportProgress`, `reportResult`, `deferTask`).

## Pedidos en dos fases (v1.1)

Free Fire solo deja regalar a jugadores de la lista de amigos de la cuenta emisora. Por eso Horizons crea, por cada
pedido pagado, una tarea `friend_request` y una o varias tareas `gift` (en `waiting_friend`). Si la cantidad supera
`maxPerTransaction`, el servidor la divide: 100 cajas con máximo 57 → `chunkIndex` 0 (57) y 1 (43).

| Fase | Flujo local | Resultado |
|---|---|---|
| `friend_request` | `friend_workflow_id` (simulación: `simulation_friend`) | `completed` + `friendStatus`: `request_sent`, `already_friends` (sin pulsar nada) o `player_not_found` (Horizons pasa el pedido a revisión) |
| `gift` | `workflow_id` (simulación: `simulation_gift`) | Si el jugador aún no es amigo → `/defer`. Si lo es → selecciona pestaña (`category`), objeto (`gameItemName` exacto), destinatario y cantidad con –/+; comprueba cantidad, costo total (`quantity × unitDiamondCost`) y saldo **antes** de enviar |

Reglas del agente:
- La clave de idempotencia local es `orderCode:phase:chunkIndex`: un mismo trozo nunca se envía dos veces.
- Una tarea aplazada (`deferred`) puede volver a reclamarse; una completada no.
- Si el saldo leído en pantalla no alcanza, la tarea termina `failed` `INSUFFICIENT_BALANCE` **sin enviar** y se alerta.
- `quantity > maxPerTransaction` se rechaza (`QUANTITY_EXCEEDS_MAX`): la división la hace el servidor, no el agente.
- Los valores vacíos (`"notBefore": ""`, `"friendStatus": ""`) se tratan como ausentes, igual que una tarea v1.0.

## Modo del servidor y modo local

El servidor manda; el modo local solo puede **restringir**.

| Modo del servidor | Local SIMULATION | Local SUPERVISED | Local AUTHORIZED_PRODUCTION |
|---|---|---|---|
| `off` / `paused` | no reclama | no reclama | no reclama |
| `simulation` (simulate=true) | app de simulación | app de simulación + aprobación local | app de simulación |
| `supervised` | no reclama | flujo real + **aprobación humana local** | flujo real + aprobación humana local |
| `production` | no reclama | flujo real + aprobación humana local | flujo real sin aprobación **solo** si el flujo tiene autorización validada |

- Una tarea con `simulate=true`, `simulated=true` o `mode=simulation` se ejecuta **siempre** con el flujo de
  simulación; su lista de paquetes autorizados contiene solo la app ficticia, así que el juego no se toca.
- Las tareas reales necesitan un flujo real validado (`workflow_id`). Hoy solo existe la **plantilla** de Free Fire
  con valores `<VERIFICAR>`, que el motor se niega a cargar. Por eso, mientras no se complete, el agente **no reclama**
  tareas en `supervised`/`production` y lo indica como "En espera del servidor".

## Cómo se cierra cada tarea

| Situación local | Qué se envía | Estado en Horizons |
|---|---|---|
| Entrega verificada en pantalla (destinatario y producto coinciden) | `completed` + `giftCode` | `completed` (`manual_review` en supervised) |
| Datos inválidos, destinatario inexistente o rechazo del operador (nada enviado) | `failed` (`evidence.preCommit=true`) | `failed` |
| La app informó rechazo explícito tras enviar | `failed` `DELIVERY_REJECTED` | `failed` |
| Cualquier duda tras pulsar "enviar" (sin confirmación, error de red, reinicio) | `uncertain` | **siempre** `manual_review` |
| Fallo de entorno antes de enviar (emulador, IA, pantalla desconocida, emergencia) | **nada**: se deja vencer el lease | vuelve a `pending` hasta `maxAttempts`, luego `manual_review` |

Una tarea ya ejecutada nunca se repite: si vuelve a entregarse (p. ej. se perdió la respuesta del resultado y venció
el lease), el agente **reenvía el resultado guardado** sin tocar el emulador.

## Limitaciones del contrato actual (documentadas por Horizons)

- No hay subida de capturas: las imágenes quedan en la PC (`evidence\<id>\`) y viajan solo sus hashes.
- No hay endpoint de alertas: las alertas se ven en la interfaz y en `logs\audit.jsonl`.
- No hay endpoint para liberar una tarea: se libera dejando vencer el lease.
- `POST /automation/simulate` aún no permite elegir `kind`, `quantity` ni `phase` (pedido a Horizons). Para probar
  pedidos completos en local: `dxagent-cli.exe mock-backend` y `dxagent-cli.exe simulate --orders 25`.
- El texto `x-unsupported` del OpenAPI 1.1.0 todavía dice «solo Pase Booyah»; el catálogo `agent_products` ya
  permite otros productos.
- El inicio de sesión de Garena en el emulador se hace a mano; el agente no gestiona esas credenciales.

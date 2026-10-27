# Prompt para Hostinger Horizons — API del agente v1.1 (productos, cantidades y amistad)

Copiar y pegar en Horizons tal cual.

---

Necesito ampliar la integración "Windows Delivery Agent" de DiamantesExpress (rutas `/hcgi/api/agent/*` y
`/automation/*`, contrato OpenAPI 1.0.0 ya publicado). El agente Windows ya está construido contra la v1.0.0, así que
**todos los cambios deben ser retrocompatibles**: no renombrar ni quitar campos ni rutas existentes; solo agregar campos
opcionales y rutas nuevas. Publicar el resultado como OpenAPI **1.1.0** en `GET /automation/openapi` y actualizar
`GET /automation/spec`.

## 1. Catálogo de productos que se entregan con el agente

Hoy solo se enruta «Pase Booyah» (`kind=pase_booyah`). Crear una colección **`agent_products`** editable por el
SUPER_ADMIN en el panel de Automatización, con estos campos:

- `kind` (único, minúsculas y guiones bajos), por ejemplo `pase_booyah`, `caja_tokens_evo_universales` o `token_escorpion`.
- `category`: `pase` | `caja` | `token` (es la pestaña de la sección de regalos del juego donde está el objeto).
- `gameItemName`: el texto **exacto** que muestra el juego (p. ej. «Pase Booyah Premium», «Caja de Tokens evo universales»).
- `unitDiamondCost`: diamantes que cuesta 1 unidad en el juego (p. ej. 300, 50, 10).
- `maxPerTransaction`: máximo de unidades por envío en el juego (p. ej. 1 para el pase, 57 para la caja).
- `active` (bool): interruptor reversible, igual que hoy `booyahAgent`.
- `requiresFriendship` (bool, por defecto `true`).

Cada producto de la tienda que se entrega con el agente se vincula a un `kind` y a una cantidad. Solo los productos con
`active=true` se enrutan al agente; el resto conserva su proveedor actual. `booyahAgent` sigue funcionando como hoy.

## 2. Cantidades y división por transacción

Al confirmarse un pago verificado (`fulfillPaidOrder` / webhooks), si la cantidad comprada supera `maxPerTransaction`,
el servidor **divide el pedido en varias tareas de regalo**, cada una con un máximo de `maxPerTransaction` unidades.
Ejemplo: 100 cajas con máximo 57 → dos tareas, de 57 y 43.

- Cada tarea lleva `chunkIndex` (1, 2, …) y `chunkCount`.
- La clave de idempotencia pasa a ser `orderCode + phase + chunkIndex`, para que un webhook repetido no duplique tareas.
- El pedido se marca entregado solo cuando **todas** sus tareas de regalo están `completed`. Si alguna termina en
  `manual_review` o `failed`, el pedido queda en revisión mostrando cuántas unidades se entregaron.

## 3. Amistad previa (dos fases por pedido)

El juego solo permite regalar a jugadores de la lista de amigos de la cuenta que envía. Cada pedido tendrá dos fases:

**Fase A — `friend_request`:** se crea al instante del pago (una por pedido). El agente envía la solicitud de amistad
al `playerId` y responde con `result.outcome = completed` y un campo nuevo `friendStatus`:
`request_sent` | `already_friends` | `player_not_found`. Con `player_not_found` el pedido pasa a `manual_review` y se avisa
al cliente para que corrija su ID.

**Fase B — `gift`** (una o varias tareas, según la sección 2): se crea junto con la fase A pero en un estado nuevo
**`waiting_friend`**, que **no se entrega en `/agent/tasks/claim`** hasta que sea elegible:
- Pasa a `pending` cuando la fase A respondió `already_friends`.
- Mientras tanto, se reintenta de forma programada: el servidor la entrega cada `friendRetryMinutes` (configurable,
  por defecto 10) para que el agente compruebe si ya son amigos.
- Nueva ruta **`POST /agent/tasks/{id}/defer`** con cuerpo `{ "reason": "not_friend_yet", "retryAfterSeconds": 600,
  "message": "..." }`. Solo la puede llamar el agente propietario. La tarea vuelve a `waiting_friend` con
  `notBefore = ahora + retryAfterSeconds`. **No cuenta como intento fallido** (no incrementa `attempts`).
- Tras `friendWaitMaxHours` (configurable, por defecto 48) sin amistad, la tarea pasa a `manual_review` con
  `errorCode = FRIEND_NOT_ACCEPTED` y se notifica al SUPER_ADMIN y al cliente.

Campos nuevos en el esquema `Task` (opcionales): `phase` (`friend_request` | `gift`), `quantity`, `chunkIndex`,
`chunkCount`, `category`, `gameItemName`, `unitDiamondCost`, `maxPerTransaction`, `notBefore`, `friendStatus`,
`deferCount`. En la fase A, `quantity` es null.

## 4. Comunicación con el cliente

- En la página de confirmación del pedido, por correo y en el estado del pedido mostrar:
  «Para recibir tu regalo, acepta en Free Fire la solicitud de amistad de **{nombre de la cuenta emisora}**
  (ID {id de la cuenta emisora})». El nombre y el ID de la cuenta emisora se configuran en Automatización.
- Estados visibles para el cliente: «Solicitud de amistad enviada», «Esperando que aceptes la amistad», «Enviando
  regalo», «Entregado», «En revisión».

## 5. Saldo de diamantes de la cuenta emisora

- En `POST /agent/heartbeat` aceptar el campo opcional `diamondBalance` (entero) y guardar el último valor con su fecha.
- En `POST /agent/tasks/claim`, si `diamondBalance < quantity × unitDiamondCost` de la siguiente tarea de regalo,
  no entregarla y responder `task=null` con `reason = "insufficient_balance"`.
- Mostrar el saldo en el panel y alertar al SUPER_ADMIN por debajo de un umbral configurable.

## 6. Resultado y evidencia

- En `POST /agent/tasks/{id}/result` aceptar el campo opcional `deliveredQuantity`. Un `completed` de la fase B requiere
  `deliveredQuantity == quantity`. Mantener la regla actual: `uncertain` va siempre a `manual_review`.
- Sin cambios en `usage` ni en `evidence` (solo hashes y metadatos, máx. 2 MB).

## 7. Panel de Automatización (SUPER_ADMIN)

- Editor del catálogo `agent_products`.
- En la lista de tareas mostrar fase, producto, cantidad, `chunkIndex/chunkCount`, `friendStatus`, `notBefore`,
  `deferCount` y el saldo de diamantes reportado.
- Configuración nueva: `friendRetryMinutes`, `friendWaitMaxHours`, nombre e ID de la cuenta emisora y umbral de saldo.
- Las acciones existentes (retry, approve, cancel, simulate) siguen funcionando. `POST /automation/simulate` debe
  permitir elegir `kind`, `quantity` y `phase` para probar todo en modo simulación.

## 8. Reglas que se mantienen

- Pago verificado antes de crear tareas; claim atómico con lease; `uncertain` siempre a revisión manual; production
  solo con confirmación del SUPER_ADMIN; tokens guardados solo como hash.
- Las tareas en `waiting_friend` y el nuevo `defer` no deben consumir `maxAttempts`.

Al terminar, entrégame el JSON de la OpenAPI 1.1.0, un ejemplo de respuesta de `/agent/tasks/claim` para cada fase y un
ejemplo de `defer`.

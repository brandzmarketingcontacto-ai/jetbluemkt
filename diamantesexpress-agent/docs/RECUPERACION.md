# Guía de recuperación

## Regla de oro

**Una entrega nunca se repite automáticamente.** Si el agente pierde el control en el momento del envío
(corte de energía, cierre, desconexión de ADB, emulador congelado), el pedido queda en **Revisión manual**
(`needs_review`) con la evidencia disponible. Un humano decide.

## Qué hace el agente solo

| Situación | Respuesta automática |
|---|---|
| Sin Internet / API caída | Estado *Degradado*; no reclama pedidos; reintenta con backoff exponencial (máx. 5 min); los reportes esperan en el outbox y se envían en orden al volver. |
| Token inválido o revocado | Estado *Error de credenciales*; deja de reclamar tareas hasta cargar un token nuevo. |
| Emulador cerrado (BlueStacks o LDPlayer) | Lo abre con la instancia configurada y espera el arranque (timeout configurable). |
| ADB desconectado | Reconecta por el puerto de la instancia; si no hay dispositivo, no reclama pedidos. |
| Emulador congelado | Tras el umbral configurado, reinicio controlado **solo si no hay operación incierta**. |
| App de prueba/juego cerrado | La abre (solo paquetes autorizados en el flujo). |
| Bucle interno colgado o caído | El watchdog lo reinicia. |
| Servicio caído | La interfaz lo relanza; opcionalmente también la tarea programada. |
| Reinicio durante un pedido | Antes del envío: se abandona y vuelve a `pending` al vencer el lease. Después: `uncertain` → `manual_review`. Al arrancar se compara con `/agent/tasks/current`. |
| Presupuesto IA agotado | Estado *Presupuesto IA agotado*; no toma pedidos hasta el día siguiente o hasta subir el límite. |

## Procedimientos manuales

### Pedido en "Revisión manual"
1. Historial → anote el pedido. Abra `evidence\<job_id>\` (capturas `resumen_previo`, `resultado…`).
2. Verifique en la cuenta del juego/app si el regalo llegó al destinatario.
3. Resuelva en el panel de Horizons (marcar entregado o reprogramar). El agente **no** lo reintentará.

### Parada de emergencia
- Botón **EMERGENCIA** o `dxagent-cli.exe emergency-stop`. Crea la bandera `EMERGENCY_STOP` que el motor
  revisa antes de cada acción. Para reanudar: *Configuración → Liberar parada de emergencia* y luego *Iniciar*.

### El agente no inicia
1. `dxagent-cli.exe doctor` y revise `service.jsonl`.
2. "El servicio ya está en ejecución": hay otro proceso; ciérrelo desde el Administrador de tareas o reinicie sesión.
3. "Flujo no disponible": el flujo tiene valores `<VERIFICAR>` o el modo no está permitido.

### Base de datos dañada
Detenga el agente, renombre `agent.db` (y `agent.db-wal`, `agent.db-shm`) y reinicie. Al arrancar, el agente
consulta `/agent/tasks/current`: toda tarea que el servidor le atribuya y que ya no conozca se reporta como
`uncertain` (revisión manual), nunca se re-ejecuta. Conserve `logs\audit.jsonl` como respaldo.

### Cambiar el token
*Automatización → Tokens del agente* → revocar el anterior → crear uno nuevo → *Configuración → Guardar token*.

### Tareas devueltas sin ejecutar
Si el agente no pudo empezar (emulador, IA, pantalla desconocida, emergencia), no reporta resultado: al vencer
el lease (`leaseSeconds`, 300 s por defecto) Horizons la vuelve a `pending`; tras `maxAttempts` pasa a
`manual_review`. Se ve en el Historial como *Devuelto*.

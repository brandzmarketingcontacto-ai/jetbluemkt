# Guía de pruebas paso a paso (Windows 11)

Todo en **modo simulación**: el bot usa la app ficticia *DX Gift Simulator*, nunca Free Fire, y no gasta
diamantes reales. Haga las etapas en orden; cada una confirma una pieza antes de pasar a la siguiente.

## Etapa 0 — Preparación (una vez)

1. Instale **BlueStacks 5** o **LDPlayer 9** (desde su web oficial) y ábralo una vez.
2. Active ADB en el emulador:
   - BlueStacks: *Configuración → Avanzado → Android Debug Bridge (ADB)* → activado.
   - LDPlayer: *Configuración → Otros ajustes → Depuración ADB → Abrir conexión local*.
3. Instale `DiamantesExpressAgent-Setup.exe` y elija el mismo emulador. (SmartScreen: *Más información →
   Ejecutar de todas formas*, el instalador no está firmado.)

En Android no hay que instalar nada más que la app de simulación (Etapa 2). Free Fire no se usa en estas pruebas.

## Etapa 1 — El programa funciona en esta PC (sin emulador ni internet)

En *Inicio → DiamantesExpress Agent → Diagnóstico*, o en una terminal dentro de la carpeta de instalación:

```
dxagent-cli.exe doctor
dxagent-cli.exe simulate --orders 10
```
Esperado: `simulate` termina con `"pedidos_incompletos": []` y `"duplicados": 0`.

## Etapa 2 — El bot controla el emulador

1. *Inicio → DiamantesExpress Agent → Instalar app de simulación en el emulador*. Debe terminar con
   `"instalada": true` y aparecer **DX Gift Simulator** en el emulador.
2. Abra **DiamantesExpress Agent → Configuración → Emulador Android y ADB**:
   - *Dispositivo*: **BlueStacks 5 por ADB** o **LDPlayer 9 por ADB**.
   - Pulse **Detectar instalación e instancias** y elija la instancia.
3. Captura de prueba: `dxagent-cli.exe screenshot --out prueba.png` → debe crear la imagen de la pantalla.

Si algo falla, guarde la salida de `doctor` y la carpeta de registros (*Registros → Abrir carpeta*).

## Etapa 3 — Pedido completo con el servidor simulado local (sin Horizons)

1. Abra una terminal en la carpeta de instalación y deje corriendo:
   ```
   dxagent-cli.exe mock-backend --jobs 2
   ```
   Crea 2 pedidos de dos fases (amistad + regalo). Muestra la URL `http://127.0.0.1:8787/hcgi/api` y el token.
2. En **Configuración → Conexión**: pegue esa URL, marque **Permitir HTTP solo hacia esta PC** y **Guardar**.
   En **Claves y secretos** pegue el token `dxa_test_token_123456` → **Guardar token**.
3. **Probar conexión** → debe responder con modo `simulation`.
4. Modo de operación: **Simulación**. Pulse **Iniciar** en el panel.
5. Observe en el emulador: el bot abre la app, va a *Agregar amigo*, busca el ID y envía la solicitud
   (Historial: fase *Amistad*, `request_sent`).
6. El regalo queda *Aplazado (esperando amistad)*. En la app del emulador pulse
   **[Prueba] El cliente acepta las solicitudes** (hace de cliente).
7. En ~1 minuto el bot reintenta: elige pestaña, objeto, destinatario y cantidad con –/+, y envía.
   Las cajas de 100 se entregan en dos envíos (57 + 43). El historial muestra *Completado* y `57/57`, `43/43`.
8. Pruebe también **EMERGENCIA** (detiene todo al instante) y **Pausar**.

Al terminar: Ctrl+C en la terminal del servidor simulado, desmarque la casilla de HTTP y vuelva a la URL real.

## Etapa 4 — Modo supervisado (aprobación humana)

Igual que la Etapa 3, pero con *Modo de operación* = **Supervisado**: antes de cada envío aparece en el panel
la tarjeta *Aprobación requerida* con destinatario, producto, cantidad y costo. Pruebe **Aprobar envío** y
**Rechazar** (rechazado = no se envía nada).

## Etapa 5 — Conexión con Horizons (tienda real, tareas simuladas)

1. En Horizons: *Automatización* → modo **simulation**; cree un token del agente (`dxa_…`, se muestra una vez);
   configure nombre e ID de la cuenta emisora.
2. En el programa: URL `https://<su-dominio>/hcgi/api`, **desmarque** la casilla de HTTP local, pegue el token y
   **Probar conexión**.
3. En Horizons cree una tarea con *Simular*. El bot la reclama, la ejecuta en la app ficticia y Horizons muestra
   el resultado con consumo (`usage`) y evidencia (hashes).
   Nota: `/automation/simulate` de Horizons aún no permite elegir producto, cantidad ni fase.

## Etapa 6 — OpenAI (opcional en simulación)

*Configuración → Claves y secretos → Clave OpenAI* → **Guardar** → **Verificar clave**. Para probar la lectura
de pantalla con IA: `dxagent-cli.exe analyze-screen --image prueba.png`; el consumo aparece en *Consumo IA*.

## Etapa 7 — Free Fire (después de todo lo anterior)

Requiere completar las plantillas `freefire_*_supervised.template.yaml` con datos observados en su PC
(paquete, pantallas, textos), la cuenta emisora abierta a mano en el emulador y confirmar que los Términos de
Servicio lo permiten. Siempre empieza en **Supervisado**.

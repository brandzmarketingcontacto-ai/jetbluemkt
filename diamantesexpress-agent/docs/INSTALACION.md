# Instalación en la PC Windows dedicada

## 1. Requisitos

- Windows 11 64 bits con una **sesión de usuario iniciada** (el emulador necesita escritorio gráfico;
  no funciona en la sesión 0 de los servicios de Windows).
- **BlueStacks 5** o **LDPlayer 9** instalado, con una instancia Android creada. Se elige en el instalador y se
  puede cambiar después en *Configuración → Emulador Android y ADB*.
- Conexión a Internet estable.

## 2. Preparar el emulador

**BlueStacks 5**
1. *Configuración → Avanzado* → active **Android Debug Bridge (ADB)**. Anote la dirección (p. ej. `127.0.0.1:5555`).
2. La instancia se identifica por su nombre interno (`Pie64`, `Pie64_1`…); *Detectar instancias* los lista.

**LDPlayer 9**
1. *Configuración → Otros ajustes → Depuración ADB* → **Abrir conexión local**.
2. La instancia se identifica por su índice en el *Multi-instancia* de LDPlayer (`0` = principal) o por su nombre.
   La instancia N usa el serial `emulator-(5554 + 2N)` (la principal es `emulator-5554`) o `127.0.0.1:(5555 + 2N)`.
3. El agente usa `ldconsole.exe` de la carpeta de LDPlayer (por defecto `C:\LDPlayer\LDPlayer9`) para abrir y
   cerrar la instancia, y su `adb.exe` para controlarla.

En ambos:
3. Instale la app de simulación (ver `android-sim/README.md`) para la fase de pruebas.
4. Recomendado: resolución fija de la instancia y sin rotación automática.
5. Use **uno solo** de los dos emuladores en la PC: los dos traen su propio `adb` y pueden interferir entre sí.

## 3. Instalar el agente

- Con instalador: ejecute `DiamantesExpressAgent-Setup.exe`, elija el emulador (**BlueStacks 5**, **LDPlayer 9** o
  *ninguno por ahora*) y marque *Iniciar al iniciar sesión*.
- Sin instalador: copie la carpeta `DiamantesExpressAgent` a `C:\Program Files\` y ejecute
  `DiamantesExpressAgent.exe`.

Los datos quedan en `%LOCALAPPDATA%\DiamantesExpressAgent\` (base de datos, logs, evidencias, configuración).

## 4. Configurar (pestaña Configuración)

1. **URL de la API**: `https://<dominio-publicado>/hcgi/api` (solo HTTPS).
2. **Token del agente**: en DiamantesExpress, *Automatización → Tokens del agente* → crear. Copie el `dxa_...`
   (se muestra una sola vez) y péguelo en *Claves y secretos → Guardar token*. Queda en el Administrador de
   credenciales de Windows (DPAPI), nunca en archivos. Pulse *Probar conexión*: debe mostrar el modo del servidor.
3. **Clave OpenAI**: guárdela y pulse *Verificar clave* (no consume tokens).
4. **Emulador**: *Dispositivo* = *BlueStacks 5 por ADB* o *LDPlayer 9 por ADB* → *Detectar instalación e
   instancias* → elija la instancia. Si la detección falla, complete la carpeta del emulador (y `bluestacks.conf` en
   BlueStacks) y/o el serial ADB. Al guardar, el servicio se reinicia con el emulador elegido.
5. **Modo local**: empiece en **SIMULACIÓN**. El modo efectivo lo decide DiamantesExpress (*Automatización*); el
   local solo restringe. Para la fase de pruebas ponga el servidor en `simulation` y encole tareas con *Simular*.
6. **Presupuesto**: costo máximo por pedido y presupuesto diario.
7. **24/7**: inicio automático, impedir suspensión (opcional, requiere su consentimiento) y reinicio seguro del emulador.

Alternativa por consola: `dxagent-cli.exe set-secret agent-token`, `dxagent-cli.exe set-secret openai-key` y
`dxagent-cli.exe check-api` (heartbeat real, no reclama tareas).

## 5. Energía y suspensión (PC 24/7)

La opción *Impedir la suspensión* solo evita la suspensión mientras el agente corre. Además configure:

- *Configuración → Sistema → Inicio/apagado y batería*: Suspender = **Nunca**.
- Desactive la hibernación si no la usa: `powercfg /hibernate off` (PowerShell como administrador).
- Inicio de sesión automático del usuario dedicado (o bloquee la pantalla sin cerrar sesión: el emulador
  sigue funcionando con la sesión bloqueada, pero **no** si la sesión se cierra).
- Windows Update: defina *horas activas* para evitar reinicios durante la operación.

## 6. Supervisión adicional (opcional)

`packaging\install_watchdog_task.ps1` registra una tarea programada que relanza el servicio al iniciar sesión
y lo reinicia si termina. El servicio usa un bloqueo de instancia única, así que no se duplica con la interfaz.

## 7. Verificación

1. `dxagent-cli.exe doctor` → revise `bluestacks`, `contract`, `pricing`, `workflow`.
2. `dxagent-cli.exe screenshot --out prueba.png` → debe abrir el emulador elegido si está cerrado y guardar una captura.
3. Interfaz → *Iniciar* → con pedidos de prueba en Horizons, observe el panel y el historial.
4. Complete la lista de [PRUEBAS_WINDOWS.md](PRUEBAS_WINDOWS.md) antes de pasar a SUPERVISADO.

# Instalación en la PC Windows dedicada

## 1. Requisitos

- Windows 11 64 bits con una **sesión de usuario iniciada** (BlueStacks necesita escritorio gráfico;
  no funciona en la sesión 0 de los servicios de Windows).
- BlueStacks 5 instalado, con una instancia Android creada.
- Conexión a Internet estable.

## 2. Preparar BlueStacks

1. Abra BlueStacks → *Configuración* → *Avanzado* → active **Android Debug Bridge (ADB)**.
   Anote la dirección que muestra (por ejemplo `127.0.0.1:5555`).
2. Instale la app de simulación (ver `android-sim/README.md`) para la fase de pruebas.
3. Recomendado: resolución fija de la instancia y sin rotación automática.

## 3. Instalar el agente

- Con instalador: ejecute `DiamantesExpressAgent-Setup.exe` y marque *Iniciar al iniciar sesión*.
- Sin instalador: copie la carpeta `DiamantesExpressAgent` a `C:\Program Files\` y ejecute
  `DiamantesExpressAgent.exe`.

Los datos quedan en `%LOCALAPPDATA%\DiamantesExpressAgent\` (base de datos, logs, evidencias, configuración).

## 4. Configurar (pestaña Configuración)

1. **URL de la API** que entregue Horizons (solo HTTPS).
2. **Emparejamiento**: genere un código en el panel de superadministrador e ingréselo. El token queda en el
   Administrador de credenciales de Windows; nunca en archivos.
3. **Clave OpenAI**: guárdela y pulse *Verificar clave* (no consume tokens).
4. **BlueStacks**: *Dispositivo = BlueStacks 5 por ADB* → *Detectar instalación e instancias* → elija la instancia.
   Si la detección falla, complete la carpeta de instalación, `bluestacks.conf` y/o el serial ADB.
5. **Modo**: empiece en **SIMULACIÓN** con el flujo `simulation_gift`.
6. **Presupuesto**: costo máximo por pedido y presupuesto diario.
7. **24/7**: inicio automático, impedir suspensión (opcional, requiere su consentimiento) y reinicio seguro del emulador.

Alternativa por consola: `dxagent-cli.exe pair --code XXXX`, `dxagent-cli.exe set-secret openai-key`.

## 5. Energía y suspensión (PC 24/7)

La opción *Impedir la suspensión* solo evita la suspensión mientras el agente corre. Además configure:

- *Configuración → Sistema → Inicio/apagado y batería*: Suspender = **Nunca**.
- Desactive la hibernación si no la usa: `powercfg /hibernate off` (PowerShell como administrador).
- Inicio de sesión automático del usuario dedicado (o bloquee la pantalla sin cerrar sesión: BlueStacks
  sigue funcionando con la sesión bloqueada, pero **no** si la sesión se cierra).
- Windows Update: defina *horas activas* para evitar reinicios durante la operación.

## 6. Supervisión adicional (opcional)

`packaging\install_watchdog_task.ps1` registra una tarea programada que relanza el servicio al iniciar sesión
y lo reinicia si termina. El servicio usa un bloqueo de instancia única, así que no se duplica con la interfaz.

## 7. Verificación

1. `dxagent-cli.exe doctor` → revise `bluestacks`, `contract`, `pricing`, `workflow`.
2. `dxagent-cli.exe screenshot --out prueba.png` → debe abrir BlueStacks si está cerrado y guardar una captura.
3. Interfaz → *Iniciar* → con pedidos de prueba en Horizons, observe el panel y el historial.
4. Complete la lista de [PRUEBAS_WINDOWS.md](PRUEBAS_WINDOWS.md) antes de pasar a SUPERVISADO.

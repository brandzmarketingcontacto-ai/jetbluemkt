# DX Gift Simulator (aplicación Android ficticia)

Aplicación de prueba para validar DiamantesExpress Agent sobre BlueStacks 5 o LDPlayer 9 **sin envíos reales**.
No solicita permisos de red. Sus `resource-id` coinciden con `src/dxagent/android/mock_device.py`
y con `src/dxagent/resources/workflows/simulation_gift.yaml`.

> Estado: código fuente escrito pero **no compilado** en el entorno de desarrollo (no había Android SDK).
> Compílelo en Windows con Android Studio y valide con la lista de `docs/PRUEBAS_WINDOWS.md`.

## Compilar e instalar

1. Abra esta carpeta en Android Studio (Hedgehog o superior, JDK 17) y deje que genere el wrapper de Gradle.
2. *Build → Build APK(s)*. El APK queda en `app/build/outputs/apk/debug/app-debug.apk`.
3. Active ADB en el emulador:
   - BlueStacks 5: *Configuración → Avanzado → Android Debug Bridge (ADB)*.
   - LDPlayer 9: *Configuración → Otros ajustes → Depuración ADB → Abrir conexión local*.
4. Instale (el serial aparece con `dxagent doctor`):
   - BlueStacks: `HD-Adb.exe -s 127.0.0.1:<puerto> install app-debug.apk`
   - LDPlayer: `C:\LDPlayer\LDPlayer9\adb.exe -s emulator-5554 install app-debug.apk`
     (o arrastre el APK a la ventana de LDPlayer).

## Probar resultados no exitosos

```
adb shell am start -n com.diamantesexpress.giftsim/.MainActivity --es outcome failure
adb shell am start -n com.diamantesexpress.giftsim/.MainActivity --es outcome uncertain
```

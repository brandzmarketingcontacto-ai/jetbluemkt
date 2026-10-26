# DX Gift Simulator (aplicación Android ficticia)

Aplicación de prueba para validar DiamantesExpress Agent sobre BlueStacks **sin envíos reales**.
No solicita permisos de red. Sus `resource-id` coinciden con `src/dxagent/android/mock_device.py`
y con `src/dxagent/resources/workflows/simulation_gift.yaml`.

> Estado: código fuente escrito pero **no compilado** en el entorno de desarrollo (no había Android SDK).
> Compílelo en Windows con Android Studio y valide con la lista de `docs/PRUEBAS_WINDOWS.md`.

## Compilar e instalar

1. Abra esta carpeta en Android Studio (Hedgehog o superior, JDK 17) y deje que genere el wrapper de Gradle.
2. *Build → Build APK(s)*. El APK queda en `app/build/outputs/apk/debug/app-debug.apk`.
3. En BlueStacks 5: *Configuración → Avanzado → Android Debug Bridge (ADB)* activado.
4. Instale: `HD-Adb.exe -s 127.0.0.1:<puerto> install app-debug.apk`
   (el puerto aparece en la misma pantalla de BlueStacks o con `dxagent doctor`).

## Probar resultados no exitosos

```
adb shell am start -n com.diamantesexpress.giftsim/.MainActivity --es outcome failure
adb shell am start -n com.diamantesexpress.giftsim/.MainActivity --es outcome uncertain
```

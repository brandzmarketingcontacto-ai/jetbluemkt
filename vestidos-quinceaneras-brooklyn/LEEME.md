# Vestidos para Quinceañeras Brooklyn — sitio + panel

Landing de ventas con panel de administración. PHP 8 + MySQL, pensado para Hostinger (hosting compartido).

## Instalar en Hostinger

1. **Base de datos** (ya creada en hPanel → Bases de datos MySQL):
   - Base: `u731277802_vqbdb`
   - Usuario: `u731277802_vqb`
   - Servidor: `localhost`
2. **Subir archivos**: en hPanel → Administrador de archivos → `public_html`, sube el contenido de esta carpeta
   (o el `.zip` y usa "Extraer"). Deben quedar `index.php`, `install.php`, `admin/`, `assets/`, `inc/` y `uploads/`
   directamente dentro de `public_html`.
3. **Instalar**: abre `https://TU-DOMINIO/install.php`, escribe la contraseña de MySQL y pulsa **Instalar**.
   Se crean las tablas y se carga todo el contenido (secciones, 19 vestidos, accesorios, preguntas e imágenes).
4. **Borra `install.php`** del servidor cuando termine.
5. **Panel**: botón **Admin** al pie del sitio → contraseña inicial `admin123`.
   Cámbiala en *Ajustes → Seguridad*.

La contraseña de MySQL solo se guarda en `inc/config.php` en el servidor (no está en GitHub; `inc/` está bloqueada al navegador).

## Qué se edita desde el panel

| Pestaña | Qué hace |
|---|---|
| Página | Ordenar secciones arrastrando, mostrar/ocultar, editor de texto TipTap (títulos, negrita, listas, enlaces, colores, alineación), botón y destino, color de fondo, imagen, bloques nuevos. Vista previa en vivo del sitio real. |
| Vestidos | Foto (subir, soltar o elegir de la biblioteca), nombre, precio, estilo, etiqueta, destacado, visible, orden arrastrando. |
| Accesorios | Ramos, crinolinas y complementos. |
| Preguntas | Preguntas frecuentes con respuesta en TipTap, orden arrastrando. |
| Imágenes | Logo a color, logo blanco, foto pequeña de portada, imágenes de secciones y biblioteca de fotos. |
| Ajustes | WhatsApp, teléfono, dirección, mensajes, barra superior, botón flotante, salón EMA y contraseña. |

Todo se guarda solo, al momento, en MySQL.

## Estructura

```
index.php          Landing (lee la base de datos)
install.php        Instalador (crea tablas y contenido inicial)
admin/             Panel: index.php, app.js, admin.css, api.php, login.php, logout.php
admin/vendor/      TipTap 2.27.3 + SortableJS 1.15.2 empaquetados (sin CDN)
assets/            CSS, JS e imágenes del sitio
inc/               Conexión, seguridad y esquema (bloqueado al navegador)
uploads/           Fotos subidas desde el panel (no ejecuta scripts)
```

## Seguridad incluida

- Contraseña guardada con `password_hash`; máximo 5 intentos fallidos cada 15 minutos.
- Token CSRF en todas las escrituras; sesión con cookie `HttpOnly`.
- El HTML del editor se limpia en el servidor (sin scripts ni enlaces `javascript:`).
- Las subidas se validan como imagen real (JPG, PNG, WEBP, GIF, máx. 10 MB) y se reducen a 1800 px.

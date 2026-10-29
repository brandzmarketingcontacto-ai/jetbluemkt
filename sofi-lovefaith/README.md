# Sofi Love & Faith — tienda en línea autoadministrable

Tienda de joyería, perfumes y detalles de amor y fe. Está en inglés, tiene botón EN/ES y pregunta el idioma en la primera visita. Incluye un panel de super admin donde todo es editable.

- **PHP 8.1+ y MySQL** (Hostinger, plan compartido o superior). No necesita Composer ni Node.
- **Sitio público:** Inicio, Tienda, página de cada producto, Contacto y Carrito.
- **Panel `/admin`:** solo pide contraseña. La inicial es `admin123` y se cambia en Ajustes › Seguridad.

## Instalar en Hostinger

1. **Sube los archivos.** En hPanel ve a **Archivos › Administrador de archivos › public_html**. Sube **el contenido** de esta carpeta `sofi-lovefaith/` (no la carpeta en sí). Lo más fácil es comprimir la carpeta en un .zip, subirlo y usar "Extraer".
   > **Importante:** el sitio necesita los archivos ocultos `.htaccess` y `.user.ini`. La Mac (Finder) no los muestra, así que si descomprimes en tu computadora y subes los archivos, esos dos se quedan fuera, y `/admin`, `/shop` y las demás páginas muestran "404 This Page Does Not Exist". Sube el `.zip` tal cual y extráelo **dentro de Hostinger**. Si ya subiste los archivos, crea en public_html un archivo nuevo llamado `.htaccess` y pega el contenido del `.htaccess` de este proyecto.
2. **Versión de PHP.** En hPanel ve a **Avanzado › Configuración de PHP** y elige PHP 8.1 o superior. Revisa que estén activas `pdo_mysql`, `curl`, `gd` y `fileinfo` (vienen activas por defecto).
3. **Base de datos.** Ya está creada en hPanel › Bases de datos:
   - Base de datos: `u731277802_sofidb`
   - Usuario: `u731277802_sofilf`
   - Host: `localhost`
4. **Ejecuta el instalador.** Abre `https://tu-dominio.com/install.php`. Los datos de la base ya vienen escritos, así que **solo escribe la contraseña de MySQL** y pulsa **Instalar base de datos**. El instalador:
   - crea las 11 tablas;
   - carga los ajustes, las páginas (Inicio, Tienda, Contacto), tus productos de ejemplo con sus fotos y los cupones `FAITH25` y `WELCOME10`;
   - escribe `config.php` con la conexión y se bloquea solo (`install.lock`).
5. **Borra `install.php`** desde el Administrador de archivos (recomendado).
6. **SSL.** Activa el certificado SSL gratis en hPanel › Seguridad › SSL. Después quita el `#` de las dos líneas "Forzar HTTPS" en `.htaccess`.

## Primeros pasos en el panel (`/admin`)

1. **Ajustes › Seguridad:** cambia la contraseña `admin123`.
2. **Ajustes › Tienda:** escribe tu dominio (`https://tu-dominio.com`).
3. **Ajustes › Correo SMTP:**
   - Crea un buzón en hPanel › Correos (por ejemplo `tienda@tu-dominio.com`).
   - Usa servidor `smtp.hostinger.com`, puerto `465`, cifrado SSL, el usuario y la contraseña de ese buzón.
   - En **Correo secundario para notificaciones** pon el correo donde quieres recibir los avisos.
   - Guarda y pulsa **Enviar correo de prueba**.
4. **Ajustes › Pagos · Stripe:**
   - Pega tus claves de prueba (`pk_test_…`, `sk_test_…`).
   - En Stripe › Developers › Webhooks, crea un endpoint con la URL que muestra el panel y el evento `checkout.session.completed`. Copia el secreto `whsec_…` al panel.
   - Haz una compra de prueba con la tarjeta `4242 4242 4242 4242`. Cuando todo funcione, cambia a **Modo real** con tus claves `live`.
5. **Ajustes › Logotipo y favicon, Redes y WhatsApp:** revisa el logo, el favicon, Instagram y el número de WhatsApp (+1 656 223 8763).
6. **SEO y Open Graph:** revisa el título, la descripción y la imagen que se ve al compartir el enlace.

## Qué se edita y dónde

| Sección del panel | Qué controla |
| --- | --- |
| **Editor de páginas** | Inicio, Tienda y Contacto por bloques: hero, beneficios, categorías, banner de oferta con cuenta regresiva, productos, historia, Instagram, imagen + texto, video, testimonios, preguntas frecuentes, suscripción y espaciador. Cada bloque tiene versión EN y ES, fondo, espaciado y opción de ocultarlo en celular. Se arrastran para cambiar el orden, el borrador se guarda solo con vista previa en vivo, y hay deshacer/rehacer y botón **Publicar**. |
| **Productos** | Editor con fotos, GIFs y videos (MP4 o YouTube/Vimeo) que se suben arrastrando, se ordenan y se elige la portada. Incluye texto alternativo, descripción con formato EN/ES, precio "antes" con % automático, fecha de fin de oferta, variantes (color, largo…) con precio, stock, SKU y foto por variante, elementos de venta (cuenta regresiva, "solo quedan X", tarjeta de regalo, WhatsApp, "completa el look", reseñas, botón fijo en móvil), SEO con vista de Google e indicador de qué tan completo está el producto. |
| **Pedidos** | Pedidos pagados con Stripe: estado, número de rastreo y aviso al cliente por correo. |
| **Formularios** | Mensajes de Contacto (con respuesta por WhatsApp o correo y exportación a CSV), suscripciones y reseñas por aprobar. |
| **Ofertas y cupones** | La barra de ofertas en movimiento (EN/ES, velocidad, enlace) y los códigos de descuento. |
| **Medios** | Biblioteca de todas las fotos y videos subidos. |
| **SEO y Open Graph** | Título, descripción y palabras clave EN/ES; imagen al compartir (por defecto el logotipo); vistas previas de WhatsApp, Facebook y Google; SEO por página; sitemap, robots, hreflang, Google Analytics, Meta Pixel y Search Console. |
| **Ajustes** | Logotipo, logotipo para fondo oscuro, favicon, envíos, idiomas, textos del pie de página, redes sociales, WhatsApp, Stripe, SMTP, contraseña y respaldo de la base de datos. |

## Estructura

```
index.php          enrutador (tienda + /admin)
install.php        instalador de la base de datos (bórralo después de instalar)
app/               código PHP (bloqueado desde la web)
  schema.sql       tablas MySQL
  seed.php         contenido inicial
  admin.php        panel
  front.php        tienda
  views/           plantillas
assets/            CSS, JS e imágenes de la marca
uploads/           fotos y videos subidos (no ejecuta PHP)
config.php         se crea al instalar (no se sube a Git)
```

Las fotos y videos se guardan en `uploads/`. Hostinger permite archivos de hasta 64 MB con el `.user.ini` incluido. Para videos más pesados, súbelos a YouTube y pega el enlace.

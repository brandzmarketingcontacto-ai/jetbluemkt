# Instalar joseluiscornejo.com en Hostinger

La base de datos se instala sola: al abrir el sitio por primera vez se crean todas las tablas y el contenido inicial.

## 1. Subir los archivos
1. Entra a hPanel: https://hpanel.hostinger.com → **Sitios web** → joseluiscornejo.com → **Administrador de archivos**.
2. Abre la carpeta **public_html** (bórrale el `default.php` si existe).
3. Sube `joseluiscornejo-sitio.zip` y usa **Extraer** dentro de `public_html`.
   Debe quedar así: `public_html/index.php`, `public_html/admin/`, `public_html/assets/`, `public_html/.htaccess`…
   (Si quedó dentro de una subcarpeta, mueve su contenido a `public_html`.)

## 2. Base de datos (ya configurada)
`inc/config.php` ya tiene los datos:
- Base de datos: `u731277802_jlcdb`
- Usuario: `u731277802_jlc`
- Servidor: `localhost`

Si alguna vez cambias la contraseña de MySQL en hPanel (**Bases de datos → Administración**), actualízala también en `inc/config.php`.

## 3. Activar SSL y PHP
- hPanel → **Seguridad → SSL**: activa el certificado gratuito (el sitio fuerza HTTPS).
- hPanel → **Avanzado → Configuración de PHP**: PHP 8.1 o superior (8.2 recomendado).

## 4. Primera visita
- Abre https://joseluiscornejo.com → se instala la base de datos automáticamente.
- Panel: https://joseluiscornejo.com/admin/ → contraseña **admin123** → cámbiala en **Contraseña**.

## 5. Correo y notificaciones
1. hPanel → **Correos electrónicos** → crea `contacto@joseluiscornejo.com` (webmail: https://mail.hostinger.com).
2. Panel del sitio → **Correo SMTP**: servidor `smtp.hostinger.com`, puerto `465`, SSL, usuario `contacto@joseluiscornejo.com` y su contraseña → **Guardar** → **Enviar correo de prueba**.

## 6. Pagos con tarjeta
Panel → **Pagos**: elige la pasarela activa (Stripe o PayPal) y pega las claves.
- **Stripe**: https://dashboard.stripe.com/apikeys (clave publicable y secreta).
  Opcional, recomendado: https://dashboard.stripe.com/webhooks → endpoint `https://joseluiscornejo.com/webhook/stripe`, evento `checkout.session.completed`, y pega el secreto `whsec_…`.
- **PayPal**: https://developer.paypal.com/dashboard/applications/live → crea una app → Client ID y Secret.
  En la cuenta PayPal Business, activa pagos con tarjeta sin cuenta PayPal (*PayPal Account Optional* en Preferencias de pago del sitio web).

## Qué se edita desde el panel
Textos e imágenes de todas las páginas, logotipo, redes, WhatsApp, libros y precios (estándar y firmado), costo de envío, escritos, videos de YouTube y frases, pedidos y su estado, mensajes, suscriptores (exporta CSV), pasarela de pago, correo SMTP y contraseña.

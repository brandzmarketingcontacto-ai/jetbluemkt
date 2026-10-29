<?php /** @var array $dbInfo */
$s = fn($k, $d = '') => (string)setting($k, $d);
$on = fn($k, $d = true) => setting_on($k, $d) ? 'checked' : '';
$secretPh = fn($k, $ph) => $s($k) !== '' ? '•••••••• guardada (escribe para cambiarla)' : $ph;
$imgField = function (string $name, string $label, string $hint = '', string $bg = '') use ($s) {
    $val = $s($name);
    ob_start(); ?>
    <div class="img-field" data-image-field>
      <strong><?= e($label) ?></strong>
      <div class="img-prev <?= e($bg) ?>"><?php if ($val): ?><img src="<?= e(media_url($val)) ?>" alt=""><?php else: ?><span class="muted">Sin imagen</span><?php endif; ?></div>
      <?php if ($hint): ?><small class="muted"><?= e($hint) ?></small><?php endif; ?>
      <input type="hidden" name="<?= e($name) ?>" value="<?= e($val) ?>">
      <div class="row-gap"><button type="button" class="btn btn-primary sm" data-pick="image"><?= icon('upload', 14) ?>Subir o elegir</button><button type="button" class="btn btn-ghost sm" data-clear>Quitar</button></div>
    </div>
    <?php return ob_get_clean();
};
$webhook = abs_url('/stripe/webhook');
?>
<div class="page-head">
  <div><h1>Ajustes</h1><p class="muted">Logotipo, pagos, correo, redes sociales y seguridad.</p></div>
  <button class="btn btn-primary" type="submit" form="settings-form">Guardar ajustes</button>
</div>
<nav class="anchor-nav" aria-label="Secciones">
  <a href="#marca">Logotipo y favicon</a><a href="#tienda">Tienda y envíos</a><a href="#idiomas">Idiomas y textos</a><a href="#redes">Redes y WhatsApp</a><a href="#pagos">Pagos · Stripe</a><a href="#smtp">Correo SMTP</a><a href="#seguridad">Seguridad</a><a href="#bd">Base de datos</a><a href="<?= e(url('/admin/seo')) ?>">SEO y Open Graph ↗</a>
</nav>

<form method="post" id="settings-form" class="settings" data-dirty-watch>
  <?= csrf_field() ?>
  <input type="hidden" name="_section" value="" data-section-field>

  <section class="card" id="marca">
    <div class="card-head"><div><h2>Logotipo y favicon</h2><p class="muted">Se actualizan en todo el sitio: encabezado, pie de página, ventana de idioma, correos, pestaña del navegador y vista al compartir.</p></div><a href="<?= e(url('/')) ?>" target="_blank" rel="noopener">Ver en la tienda ↗</a></div>
    <div class="grid-3">
      <?= $imgField('logo', 'Logotipo principal', 'PNG, JPG o WEBP. Recomendado cuadrado, 600 × 600 o más.') ?>
      <?= $imgField('logo_light', 'Logotipo para fondo oscuro (pie de página)', 'Opcional: si está vacío se usa el nombre en letra script.', 'dark') ?>
      <div class="img-field" data-image-field>
        <strong>Favicon (ícono de pestaña)</strong>
        <div class="img-prev tabs-prev">
          <span class="fake-tab"><img src="<?= e(media_url(brand_favicon())) ?>" alt="" data-fav-prev> <?= e($s('store_name', 'Sofi Love & Faith')) ?></span>
          <span class="fav-sizes"><img src="<?= e(media_url(brand_favicon())) ?>" alt="16 px" width="16" height="16"><img src="<?= e(media_url(brand_favicon())) ?>" alt="32 px" width="32" height="32"><img src="<?= e(media_url(brand_favicon())) ?>" alt="64 px" width="64" height="64"></span>
        </div>
        <label class="check"><input type="checkbox" name="favicon_from_logo" value="1" <?= $on('favicon_from_logo') ?>> Usar el logotipo principal</label>
        <input type="hidden" name="favicon" value="<?= e($s('favicon')) ?>">
        <div class="row-gap"><button type="button" class="btn btn-ghost sm" data-pick="image">Subir favicon propio (512 × 512)</button></div>
      </div>
    </div>
    <div class="grid-4">
      <label class="fld">Tamaño del logo en el encabezado · <span data-range-out><?= (int)$s('logo_size', '56') ?></span> px<input type="range" name="logo_size" min="32" max="96" value="<?= (int)$s('logo_size', '56') ?>" data-range-in></label>
      <label class="fld">Forma<select name="logo_shape"><?php foreach (['circle' => 'Círculo', 'rounded' => 'Cuadrado redondeado', 'original' => 'Original (sin recorte)'] as $k => $l): ?><option value="<?= $k ?>" <?= $s('logo_shape', 'circle') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <label class="fld">Nombre en letra script<input type="text" name="brand_script" value="<?= e($s('brand_script', 'Sofi')) ?>"></label>
      <label class="fld">Texto bajo el nombre<input type="text" name="brand_sub" value="<?= e($s('brand_sub', 'Love & Faith')) ?>"></label>
    </div>
    <label class="switch"><input type="checkbox" name="logo_show_name" value="1" <?= $on('logo_show_name') ?>><span></span>Mostrar el nombre junto al logo</label>
  </section>

  <section class="card" id="tienda">
    <h2>Tienda y envíos</h2>
    <div class="grid-3">
      <label class="fld">Nombre de la tienda<input type="text" name="store_name" value="<?= e($s('store_name')) ?>"></label>
      <label class="fld">Usuario / marca<input type="text" name="brand_handle" value="<?= e($s('brand_handle')) ?>"></label>
      <label class="fld">Dominio del sitio<input type="url" name="site_url" value="<?= e($s('site_url')) ?>" placeholder="https://tu-dominio.com"></label>
      <label class="fld">Moneda<select name="currency"><option value="USD" <?= $s('currency') === 'USD' ? 'selected' : '' ?>>USD — Dólar</option><option value="MXN" <?= $s('currency') === 'MXN' ? 'selected' : '' ?>>MXN — Peso mexicano</option></select></label>
      <label class="fld">Envío gratis desde<span class="money"><i>$</i><input type="text" inputmode="decimal" name="free_shipping_from" value="<?= e($s('free_shipping_from')) ?>"></span><small class="muted">0 = nunca gratis</small></label>
      <label class="fld">Costo de envío normal<span class="money"><i>$</i><input type="text" inputmode="decimal" name="shipping_flat" value="<?= e($s('shipping_flat')) ?>"></span></label>
      <label class="fld">Países de envío (códigos)<input type="text" name="ship_countries" value="<?= e($s('ship_countries', 'US,MX')) ?>"><small class="muted">Ej. US,MX</small></label>
      <label class="fld">Aviso de stock bajo en ≤<input type="number" name="low_stock_at" min="0" value="<?= (int)$s('low_stock_at', '5') ?>"></label>
      <label class="fld">Mostrar “Solo quedan X” con ≤<input type="number" name="low_stock_show" min="0" value="<?= (int)$s('low_stock_show', '8') ?>"></label>
      <label class="fld">Zona horaria<input type="text" name="timezone" value="<?= e($s('timezone', 'America/Denver')) ?>" list="tzs"><datalist id="tzs"><option value="America/Denver"><option value="America/Chicago"><option value="America/Los_Angeles"><option value="America/New_York"><option value="America/Ciudad_Juarez"><option value="America/Mexico_City"></datalist></label>
    </div>
  </section>

  <section class="card" id="idiomas">
    <h2>Idiomas y textos generales</h2>
    <div class="grid-3">
      <label class="fld">Idioma principal<select name="default_lang"><option value="en" <?= $s('default_lang', 'en') === 'en' ? 'selected' : '' ?>>English</option><option value="es" <?= $s('default_lang') === 'es' ? 'selected' : '' ?>>Español</option></select></label>
      <label class="switch"><input type="checkbox" name="lang_toggle" value="1" <?= $on('lang_toggle') ?>><span></span>Botón EN / ES en el encabezado</label>
      <label class="switch"><input type="checkbox" name="ask_lang" value="1" <?= $on('ask_lang') ?>><span></span>Preguntar el idioma en la primera visita</label>
    </div>
    <div class="grid-2 tight">
      <label class="fld">Texto del pie de página (inglés)<textarea name="footer_text_en" rows="2"><?= e($s('footer_text_en')) ?></textarea></label>
      <label class="fld">Texto del pie de página (español)<textarea name="footer_text_es" rows="2"><?= e($s('footer_text_es')) ?></textarea></label>
      <label class="fld">Ayuda en el pie (inglés)<textarea name="footer_help_en" rows="2"><?= e($s('footer_help_en')) ?></textarea></label>
      <label class="fld">Ayuda en el pie (español)<textarea name="footer_help_es" rows="2"><?= e($s('footer_help_es')) ?></textarea></label>
      <label class="fld">Materiales y cuidado (inglés)<textarea name="care_text_en" rows="3"><?= e($s('care_text_en')) ?></textarea></label>
      <label class="fld">Materiales y cuidado (español)<textarea name="care_text_es" rows="3"><?= e($s('care_text_es')) ?></textarea></label>
      <label class="fld">Envíos y devoluciones (inglés)<textarea name="shipping_text_en" rows="3"><?= e($s('shipping_text_en')) ?></textarea></label>
      <label class="fld">Envíos y devoluciones (español)<textarea name="shipping_text_es" rows="3"><?= e($s('shipping_text_es')) ?></textarea></label>
    </div>
  </section>

  <section class="card" id="redes">
    <h2>Redes sociales y WhatsApp</h2>
    <p class="muted">Se muestran en el pie de página y en Contacto. Deja vacío o apaga para ocultar.</p>
    <div class="social-rows">
      <?php foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'pinterest' => 'Pinterest', 'youtube' => 'YouTube'] as $k => $lbl): ?>
        <div class="social-row">
          <span class="soc-ic"><?= icon($k, 18) ?></span>
          <label class="fld grow"><?= e($lbl) ?><input type="url" name="social_<?= $k ?>" value="<?= e($s('social_' . $k)) ?>" placeholder="https://<?= $k ?>.com/…"></label>
          <label class="switch"><input type="checkbox" name="social_<?= $k ?>_on" value="1" <?= $on('social_' . $k . '_on') ?>><span></span><span class="sr-only">Mostrar <?= e($lbl) ?></span></label>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="grid-3">
      <label class="fld">Número de WhatsApp<input type="tel" name="whatsapp_number" value="<?= e($s('whatsapp_number')) ?>"><small class="muted">Con código de país. EE. UU.: +1</small></label>
      <label class="fld">Mensaje inicial (inglés)<input type="text" name="whatsapp_message_en" value="<?= e($s('whatsapp_message_en')) ?>"></label>
      <label class="fld">Mensaje inicial (español)<input type="text" name="whatsapp_message_es" value="<?= e($s('whatsapp_message_es')) ?>"></label>
    </div>
    <label class="switch"><input type="checkbox" name="whatsapp_float" value="1" <?= $on('whatsapp_float') ?>><span></span>Botón flotante de WhatsApp</label>
    <p class="hint">Enlace actual: <a href="<?= e(wa_link()) ?>" target="_blank" rel="noopener"><?= e(wa_display()) ?></a></p>
  </section>

  <section class="card" id="pagos">
    <div class="card-head">
      <div class="row-gap center-v"><span class="stripe-logo">S</span><div><h2>Pagos con Stripe</h2><p class="muted">Tarjetas, Apple Pay y Google Pay. Las claves están en dashboard.stripe.com › Developers › API keys.</p></div></div>
      <span class="st <?= stripe_configured() ? 'st-published' : 'st-draft' ?>"><?= stripe_configured() ? 'Conectado · modo ' . (stripe_mode() === 'live' ? 'real' : 'prueba') : 'Listo para configurar' ?></span>
    </div>
    <div class="row-gap">
      <div class="seg" role="radiogroup" aria-label="Modo">
        <label><input type="radio" name="stripe_mode" value="test" <?= stripe_mode() === 'test' ? 'checked' : '' ?>><span>Modo prueba</span></label>
        <label><input type="radio" name="stripe_mode" value="live" <?= stripe_mode() === 'live' ? 'checked' : '' ?>><span>Modo real (live)</span></label>
      </div>
      <label class="switch"><input type="checkbox" name="stripe_enabled" value="1" <?= $on('stripe_enabled') ?>><span></span>Aceptar pagos en línea</label>
    </div>
    <div class="grid-2 tight">
      <fieldset class="keys"><legend>Claves de prueba</legend>
        <label class="fld">Clave publicable<input type="text" name="stripe_pk_test" value="<?= e($s('stripe_pk_test')) ?>" placeholder="pk_test_…" class="mono" autocomplete="off"></label>
        <label class="fld">Clave secreta<input type="password" name="stripe_sk_test" placeholder="<?= e($secretPh('stripe_sk_test', 'sk_test_…')) ?>" class="mono" autocomplete="new-password"></label>
        <label class="fld">Secreto del webhook<input type="password" name="stripe_whsec_test" placeholder="<?= e($secretPh('stripe_whsec_test', 'whsec_…')) ?>" class="mono" autocomplete="new-password"></label>
      </fieldset>
      <fieldset class="keys"><legend>Claves reales (live)</legend>
        <label class="fld">Clave publicable<input type="text" name="stripe_pk_live" value="<?= e($s('stripe_pk_live')) ?>" placeholder="pk_live_…" class="mono" autocomplete="off"></label>
        <label class="fld">Clave secreta<input type="password" name="stripe_sk_live" placeholder="<?= e($secretPh('stripe_sk_live', 'sk_live_…')) ?>" class="mono" autocomplete="new-password"></label>
        <label class="fld">Secreto del webhook<input type="password" name="stripe_whsec_live" placeholder="<?= e($secretPh('stripe_whsec_live', 'whsec_…')) ?>" class="mono" autocomplete="new-password"></label>
      </fieldset>
    </div>
    <div class="note-box">
      <strong>Webhook:</strong> en Stripe › Developers › Webhooks › “Add endpoint”, pega esta URL y elige el evento <code>checkout.session.completed</code>:
      <span class="copy-line"><code><?= e($webhook) ?></code><button type="button" class="btn btn-ghost sm" data-copy="<?= e($webhook) ?>">Copiar</button></span>
    </div>
    <button type="button" class="btn btn-ghost sm" data-test="<?= e(url('/admin/api/stripe-test')) ?>">Probar conexión con Stripe</button>
  </section>

  <section class="card" id="smtp">
    <div class="row-gap center-v"><span class="soc-ic"><?= icon('mail', 20) ?></span><div><h2>Correo SMTP y notificaciones</h2><p class="muted">Con el correo de Hostinger: servidor smtp.hostinger.com, puerto 465, SSL.</p></div></div>
    <div class="grid-3">
      <label class="fld">Servidor SMTP<input type="text" name="smtp_host" value="<?= e($s('smtp_host')) ?>"></label>
      <label class="fld">Puerto<input type="number" name="smtp_port" value="<?= e($s('smtp_port', '465')) ?>"></label>
      <label class="fld">Cifrado<select name="smtp_secure"><?php foreach (['ssl' => 'SSL (465)', 'tls' => 'TLS / STARTTLS (587)', 'none' => 'Ninguno'] as $k => $l): ?><option value="<?= $k ?>" <?= $s('smtp_secure', 'ssl') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <label class="fld">Usuario (correo remitente)<input type="email" name="smtp_user" value="<?= e($s('smtp_user')) ?>" placeholder="tienda@tu-dominio.com" autocomplete="off"></label>
      <label class="fld">Contraseña del correo<input type="password" name="smtp_pass" placeholder="<?= e($secretPh('smtp_pass', 'Contraseña del buzón')) ?>" autocomplete="new-password"></label>
      <label class="fld">Nombre del remitente<input type="text" name="smtp_from_name" value="<?= e($s('smtp_from_name')) ?>"></label>
    </div>
    <label class="fld">Correo secundario para notificaciones<input type="text" name="notify_emails" value="<?= e($s('notify_emails')) ?>" placeholder="notificaciones@tu-correo.com" class="accent"><small class="muted">Aquí llegan los avisos del sitio. Puedes poner varios separados por coma.</small></label>
    <input type="hidden" name="smtp_from" value="<?= e($s('smtp_from')) ?>">
    <div class="toggles">
      <?php foreach (['notify_order' => 'Nuevo pedido pagado', 'notify_form' => 'Nuevo mensaje del formulario', 'notify_stock' => 'Producto con stock bajo', 'notify_review' => 'Nueva reseña por aprobar'] as $k => $lbl): ?>
        <label class="switch"><input type="checkbox" name="<?= $k ?>" value="1" <?= $on($k) ?>><span></span><?= e($lbl) ?></label>
      <?php endforeach; ?>
    </div>
    <div class="row-gap center-v">
      <button type="button" class="btn btn-ghost sm" data-test="<?= e(url('/admin/api/smtp-test')) ?>" data-test-needs-save>Enviar correo de prueba</button>
      <small class="muted">Guarda primero los ajustes y después envía la prueba.</small>
    </div>
  </section>
</form>

<section class="card" id="seguridad">
  <div class="row-gap center-v"><span class="soc-ic"><?= icon('lock', 20) ?></span><div><h2>Seguridad — contraseña del panel</h2><p class="muted">El panel no usa usuario, solo contraseña.</p></div></div>
  <?php if ($s('admin_password_default') === '1'): ?><p class="note-box warn">Sigues usando la contraseña inicial <strong>admin123</strong>. Te recomendamos cambiarla.</p><?php endif; ?>
  <form method="post" class="grid-3" data-pw-form>
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="password">
    <label class="fld">Contraseña actual<input type="password" name="current" autocomplete="current-password" required></label>
    <label class="fld">Nueva contraseña<input type="password" name="new" autocomplete="new-password" minlength="8" required data-pw-new><span class="meter" data-pw-meter><i></i><i></i><i></i><i></i></span></label>
    <label class="fld">Confirmar nueva contraseña<input type="password" name="confirm" autocomplete="new-password" minlength="8" required></label>
    <div><button class="btn btn-dark" type="submit">Cambiar contraseña</button></div>
  </form>
  <p class="hint">Bloqueo automático de 15 minutos después de 5 intentos fallidos.</p>
</section>

<section class="card" id="bd">
  <div class="card-head"><h2>Base de datos (Hostinger MySQL)</h2><span class="st st-published">Conectada</span></div>
  <dl class="facts">
    <div><dt>Host</dt><dd class="mono"><?= e($dbInfo['host']) ?></dd></div>
    <div><dt>Base de datos</dt><dd class="mono"><?= e($dbInfo['name']) ?></dd></div>
    <div><dt>Productos</dt><dd><?= (int)$dbInfo['products'] ?></dd></div>
    <div><dt>Pedidos</dt><dd><?= (int)$dbInfo['orders'] ?></dd></div>
    <div><dt>Tamaño</dt><dd><?= e($dbInfo['size']) ?> MB</dd></div>
    <div><dt>Versión MySQL</dt><dd><?= e($dbInfo['version']) ?></dd></div>
  </dl>
  <a class="btn btn-ghost sm" href="<?= e(url('/admin/backup')) ?>"><?= icon('down', 16) ?>Descargar respaldo (.sql)</a>
  <p class="hint">Para restaurar un respaldo, impórtalo en hPanel › Bases de datos › phpMyAdmin.</p>
</section>

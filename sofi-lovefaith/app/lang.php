<?php
declare(strict_types=1);

const LANGS = ['en', 'es'];

function lang(): string
{
    static $lang = null;
    if ($lang !== null) return $lang;
    $q = $_GET['lang'] ?? null;
    if (is_string($q) && in_array($q, LANGS, true)) {
        $lang = $q;
        setcookie('lang', $q, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
        $_COOKIE['lang'] = $q;
        return $lang;
    }
    $c = $_COOKIE['lang'] ?? null;
    if (is_string($c) && in_array($c, LANGS, true)) return $lang = $c;
    return $lang = in_array(setting('default_lang', 'en'), LANGS, true) ? setting('default_lang', 'en') : 'en';
}

function lang_chosen(): bool
{
    return isset($_COOKIE['lang']) || isset($_GET['lang']);
}

/** Pick a translated column/field: tr($product, 'name') -> name_es or name_en. */
function tr(?array $row, string $field): string
{
    if (!$row) return '';
    $v = $row[$field . '_' . lang()] ?? '';
    if ($v === '' || $v === null) $v = $row[$field . '_en'] ?? ($row[$field] ?? '');
    return (string)$v;
}

/** Translated setting: settings stored as key_en / key_es. */
function st(string $key, string $default = ''): string
{
    $v = (string)setting($key . '_' . lang(), '');
    if ($v === '') $v = (string)setting($key . '_en', $default);
    return $v;
}

/** URL of the current page in another language. */
function lang_url(string $l): string
{
    $params = $_GET;
    $params['lang'] = $l;
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return $path . '?' . http_build_query($params);
}

function t(string $key, array $vars = []): string
{
    static $dict = [
        'home' => ['Home', 'Inicio'],
        'shop' => ['Shop', 'Tienda'],
        'sale' => ['Sale', 'Ofertas'],
        'contact' => ['Contact', 'Contacto'],
        'search' => ['Search', 'Buscar'],
        'cart' => ['Cart', 'Carrito'],
        'language' => ['Language', 'Idioma'],
        'add_to_cart' => ['Add to cart', 'Agregar al carrito'],
        'added' => ['Added to cart', 'Agregado al carrito'],
        'buy_now' => ['Buy now — secure checkout', 'Comprar ahora — pago seguro'],
        'order_whatsapp' => ['Order or ask on WhatsApp', 'Pedir o preguntar por WhatsApp'],
        'you_save' => ['You save', 'Ahorras'],
        'only_left' => ['Only :n left in stock', 'Solo quedan :n'],
        'out_of_stock' => ['Out of stock', 'Agotado'],
        'sale_ends' => ['Sale ends in', 'La oferta termina en'],
        'gift_card' => ['Add a gift message card', 'Agregar tarjeta con mensaje de regalo'],
        'gift_card_hint' => ['Free · gift box included with every order', 'Gratis · caja de regalo incluida en cada pedido'],
        'gift_message' => ['Gift message (optional)', 'Mensaje de regalo (opcional)'],
        'free_ship_away' => ['You\'re :amt away from free shipping', 'Te faltan :amt para envío gratis'],
        'free_ship_ok' => ['You unlocked free shipping!', '¡Tienes envío gratis!'],
        'wont_tarnish' => ['Won\'t tarnish', 'No se oxida'],
        'ships_fast' => ['Ships in 24–48h', 'Envío en 24–48 h'],
        'returns' => ['30-day returns', 'Devoluciones 30 días'],
        'secure_stripe' => ['Payments processed securely by Stripe', 'Pagos procesados de forma segura con Stripe'],
        'details' => ['Details', 'Detalles'],
        'care' => ['Materials & care', 'Materiales y cuidado'],
        'shipping_returns' => ['Shipping & returns', 'Envíos y devoluciones'],
        'complete_look' => ['complete the look', 'completa el look'],
        'bundle_save' => ['Bundle & save :p%', 'Llévalos juntos y ahorra :p%'],
        'bundle_add' => ['Add bundle to cart', 'Agregar paquete al carrito'],
        'reviews' => ['Customer reviews', 'Reseñas de clientes'],
        'based_on' => ['Based on :n reviews', 'Basado en :n reseñas'],
        'write_review' => ['Write a review', 'Escribir una reseña'],
        'no_reviews' => ['Be the first to review this piece.', 'Sé la primera persona en dejar una reseña.'],
        'review_thanks' => ['Thank you! Your review will appear once approved.', '¡Gracias! Tu reseña aparecerá cuando sea aprobada.'],
        'verified' => ['Verified buyer', 'Compra verificada'],
        'may_love' => ['You may also love', 'También te puede encantar'],
        'color' => ['Color', 'Color'],
        'quantity' => ['Quantity', 'Cantidad'],
        'showing' => ['Showing :n products', 'Mostrando :n productos'],
        'all' => ['All', 'Todo'],
        'sort' => ['Sort', 'Ordenar'],
        'sort_best' => ['Featured', 'Destacados'],
        'sort_new' => ['Newest', 'Más nuevos'],
        'sort_low' => ['Price: low to high', 'Precio: menor a mayor'],
        'sort_high' => ['Price: high to low', 'Precio: mayor a menor'],
        'sort_disc' => ['Biggest discount', 'Mayor descuento'],
        'on_sale_only' => ['On sale only', 'Solo en oferta'],
        'search_ph' => ['Search jewelry, perfumes…', 'Buscar joyería, perfumes…'],
        'price_up_to' => ['Price · up to :amt', 'Precio · hasta :amt'],
        'apply' => ['Apply', 'Aplicar'],
        'no_products' => ['No products found. Try another filter.', 'No encontramos productos. Prueba otro filtro.'],
        'help_gift' => ['Need help choosing a gift?', '¿Necesitas ayuda para elegir un regalo?'],
        'help_gift_text' => ['Message us on WhatsApp and we\'ll help you pick.', 'Escríbenos por WhatsApp y te ayudamos a elegir.'],
        'your_cart' => ['Your cart', 'Tu carrito'],
        'cart_empty' => ['Your cart is empty.', 'Tu carrito está vacío.'],
        'keep_shopping' => ['Keep shopping', 'Seguir comprando'],
        'subtotal' => ['Subtotal', 'Subtotal'],
        'discount' => ['Discount', 'Descuento'],
        'shipping' => ['Shipping', 'Envío'],
        'free' => ['Free', 'Gratis'],
        'total' => ['Total', 'Total'],
        'coupon' => ['Discount code', 'Código de descuento'],
        'coupon_bad' => ['That code is not valid.', 'Ese código no es válido.'],
        'coupon_ok' => ['Code applied!', '¡Código aplicado!'],
        'coupon_min' => ['This code needs a minimum purchase of :amt.', 'Este código requiere una compra mínima de :amt.'],
        'coupon_na' => ['This code doesn\'t apply to the products in your cart.', 'Este código no aplica a los productos de tu carrito.'],
        'remove' => ['Remove', 'Quitar'],
        'update' => ['Update', 'Actualizar'],
        'checkout' => ['Secure checkout', 'Pagar de forma segura'],
        'checkout_wa' => ['Send order by WhatsApp', 'Enviar pedido por WhatsApp'],
        'payments_off' => ['Online payments are being set up. Send us your order by WhatsApp and we\'ll help you finish it.', 'Los pagos en línea se están configurando. Envíanos tu pedido por WhatsApp y te ayudamos a completarlo.'],
        'thanks_order' => ['Thank you for your order!', '¡Gracias por tu compra!'],
        'thanks_order_text' => ['Your order :n is confirmed. We sent the details to your email.', 'Tu pedido :n está confirmado. Te enviamos los detalles a tu correo.'],
        'order_pending' => ['We are confirming your payment. You will receive an email shortly.', 'Estamos confirmando tu pago. Recibirás un correo en breve.'],
        'name' => ['Name', 'Nombre'],
        'email' => ['Email', 'Correo'],
        'phone' => ['Phone / WhatsApp', 'Teléfono / WhatsApp'],
        'subject' => ['Subject', 'Asunto'],
        'message' => ['Message', 'Mensaje'],
        'send' => ['Send message', 'Enviar mensaje'],
        'newsletter_opt' => ['Send me offers and new arrivals', 'Quiero recibir ofertas y novedades'],
        'form_thanks' => ['Thank you! We\'ll get back to you within 24 hours.', '¡Gracias! Te responderemos en menos de 24 horas.'],
        'form_error' => ['Please complete your name, a valid email and your message.', 'Completa tu nombre, un correo válido y tu mensaje.'],
        'subj_product' => ['Question about a product', 'Pregunta sobre un producto'],
        'subj_order' => ['My order', 'Mi pedido'],
        'subj_custom' => ['Custom gift', 'Regalo personalizado'],
        'subj_wholesale' => ['Wholesale', 'Mayoreo'],
        'subj_other' => ['Other', 'Otro'],
        'follow_us' => ['Follow us', 'Síguenos'],
        'talk_to_us' => ['Talk to us', 'Escríbenos'],
        'help' => ['Help', 'Ayuda'],
        'rights' => ['All rights reserved.', 'Todos los derechos reservados.'],
        'welcome' => ['Welcome · Bienvenida', 'Welcome · Bienvenida'],
        'which_lang' => ['Which language do you prefer?', '¿En qué idioma prefieres ver la tienda?'],
        'enter_shop' => ['Enter the shop', 'Entrar a la tienda'],
        'days' => ['days', 'días'], 'hours' => ['hours', 'horas'], 'min' => ['min', 'min'], 'sec' => ['sec', 'seg'],
        'view_all' => ['View all →', 'Ver todo →'],
        'rating' => ['Rating', 'Calificación'],
        'review_title' => ['Title', 'Título'],
        'review_body' => ['Your review', 'Tu reseña'],
        'submit_review' => ['Submit review', 'Enviar reseña'],
        'not_found' => ['Page not found', 'Página no encontrada'],
        'not_found_text' => ['The page you are looking for doesn\'t exist.', 'La página que buscas no existe.'],
        'chat_wa' => ['Chat with us on WhatsApp', 'Escríbenos por WhatsApp'],
        'new' => ['New', 'Nuevo'],
        'best_seller' => ['Best seller', 'Más vendido'],
        'option' => ['Option', 'Opción'],
        'choose_option' => ['Please choose an option.', 'Elige una opción.'],
        'zoom' => ['Zoom image', 'Ampliar imagen'],
    ];
    $i = lang() === 'es' ? 1 : 0;
    $s = $dict[$key][$i] ?? $key;
    foreach ($vars as $k => $v) $s = str_replace(':' . $k, (string)$v, $s);
    return $s;
}

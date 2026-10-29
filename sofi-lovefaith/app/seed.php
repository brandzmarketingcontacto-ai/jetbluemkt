<?php
declare(strict_types=1);

/** Default settings + demo catalogue. Only fills what does not exist yet. */
function seed_defaults(string $adminPassword, bool $demo): void
{
    $now = date('Y-m-d H:i:s');
    $d = 'uploads/demo/';

    $settings = [
        'installed_at' => $now,
        'admin_password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
        'admin_password_default' => $adminPassword === 'admin123' ? '1' : '0',
        'store_name' => 'Sofi Love & Faith', 'brand_handle' => 'sofi.lovegifts',
        'brand_script' => 'Sofi', 'brand_sub' => 'Love & Faith',
        'logo' => 'assets/img/logo.jpg', 'logo_light' => '', 'favicon' => '', 'favicon_from_logo' => '1',
        'logo_size' => '56', 'logo_shape' => 'circle', 'logo_show_name' => '1',
        'currency' => 'USD', 'free_shipping_from' => '60', 'shipping_flat' => '6.99', 'ship_countries' => 'US,MX',
        'low_stock_at' => '5', 'low_stock_show' => '8', 'timezone' => 'America/Denver',
        'default_lang' => 'en', 'lang_toggle' => '1', 'ask_lang' => '1',
        'whatsapp_number' => '+1 (656) 223-8763', 'whatsapp_float' => '1',
        'whatsapp_message_en' => 'Hi Sofi! I have a question about…', 'whatsapp_message_es' => '¡Hola Sofi! Tengo una pregunta sobre…',
        'social_instagram' => 'https://www.instagram.com/sofi.lovefaith?vrfl=MThsaGNtZm5penF6bQ==', 'social_instagram_on' => '1',
        'social_facebook' => '', 'social_facebook_on' => '1', 'social_tiktok' => '', 'social_tiktok_on' => '1',
        'social_pinterest' => '', 'social_pinterest_on' => '1', 'social_youtube' => '', 'social_youtube_on' => '1',
        'offer_bar_on' => '1', 'offer_bar_speed' => '32', 'offer_bar_link' => '/shop?sale=1',
        'offer_bar_en' => "Love & Faith Week — up to 30% off\nFree gift box on every order\nCode FAITH25: 25% off cross pieces\nStainless steel that won't tarnish\nFree shipping on orders over $60\nQuestions? WhatsApp +1 (656) 223-8763",
        'offer_bar_es' => "Semana Amor y Fe — hasta 30% de descuento\nCaja de regalo gratis en cada pedido\nCódigo FAITH25: 25% en piezas de cruz\nAcero inoxidable que no se oxida\nEnvío gratis en pedidos de más de $60\n¿Dudas? WhatsApp +1 (656) 223-8763",
        'footer_text_en' => 'Jewelry, perfumes and little details made with love and faith.',
        'footer_text_es' => 'Joyería, perfumes y detalles hechos con amor y fe.',
        'footer_help_en' => 'We answer orders, sizes and gift questions personally.',
        'footer_help_es' => 'Respondemos personalmente sobre pedidos, tallas y regalos.',
        'care_text_en' => "316L stainless steel is water-resistant and hypoallergenic.\nTo keep the shine, avoid perfume directly on the piece and store it in its pouch.",
        'care_text_es' => "El acero inoxidable 316L resiste el agua y es hipoalergénico.\nPara conservar el brillo, evita aplicar perfume directo sobre la pieza y guárdala en su bolsita.",
        'shipping_text_en' => "Orders ship in 24–48 hours with tracking.\nFree shipping on orders over $60.\nReturns accepted within 30 days in original condition.",
        'shipping_text_es' => "Enviamos en 24–48 horas con número de rastreo.\nEnvío gratis en pedidos de más de $60.\nAceptamos devoluciones dentro de 30 días en su estado original.",
        'stripe_enabled' => '1', 'stripe_mode' => 'test',
        'stripe_pk_test' => '', 'stripe_sk_test' => '', 'stripe_whsec_test' => '',
        'stripe_pk_live' => '', 'stripe_sk_live' => '', 'stripe_whsec_live' => '',
        'smtp_host' => 'smtp.hostinger.com', 'smtp_port' => '465', 'smtp_secure' => 'ssl',
        'smtp_user' => '', 'smtp_pass' => '', 'smtp_from' => '', 'smtp_from_name' => 'Sofi Love & Faith',
        'notify_emails' => '', 'notify_order' => '1', 'notify_form' => '1', 'notify_stock' => '1', 'notify_review' => '1',
        'site_url' => '',
        'seo_title_en' => 'Sofi Love & Faith — Love & Faith Jewelry',
        'seo_title_es' => 'Sofi Love & Faith — Joyería de amor y fe',
        'seo_desc_en' => 'Stainless steel love & faith jewelry for women and men, perfumes and meaningful gifts. Tarnish-free and gift-ready.',
        'seo_desc_es' => 'Joyería de acero inoxidable para mujer y hombre, perfumes y detalles con significado. No se oxida y llega lista para regalar.',
        'seo_keywords_en' => 'faith jewelry, cross necklace, stainless steel jewelry, gifts, perfumes',
        'seo_keywords_es' => 'joyería de fe, collar de cruz, acero inoxidable, regalos, perfumes',
        'seo_title_template' => '%page% | %site%',
        'og_mode' => 'logo', 'og_image' => '', 'og_bg' => 'cream',
        'og_title_en' => 'Sofi Love & Faith — Jewelry with love and faith',
        'og_title_es' => 'Sofi Love & Faith — Joyería con amor y fe',
        'og_desc_en' => '', 'og_desc_es' => '', 'twitter_card' => 'summary_large_image',
        'biz_country' => 'US', 'biz_price_range' => '$$',
        'ga_id' => '', 'pixel_id' => '', 'gsc_verify' => '',
        'seo_sitemap' => '1', 'seo_index' => '1', 'seo_hreflang' => '1',
    ];
    $ins = db()->prepare('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)');
    foreach ($settings as $k => $v) $ins->execute([$k, $v]);
    // the installer always (re)sets the admin password it was given
    q('UPDATE settings SET v = ? WHERE k = "admin_password_hash"', [$settings['admin_password_hash']]);
    q('UPDATE settings SET v = ? WHERE k = "admin_password_default"', [$settings['admin_password_default']]);

    if (!(int)val('SELECT COUNT(*) FROM categories')) {
        $cats = [
            ['women', 'Women', 'Mujer', $d . 'pearl-model.jpg'],
            ['men', 'Men', 'Hombre', $d . 'steel-bead-bracelet.jpg'],
            ['faith', 'Faith', 'Fe', $d . 'sideways-cross.jpg'],
            ['perfumes', 'Perfumes', 'Perfumes', null],
            ['gifts', 'Gifts & details', 'Regalos y detalles', $d . 'spiral-cuff.jpg'],
        ];
        foreach ($cats as $i => [$slug, $en, $es, $img]) insert('categories', ['slug' => $slug, 'name_en' => $en, 'name_es' => $es, 'image' => $img, 'sort' => $i]);
    }

    if (!(int)val('SELECT COUNT(*) FROM pages')) {
        foreach (default_pages($d) as $slug => $p) {
            insert('pages', ['slug' => $slug, 'title_en' => $p[0], 'title_es' => $p[1], 'blocks' => jenc($p[2]), 'seo' => jenc([]), 'updated_at' => $now]);
        }
    }

    if (!(int)val('SELECT COUNT(*) FROM coupons')) {
        insert('coupons', ['code' => 'FAITH25', 'type' => 'percent', 'value' => 25, 'category' => 'faith', 'active' => 1, 'expires_at' => date('Y-m-d 23:59:59', strtotime('+30 days'))]);
        insert('coupons', ['code' => 'WELCOME10', 'type' => 'percent', 'value' => 10, 'min_total' => 20, 'active' => 1]);
    }

    if ($demo && !(int)val('SELECT COUNT(*) FROM products')) seed_products($d, $now);
}

function blk(string $type, array $en, array $es = [], array $data = [], array $style = []): array
{
    return ['id' => bid(), 'type' => $type, 'visible' => true, 'data' => $data, 'i18n' => ['en' => $en, 'es' => $es], 'style' => $style + ['bg' => 'default', 'pad' => 'm']];
}

function default_pages(string $d): array
{
    $ends = date('Y-m-d\TH:i', strtotime('+3 days 23:59'));
    $home = [
        blk('hero', [
            'badge' => 'Love & Faith Week · up to 30% off', 'script' => 'with love and faith',
            'title' => 'Jewelry that carries your love and your faith',
            'text' => "Stainless steel pieces made to be worn every day — they don't tarnish, they don't fade, and every one comes gift-ready.",
            'cta_text' => 'Shop the collection', 'cta2_text' => 'Ask us on WhatsApp',
            'perks' => "Hypoallergenic steel\nFree gift box\nSecure Stripe checkout",
            'image_2_label' => 'Faith collection', 'image_3_label' => 'New in',
        ], [
            'badge' => 'Semana Amor y Fe · hasta 30% off', 'script' => 'con amor y fe',
            'title' => 'Joyería que lleva tu amor y tu fe',
            'text' => 'Piezas de acero inoxidable hechas para usarse todos los días: no se oxidan, no pierden el color y todas llegan listas para regalar.',
            'cta_text' => 'Ver la colección', 'cta2_text' => 'Pregúntanos por WhatsApp',
            'perks' => "Acero hipoalergénico\nCaja de regalo gratis\nPago seguro con Stripe",
            'image_2_label' => 'Colección Fe', 'image_3_label' => 'Novedad',
        ], ['cta_link' => '/shop', 'cta2' => true, 'image_main' => $d . 'pearl-model.jpg', 'image_2' => $d . 'sideways-cross.jpg', 'image_3' => $d . 'spiral-cuff.jpg'], ['pad' => 'l']),
        blk('perks', ['items' => "Stainless steel | Water-resistant, won't tarnish\nGift-ready | Free box & card on every order\nSecure checkout | Cards, Apple Pay & Google Pay\nPersonal help | WhatsApp +1 (656) 223-8763"],
            ['items' => "Acero inoxidable | Resiste el agua, no se oxida\nListo para regalar | Caja y tarjeta gratis en cada pedido\nPago seguro | Tarjetas, Apple Pay y Google Pay\nAtención personal | WhatsApp +1 (656) 223-8763"], [], ['bg' => 'white', 'pad' => 's']),
        blk('categories', ['script' => 'find your piece', 'title' => 'Shop by category', 'link_text' => 'View all products →'],
            ['script' => 'encuentra tu pieza', 'title' => 'Compra por categoría', 'link_text' => 'Ver todos los productos →'], [], ['pad' => 'l']),
        blk('offer_banner', ['badge' => 'Limited-time offer', 'title' => '25% off every cross piece', 'text' => 'A small reminder of what you believe in, close to your heart. Use the code at checkout.', 'cta_text' => 'Shop the offer'],
            ['badge' => 'Oferta por tiempo limitado', 'title' => '25% en todas las piezas de cruz', 'text' => 'Un pequeño recordatorio de lo que crees, cerca de tu corazón. Usa el código al pagar.', 'cta_text' => 'Ver la oferta'],
            ['code' => 'FAITH25', 'ends_at' => $ends, 'cta_link' => '/shop?cat=faith', 'image' => $d . 'sideways-cross.jpg']),
        blk('products', ['script' => 'most loved', 'title' => 'Best sellers & deals', 'link_text' => 'Shop all →'],
            ['script' => 'los más queridos', 'title' => 'Más vendidos y ofertas', 'link_text' => 'Ver todo →'], ['source' => 'featured', 'count' => 8], ['pad' => 'l']),
        blk('story', [
            'script' => 'our story', 'title' => 'The beauty of details, the meaning behind every piece',
            'text' => "At Sofi Love & Faith we celebrate the beauty of details and the meaning behind every piece. We offer love and faith pieces that inspire, accompany and strengthen the spirit. Our jewelry and accessories are designed to go with you every day with elegance, intention and durability.\n\nWe work with high-quality stainless steel, along with other materials, creating modern, versatile accessories full of style.",
            'highlights' => "Jewelry | for women & men\nPerfumes | for her & him\nGifts | & little details",
        ], [
            'script' => 'nuestra historia', 'title' => 'La belleza de los detalles, el significado de cada pieza',
            'text' => "En Sofi Love & Faith celebramos la belleza de los detalles y el significado detrás de cada pieza. Ofrecemos artículos de amor y fe que inspiran, acompañan y fortalecen el espíritu. Nuestras joyas y artículos están diseñados para acompañarte en tu día a día con elegancia, intención y durabilidad.\n\nTrabajamos con acero inoxidable de alta calidad, además de otros materiales, creando accesorios modernos, versátiles y llenos de estilo.",
            'highlights' => "Joyería | para mujer y hombre\nPerfumes | para ella y él\nRegalos | y pequeños detalles",
        ], ['image' => 'assets/img/logo.jpg'], ['bg' => 'blush', 'pad' => 'l']),
        blk('instagram', ['script' => 'follow along', 'title' => '@sofi.lovefaith', 'link_text' => 'Follow us on Instagram →'],
            ['script' => 'síguenos', 'title' => '@sofi.lovefaith', 'link_text' => 'Síguenos en Instagram →'],
            ['images' => [$d . 'mandala-coin.jpg', $d . 'layered-model.jpg', $d . 'tri-tone-model.jpg', $d . 'spiral-cuff.jpg', $d . 'pearl-model.jpg', $d . 'sideways-cross.jpg']], ['pad' => 'l']),
    ];
    $shop = [
        blk('shop_grid', ['title' => 'The Shop', 'text' => 'Jewelry for women and men, faith pieces, perfumes and little details — made to be given, and to be kept.', 'promo_big' => '-25%', 'promo_text' => "Cross pieces this week\nCode FAITH25"],
            ['title' => 'La tienda', 'text' => 'Joyería para mujer y hombre, piezas de fe, perfumes y detalles — hechos para regalar y para conservar.', 'promo_big' => '-25%', 'promo_text' => "Piezas de cruz esta semana\nCódigo FAITH25"], [], ['pad' => 'none']),
    ];
    $contact = [
        blk('page_header', ['script' => "we'd love to hear from you", 'title' => "Let's talk", 'text' => 'Questions about a piece, a custom gift or your order? The fastest way to reach us is WhatsApp — we reply personally.'],
            ['script' => 'nos encantará saber de ti', 'title' => 'Hablemos', 'text' => '¿Preguntas sobre una pieza, un regalo personalizado o tu pedido? La forma más rápida es WhatsApp: respondemos personalmente.'], [], ['pad' => 'l']),
        blk('contact', ['wa_title' => 'Chat on WhatsApp', 'wa_text' => 'Orders, sizes, gift wrapping and custom requests. Mon–Sat · 10am–8pm', 'form_title' => 'Send us a message', 'form_text' => "We'll get back to you by email or WhatsApp within 24 hours."],
            ['wa_title' => 'Escríbenos por WhatsApp', 'wa_text' => 'Pedidos, tallas, envoltura de regalo y pedidos especiales. Lun–Sáb · 10 am–8 pm', 'form_title' => 'Envíanos un mensaje', 'form_text' => 'Te responderemos por correo o WhatsApp en menos de 24 horas.'], [], ['pad' => 's']),
        blk('faq', ['title' => 'Frequently asked questions', 'items' => "Does the jewelry tarnish? | Our pieces are 316L stainless steel: they don't tarnish or turn your skin green with normal use.\nHow long does shipping take? | Orders ship in 24–48 hours. You'll receive a tracking number by email.\nCan I return a piece? | Yes, within 30 days in its original condition. Message us on WhatsApp to start.\nDo you offer gift wrapping? | Every order includes a gift box, and you can add a free message card."],
            ['title' => 'Preguntas frecuentes', 'items' => "¿La joyería se oxida? | Nuestras piezas son de acero inoxidable 316L: no se oxidan ni manchan la piel con el uso normal.\n¿Cuánto tarda el envío? | Enviamos en 24–48 horas. Recibirás tu número de rastreo por correo.\n¿Puedo devolver una pieza? | Sí, dentro de 30 días en su estado original. Escríbenos por WhatsApp para iniciar.\n¿Envuelven para regalo? | Cada pedido incluye caja de regalo y puedes agregar una tarjeta con mensaje gratis."], [], ['pad' => 'l']),
    ];
    return ['home' => ['Home', 'Inicio', $home], 'shop' => ['Shop', 'Tienda', $shop], 'contact' => ['Contact', 'Contacto', $contact]];
}

function seed_products(string $d, string $now): void
{
    $gold = ['name_en' => 'Color', 'name_es' => 'Color', 'values' => [['en' => 'Gold', 'es' => 'Dorado', 'color' => '#D9B567'], ['en' => 'Silver', 'es' => 'Plateado', 'color' => '#C9CBCF']]];
    $len = ['name_en' => 'Chain length', 'name_es' => 'Largo de cadena', 'values' => [['en' => '16"', 'es' => '16"'], ['en' => '18"', 'es' => '18"'], ['en' => '20"', 'es' => '20"']]];
    $P = [
        // slug, name_en, name_es, cats, type, price, compare, stock, featured, badge_en, badge_es, media[], tagline_en, tagline_es, desc_en, desc_es, material, options
        ['floating-pearl-necklace', 'Floating Pearl Necklace', 'Collar Perla Flotante', 'women', 'Necklace', 24.99, 34.99, 6, 1, '', '', ['floating-pearl.jpg', 'pearl-model.jpg'],
            'Delicate, meaningful and tarnish-free.', 'Delicado, significativo y no se oxida.',
            '<p>A single pearl floating on a fine gold-tone chain — delicate enough for every day, meaningful enough to give.</p>',
            '<p>Una perla que flota sobre una fina cadena dorada: delicada para todos los días y especial para regalar.</p>', '18k gold-plated 316L stainless steel', [$gold, $len]],
        ['sideways-cross-necklace', 'Sideways Cross Necklace', 'Collar Cruz Lateral', 'faith,women', 'Necklace', 22.00, 29.00, 18, 1, '', '', ['sideways-cross.jpg'],
            'Your faith, close to your heart.', 'Tu fe, cerca de tu corazón.',
            '<p>A minimalist sideways cross on a sturdy gold-tone chain. A daily reminder of what you believe in.</p>',
            '<p>Una cruz lateral minimalista sobre una cadena dorada resistente. Un recordatorio diario de lo que crees.</p>', '18k gold-plated 316L stainless steel', []],
        ['tri-tone-braided-necklace', 'Tri-Tone Braided Necklace', 'Collar Trenzado Tres Tonos', 'women', 'Necklace', 39.00, 52.00, 9, 1, 'Best seller', 'Más vendido', ['tri-tone-braided.jpg', 'tri-tone-model.jpg'],
            'Gold, silver and rose — all in one.', 'Dorado, plateado y rosa en una sola pieza.',
            '<p>Three tones braided into one statement necklace that goes with everything you wear.</p>',
            '<p>Tres tonos trenzados en un collar llamativo que combina con todo lo que usas.</p>', '316L stainless steel, tri-tone plating', []],
        ['love-knot-cuff-bracelet', 'Love Knot Cuff Bracelet', 'Pulsera Nudo de Amor', 'women,gifts', 'Bracelet', 18.00, null, 24, 1, 'New', 'Nuevo', ['love-knot-cuff.jpg'],
            'A knot that means forever.', 'Un nudo que significa para siempre.',
            '<p>An adjustable cuff with a love knot at its center — the perfect gift for a friend, a sister or your mom.</p>',
            '<p>Una pulsera ajustable con un nudo de amor al centro: el regalo perfecto para una amiga, una hermana o tu mamá.</p>', '316L stainless steel', []],
        ['layered-bar-coin-necklace', 'Layered Bar & Coin Necklace', 'Collar Capas Barra y Moneda', 'women', 'Necklace', 29.00, null, 3, 0, '', '', ['layered-bar-coin.jpg', 'layered-model.jpg'],
            'Three layers, zero tangles.', 'Tres capas, sin enredos.',
            '<p>A pre-layered set of three chains with a bar and a coin pendant. Easy to wear, impossible to tangle.</p>',
            '<p>Un juego de tres cadenas con barra y medalla, ya en capas. Fácil de usar y no se enreda.</p>', '18k gold-plated 316L stainless steel', []],
        ['steel-bead-bracelet', 'Steel Bead Bracelet', 'Pulsera de Bolitas de Acero', 'men', 'Bracelet', 26.00, 32.00, 12, 1, '', '', ['steel-bead-bracelet.jpg'],
            'Strong, simple, made for him.', 'Fuerte, sencilla, hecha para él.',
            '<p>Polished stainless steel beads on an adjustable chain. Water-resistant and made to last.</p>',
            '<p>Bolitas de acero inoxidable pulido en una cadena ajustable. Resiste el agua y está hecha para durar.</p>', '316L stainless steel', []],
        ['hammered-disc-necklace', 'Hammered Disc Necklace', 'Collar Medalla Martillada', 'women', 'Necklace', 21.00, null, 15, 0, '', '', ['hammered-disc.jpg'],
            'Catches the light with every move.', 'Refleja la luz con cada movimiento.',
            '<p>A hand-hammered disc pendant on a fine chain — simple, golden and full of texture.</p>',
            '<p>Una medalla martillada a mano sobre una cadena fina: sencilla, dorada y llena de textura.</p>', '18k gold-plated 316L stainless steel', []],
        ['golden-spiral-cuff', 'Golden Spiral Cuff', 'Brazalete Espiral Dorado', 'gifts,women', 'Bracelet', 27.00, null, 0, 0, 'New', 'Nuevo', ['spiral-cuff.jpg'],
            'Bold spirals, endless love.', 'Espirales audaces, amor infinito.',
            '<p>An open cuff of golden spirals — a statement piece that symbolizes a love without end.</p>',
            '<p>Un brazalete abierto de espirales doradas: una pieza llamativa que simboliza un amor sin fin.</p>', '18k gold-plated stainless steel', []],
        ['mandala-coin-necklace', 'Mandala Coin Necklace', 'Collar Medalla Mandala', 'women', 'Necklace', 23.00, null, 10, 0, '', '', ['mandala-coin.jpg'],
            'Small, sacred, everyday.', 'Pequeña, especial, para diario.',
            '<p>A tiny engraved mandala coin on a delicate chain to wear alone or layered.</p>',
            '<p>Una pequeña medalla con mandala grabado sobre una cadena delicada, para usar sola o en capas.</p>', '18k gold-plated 316L stainless steel', []],
        ['perfume-for-her', '[Women\'s perfume name]', '[Nombre del perfume para ella]', 'perfumes', 'Perfume', 0, null, 0, 0, '', '', [],
            '', '', '<p>Add the description, price and photos of this perfume from the dashboard.</p>', '<p>Agrega la descripción, el precio y las fotos de este perfume desde el panel.</p>', '', []],
        ['perfume-for-him', '[Men\'s perfume name]', '[Nombre del perfume para él]', 'perfumes,men', 'Perfume', 0, null, 0, 0, '', '', [],
            '', '', '<p>Add the description, price and photos of this perfume from the dashboard.</p>', '<p>Agrega la descripción, el precio y las fotos de este perfume desde el panel.</p>', '', []],
    ];
    $ends = date('Y-m-d H:i:s', strtotime('+3 days 23:59'));
    foreach ($P as $i => $p) {
        [$slug, $nEn, $nEs, $cats, $type, $price, $cmp, $stock, $feat, $bEn, $bEs, $media, $tEn, $tEs, $dEn, $dEs, $mat, $opts] = $p;
        $variants = [];
        if ($opts) {
            $combos = [[]];
            foreach ($opts as $oi => $o) {
                $next = [];
                foreach ($combos as $c) foreach ($o['values'] as $vi => $v) $next[] = array_merge($c, [$vi]);
                $combos = $next;
            }
            foreach ($combos as $c) {
                $names = [];
                foreach ($c as $oi => $vi) $names[] = $opts[$oi]['values'][$vi]['en'];
                $variants[] = ['key' => implode('-', $c), 'name' => implode(' / ', $names), 'price' => '', 'stock' => $c[0] === 0 ? 2 : 1, 'sku' => 'SLF-' . sprintf('%03d', $i + 1) . '-' . implode('', $c), 'image' => ''];
            }
        }
        $isPerfume = $type === 'Perfume';
        $pid = insert('products', [
            'slug' => $slug, 'sku' => 'SLF-' . sprintf('%03d', $i + 1), 'name_en' => $nEn, 'name_es' => $nEs,
            'tagline_en' => $tEn, 'tagline_es' => $tEs, 'description_en' => $dEn, 'description_es' => $dEs,
            'details_en' => $isPerfume ? '' : "Material: $mat\nHypoallergenic and nickel-free\nGift box included",
            'details_es' => $isPerfume ? '' : "Material: $mat\nHipoalergénico y libre de níquel\nIncluye caja de regalo",
            'price' => $price, 'compare_price' => $cmp, 'cost' => null, 'stock' => $stock, 'track_stock' => 1, 'allow_backorder' => 0,
            'categories' => $cats, 'type' => $type, 'material' => $mat, 'tags' => '', 'badge_en' => $bEn, 'badge_es' => $bEs,
            'status' => $isPerfume ? 'draft' : 'published', 'featured' => $feat, 'sort' => $i,
            'sale_ends_at' => $cmp ? $ends : null, 'options' => jenc($opts), 'variants' => jenc($variants),
            'boosters' => jenc(['countdown' => 1, 'lowstock' => 1, 'gift' => 1, 'whatsapp' => 1, 'bundle' => 1, 'bundle_pct' => 15, 'reviews' => 1, 'sticky' => 1]),
            'bundle_ids' => '', 'seo' => jenc([]), 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($media as $mi => $file) {
            insert('product_media', ['product_id' => $pid, 'path' => $d . $file, 'kind' => 'image', 'alt_en' => $nEn, 'alt_es' => $nEs, 'sort' => $mi]);
        }
    }
    // "Complete the look" for the pearl necklace
    $ids = array_map('intval', array_column(rows('SELECT id FROM products WHERE slug IN ("love-knot-cuff-bracelet","hammered-disc-necklace")'), 'id'));
    q('UPDATE products SET bundle_ids = ? WHERE slug = "floating-pearl-necklace"', [implode(',', $ids)]);
    // register demo images in the media library
    foreach (glob(ROOT . '/uploads/demo/*.jpg') ?: [] as $f) {
        $info = @getimagesize($f);
        insert('media', ['path' => 'uploads/demo/' . basename($f), 'kind' => 'image', 'mime' => 'image/jpeg', 'size' => (int)filesize($f),
            'width' => $info[0] ?? null, 'height' => $info[1] ?? null, 'original' => basename($f), 'created_at' => $now]);
    }
}

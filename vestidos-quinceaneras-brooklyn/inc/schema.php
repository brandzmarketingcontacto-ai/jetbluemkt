<?php
// Tablas de la base de datos y contenido inicial del sitio.

declare(strict_types=1);

function schema_statements(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(64) NOT NULL PRIMARY KEY,
            v MEDIUMTEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS sections (
            id VARCHAR(40) NOT NULL PRIMARY KEY,
            kind VARCHAR(20) NOT NULL DEFAULT 'custom',
            name VARCHAR(120) NOT NULL,
            position INT NOT NULL DEFAULT 0,
            visible TINYINT(1) NOT NULL DEFAULT 1,
            eyebrow VARCHAR(160) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            body MEDIUMTEXT NOT NULL,
            cta VARCHAR(120) NOT NULL DEFAULT '',
            link VARCHAR(20) NOT NULL DEFAULT 'wa',
            bg VARCHAR(20) NOT NULL DEFAULT 'claro',
            image VARCHAR(255) NOT NULL DEFAULT '',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS dresses (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            price VARCHAR(40) NOT NULL DEFAULT '\$999',
            category VARCHAR(30) NOT NULL DEFAULT 'rosa',
            tag VARCHAR(80) NOT NULL DEFAULT '',
            image VARCHAR(255) NOT NULL DEFAULT '',
            featured TINYINT(1) NOT NULL DEFAULT 0,
            visible TINYINT(1) NOT NULL DEFAULT 1,
            position INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS accessories (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            image VARCHAR(255) NOT NULL DEFAULT '',
            visible TINYINT(1) NOT NULL DEFAULT 1,
            position INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS faqs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            question VARCHAR(255) NOT NULL,
            answer MEDIUMTEXT NOT NULL,
            visible TINYINT(1) NOT NULL DEFAULT 1,
            position INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS media (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(160) NOT NULL,
            url VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

function seed_database(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM sections')->fetchColumn();
    if ($count > 0) {
        return; // Ya tiene contenido: no sobrescribir.
    }

    $img = fn(string $f): string => 'assets/img/' . $f;

    $settings = [
        'site_title' => 'Vestidos para Quinceañeras Brooklyn',
        'tagline' => 'Confeccionados y diseñados en México',
        'whatsapp' => '917-238-8863',
        'phone2' => '646-247-4186',
        'wa_message' => 'Hola, quiero agendar una cita para ver vestidos de quinceañera',
        'address' => '1510 Ave H, Brooklyn, NY 11230',
        'maps_url' => 'https://maps.google.com/?q=1510+Ave+H+Brooklyn+NY+11230',
        'facebook_url' => 'https://www.facebook.com/profile.php?id=61571729006446',
        'topbar' => 'Expo y ventas: martes a jueves 3–7 pm · domingos 6–9 pm · o por cita',
        'float_button' => '1',
        'float_label' => '¿Agendamos tu cita?',
        'logo' => $img('logo.png'),
        'logo_white' => $img('logo-white.png'),
        'hero_inset' => $img('charro-fucsia.jpg'),
        'hero_badge' => '$999',
        'salon_web' => 'https://www.emaeventsvenue.com',
        'salon_capacity' => '',
        'admin_pass_hash' => password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT),
    ];
    $st = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = v');
    foreach ($settings as $k => $v) {
        $st->execute([$k, $v]);
    }

    $sections = [
        ['hero', 'hero', 'Portada', 'Confeccionados y diseñados en México', 'El vestido de tus XV, *desde $999.*',
            '<p>Bordados, brillo y vuelo de princesa sin pagar de más. Ven a probártelos en Brooklyn con atención personalizada, por cita y sin prisas.</p>',
            'Agendar cita por WhatsApp', 'wa', 'claro', $img('rosa-encanto.jpg')],
        ['valor', 'valor', 'Franja de beneficios', '', 'Por qué elegirnos',
            '<ul><li><strong>$999</strong> Precio de entrada en vestidos de XV</li><li><strong>México</strong> Confección y diseño mexicano</li><li><strong>1 a 1</strong> Atención personalizada por cita</li><li><strong>Salón</strong> Vestido y fiesta en la misma dirección</li></ul>',
            '', 'wa', 'ciruela', ''],
        ['coleccion', 'coleccion', 'Colección', 'La colección', 'Encuentra el vestido que te hace sentir reina',
            '<p>Muchos modelos están disponibles en todos los colores.</p>',
            'Preguntar por este vestido', 'wa', 'claro', ''],
        ['accesorios', 'accesorios', 'Accesorios', 'Completa tu look', 'Ramos y crinolinas a juego con tu vestido',
            '<p>Ramos de rosas de satín con pedrería y crinolinas en varios colores.</p>',
            'Pedir precios de accesorios', 'wa', 'claro', ''],
        ['como', 'como', 'Cómo funciona', 'Cómo funciona', 'Tu vestido en 3 pasos',
            '<ol><li><h3>Escríbenos por WhatsApp</h3><p>Cuéntanos fecha de tu fiesta, colores y talla. Te apartamos un horario.</p></li><li><h3>Pruébatelos en tu cita</h3><p>Ven con tu familia a 1510 Ave H. Te atendemos solo a ti, sin prisas.</p></li><li><h3>Aparta el tuyo</h3><p>Asegura tu vestido favorito con un apartado.</p></li></ol>',
            '', 'wa', 'claro', ''],
        ['visitanos', 'visitanos', 'Horarios y ubicación', 'Visítanos', 'Exhibición de vestidos en Brooklyn',
            '<ul><li><strong>Martes, miércoles y jueves</strong> 3:00 – 7:00 pm</li><li><strong>Domingos · Expo y ventas</strong> 6:00 – 9:00 pm</li><li><strong>Otros días</strong> Solo por cita</li></ul>',
            'Agendar mi cita', 'wa', 'claro', $img('showroom.jpg')],
        ['salon', 'salon', 'Salón EMA', 'También tenemos salón', 'EMA Events Venue: el vestido y la fiesta, en un solo lugar',
            '<p>Celebra tus XV, bodas, bautizos y cumpleaños en nuestro salón de eventos en la misma dirección: 1510 Ave H, Brooklyn. Pregunta por paquetes de vestido + salón.</p>',
            'Cotizar el salón', 'wa', 'ciruela', $img('salon-ema.jpg')],
        ['faq', 'faq', 'Preguntas frecuentes', '', 'Preguntas frecuentes', '', '', 'wa', 'claro', ''],
        ['cta', 'cta', 'Llamado final', '', 'Tu noche merece el vestido perfecto. Apártalo hoy.',
            '<p>Vestidos desde $999 · Atendemos por cita · 1510 Ave H, Brooklyn</p>',
            'Agendar cita por WhatsApp', 'wa', 'fucsia', ''],
        ['equipo', 'equipo', 'Únete al equipo', '', '¿Te gusta la moda y las ventas?',
            '<p>Forma parte de nuestro equipo de ventas — excelentes comisiones.</p>',
            'Quiero aplicar', 'wa', 'claro', ''],
    ];
    $st = $pdo->prepare('INSERT INTO sections (id, kind, name, position, eyebrow, title, body, cta, link, bg, image) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($sections as $i => $s) {
        $st->execute([$s[0], $s[1], $s[2], $i, $s[3], $s[4], $s[5], $s[6], $s[7], $s[8], $s[9]]);
    }

    $dresses = [
        ['Rosa Encanto', 'rosa', 'Flores 3D', 'rosa-encanto.jpg', 1],
        ['Charro Fucsia', 'charro', 'Negro y hueso', 'charro-fucsia.jpg', 1],
        ['Azul Marino y Oro', 'azul', 'Todos los colores', 'azul-marino-oro.jpg', 0],
        ['Moño Rosa', 'rosa', 'Moño XL en espalda', 'rosa-mono.jpg', 0],
        ['Verde Salvia Floral', 'verde', 'Flores 3D', 'verde-salvia.jpg', 0],
        ['Lila Brillo', 'lila', 'Con destellos', 'lila-brillo.jpg', 0],
        ['Rojo Pasión', 'rojo', 'Todos los colores', 'rojo-pasion.jpg', 0],
        ['Negro Estrellado', 'negro', 'Negro y azul marino', 'negro-estrellado.jpg', 0],
        ['Azul Real Corsé', 'azul', 'Corsé bordado', 'azul-real.jpg', 0],
        ['Marfil y Dorado', 'marfil', 'Encaje dorado', 'marfil-dorado.jpg', 0],
        ['Palo de Rosa Floral', 'rosa', 'Todos los colores', 'palo-rosa.jpg', 0],
        ['Plata Floral', 'verde', 'Verde, lila, palo de rosa y vino', 'plata-floral.jpg', 0],
        ['Cielo Degradado', 'azul', 'Encaje azul', 'cielo.jpg', 0],
        ['Lila Mariposas', 'lila', 'Detalles dorados', 'lila-mariposas.jpg', 0],
        ['Vino Satinado', 'rojo', 'Satín y pedrería', 'vino-satinado.jpg', 0],
        ['Champagne Bordado', 'marfil', 'Todos los colores', 'champagne.jpg', 0],
        ['Blanco y Negro', 'negro', 'Todos los colores', 'blanco-negro.jpg', 0],
        ['Rosa Jardín', 'rosa', 'Flores 3D', 'rosa-jardin.jpg', 0],
        ['Verde con Capa', 'verde', 'Con capa', 'verde-capa.jpg', 0],
    ];
    $st = $pdo->prepare('INSERT INTO dresses (name, price, category, tag, image, featured, position) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($dresses as $i => $d) {
        $st->execute([$d[0], '$999', $d[1], $d[2], $img($d[3]), $d[4], $i]);
    }

    $acc = [
        ['Ramo rojo con pedrería', 'ramo-rojo.jpg'],
        ['Ramo negro y fucsia', 'ramo-negro-fucsia.jpg'],
        ['Ramo durazno', 'ramo-durazno.jpg'],
        ['Ramo azul marino', 'ramo-marino.jpg'],
        ['Crinolinas de colores', 'crinolinas.jpg'],
    ];
    $st = $pdo->prepare('INSERT INTO accessories (name, image, position) VALUES (?, ?, ?)');
    foreach ($acc as $i => $a) {
        $st->execute([$a[0], $img($a[1]), $i]);
    }

    $faqs = [
        ['¿Necesito cita para ver los vestidos?', '<p>Atendemos por cita para darte atención personalizada. También tenemos exhibición martes, miércoles y jueves de 3 a 7 pm y expo-venta los domingos de 6 a 9 pm.</p>'],
        ['¿Cuánto cuestan los vestidos?', '<p>Nuestros vestidos de quinceañera inician en $999. El precio final depende del modelo; pregúntanos por WhatsApp por el que te guste.</p>'],
        ['¿Dónde se hacen los vestidos?', '<p>Todos son confeccionados y diseñados en México.</p>'],
        ['¿Tienen el modelo en otros colores?', '<p>Muchos de nuestros modelos están disponibles en todos los colores. Mándanos la foto del que te gusta por WhatsApp y te decimos.</p>'],
        ['¿Venden ramos y crinolinas?', '<p>Sí, tenemos ramos de rosas de satín y crinolinas en varios colores para combinar con tu vestido.</p>'],
        ['¿Puedo apartar mi vestido?', '<p>Sí, puedes apartar el tuyo. Pregúntanos por WhatsApp las condiciones de apartado.</p>'],
        ['¿También rentan salón para la fiesta?', '<p>Sí. EMA Events Venue está en la misma dirección, 1510 Ave H, Brooklyn. Pregunta por paquetes de vestido + salón.</p>'],
    ];
    $st = $pdo->prepare('INSERT INTO faqs (question, answer, position) VALUES (?, ?, ?)');
    foreach ($faqs as $i => $f) {
        $st->execute([$f[0], $f[1], $i]);
    }

    $st = $pdo->prepare('INSERT INTO media (name, url) VALUES (?, ?)');
    foreach (glob(APP_ROOT . '/assets/img/*.{jpg,png,webp}', GLOB_BRACE) ?: [] as $file) {
        $base = basename($file);
        $st->execute([ucfirst(str_replace('-', ' ', pathinfo($base, PATHINFO_FILENAME))), 'assets/img/' . $base]);
    }
}

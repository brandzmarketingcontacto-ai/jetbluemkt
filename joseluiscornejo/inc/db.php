<?php
// Conexión PDO a MySQL + instalación automática de tablas y datos iniciales.

function config(): array {
    static $c = null;
    if ($c === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('Falta inc/config.php con los datos de la base de datos.');
        }
        $c = require $file;
    }
    return $c;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = config();
    try {
        $pdo = new PDO(
            'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4',
            $c['db_user'],
            $c['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $e) {
        http_response_code(500);
        exit('No se pudo conectar a la base de datos. Revisa inc/config.php. (' . htmlspecialchars($e->getMessage()) . ')');
    }
    install_if_needed($pdo);
    migrate($pdo);
    return $pdo;
}

// Actualizaciones para sitios ya instalados (se aplican una sola vez).
function migrate(PDO $pdo): void {
    $v = (int)$pdo->query("SELECT v FROM settings WHERE k = 'schema_version'")->fetchColumn();
    if ($v < 2) migrate_v2($pdo);
    if ($v < 3) {
        // v3: Shorts de YouTube del canal
        $shorts = [
            'https://www.youtube.com/shorts/I0gJm-77MGs',
            'https://www.youtube.com/shorts/2PVSh0ddDE8',
            'https://www.youtube.com/shorts/Lw8i_JnghSE',
        ];
        $chk = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE youtube_url LIKE ?');
        $ins = $pdo->prepare("INSERT INTO posts (type, title, slug, excerpt, body, youtube_url, published, created_at) VALUES ('video', ?, ?, '', '', ?, 1, ?)");
        foreach ($shorts as $i => $url) {
            $id = substr($url, -11);
            $chk->execute(['%' . $id . '%']);
            if ($chk->fetchColumn()) continue;
            $title = function_exists('youtube_title') ? youtube_title($url) : '';
            // Fechas escalonadas para que se muestren en este orden
            $ins->execute([$title ?: 'Video', 'video-' . strtolower($id), $url, date('Y-m-d H:i:s', time() - $i)]);
        }
        $pdo->exec("INSERT INTO settings (k, v) VALUES ('schema_version', '3') ON DUPLICATE KEY UPDATE v = '3'");
    }
}

function migrate_v2(PDO $pdo): void {
    // v2: correcciones del cliente (precio de Amazon, redes, ocultar plática)
    $pdo->exec("UPDATE products SET price = 19.99 WHERE slug = 'ecos-del-pasado' AND price = 20.99");
    $set = $pdo->prepare("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = IF(v IS NULL OR v = '', VALUES(v), v)");
    $set->execute(['facebook_url', 'https://www.facebook.com/JoseLuisCornejoEcos/']);
    $set->execute(['youtube_url', 'https://www.youtube.com/@Joelouiscorne']);
    $pdo->exec("INSERT INTO settings (k, v) VALUES ('talk_show', '0') ON DUPLICATE KEY UPDATE v = '0'");
    $pdo->exec("INSERT INTO settings (k, v) VALUES ('schema_version', '2') ON DUPLICATE KEY UPDATE v = '2'");
}

function install_if_needed(PDO $pdo): void {
    $exists = $pdo->query("SHOW TABLES LIKE 'settings'")->fetchColumn();
    if ($exists) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(100) NOT NULL PRIMARY KEY,
        v MEDIUMTEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(160) NOT NULL UNIQUE,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        image VARCHAR(255) NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        price_signed DECIMAL(10,2) NULL,
        amazon_url VARCHAR(255) NULL,
        status ENUM('disponible','proximamente','oculto') NOT NULL DEFAULT 'disponible',
        sort INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS posts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        type ENUM('escrito','video','frase') NOT NULL DEFAULT 'escrito',
        title VARCHAR(200) NOT NULL,
        slug VARCHAR(220) NOT NULL UNIQUE,
        excerpt TEXT NULL,
        body MEDIUMTEXT NULL,
        youtube_url VARCHAR(255) NULL,
        published TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL UNIQUE,
        name VARCHAR(160) NOT NULL,
        email VARCHAR(190) NOT NULL,
        phone VARCHAR(40) NULL,
        address TEXT NULL,
        dedication TEXT NULL,
        items MEDIUMTEXT NOT NULL,
        subtotal DECIMAL(10,2) NOT NULL,
        shipping DECIMAL(10,2) NOT NULL DEFAULT 0,
        total DECIMAL(10,2) NOT NULL,
        currency VARCHAR(5) NOT NULL DEFAULT 'USD',
        gateway VARCHAR(20) NOT NULL,
        gateway_ref VARCHAR(190) NULL,
        status ENUM('pendiente','pagado','enviado','cancelado') NOT NULL DEFAULT 'pendiente',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (gateway_ref)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        email VARCHAR(190) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS subscribers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        source VARCHAR(60) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Datos iniciales
    $ins = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)');
    foreach (default_settings() as $k => $v) $ins->execute([$k, $v]);
    $ins->execute(['schema_version', '2']);
    $ins->execute(['admin_password', password_hash(config()['admin_initial_password'] ?? 'admin123', PASSWORD_DEFAULT)]);

    $p = $pdo->prepare('INSERT INTO products (slug,title,description,image,price,price_signed,amazon_url,status,sort) VALUES (?,?,?,?,?,?,?,?,?)');
    $p->execute([
        'ecos-del-pasado', 'Ecos del pasado: voces del silencio',
        '35 capítulos de poema y reflexión sobre el amor, los vínculos, la ausencia y lo que queda después de una ruptura.',
        'assets/img/portada.webp', 19.99, 29.99, 'https://www.amazon.com/dp/B0HNT4YBQP', 'disponible', 1,
    ]);
    $p->execute([
        'conexiones-eternas', 'Conexiones Eternas',
        'Segundo libro de la serie: vínculos que se separan y se reencuentran sin romperse.',
        '', 0, null, '', 'proximamente', 2,
    ]);

    $q = $pdo->prepare('INSERT INTO posts (type,title,slug,excerpt,body,youtube_url,published) VALUES (?,?,?,?,?,?,?)');
    $q->execute(['video', 'Perdón a mi corazón', 'perdon-a-mi-corazon', '', '', '', 1]);
    $q->execute(['video', 'Bienvenido noviembre', 'bienvenido-noviembre', '', '', '', 1]);
}

function default_settings(): array {
    return [
        // General
        'site_name' => 'José Luis Cornejo',
        'site_tagline' => 'AUTOR · FACILITADOR',
        'logo' => '',
        'meta_description' => 'José Luis Cornejo, facilitador y autor de «Ecos del pasado: voces del silencio». Desarrollo personal transgeneracional y sistémico.',
        'whatsapp' => '12526734147',
        'whatsapp_label' => 'WhatsApp Business · 1-252-673-4147',
        'facebook_url' => 'https://www.facebook.com/JoseLuisCornejoEcos/',
        'facebook_label' => 'Facebook · José Luis Cornejo: Ecos del Pasado, Voces del Silencio',
        'instagram_url' => 'https://www.instagram.com/joseluiscornejooficial',
        'instagram_label' => 'Instagram · @joseluiscornejooficial',
        'youtube_url' => 'https://www.youtube.com/@Joelouiscorne',
        'youtube_label' => 'YouTube · Joe louis corne',
        'footer_note' => 'Facilitador en desarrollo personal. No es un servicio clínico.',
        // Inicio
        'hero_badge' => 'NUEVO LIBRO · DISPONIBLE YA',
        'hero_title' => 'Ecos del pasado',
        'hero_title_red' => 'Voces del silencio',
        'hero_text' => '35 capítulos de poema y reflexión sobre el amor, los vínculos, la ausencia y lo que queda después de una ruptura.',
        'hero_tagline' => 'Cuando te rompen el corazón',
        'hero_button' => 'Comprar ejemplar firmado',
        'hero_note' => 'Tapa blanda · Pago seguro con tarjeta · Envío a todo EE. UU.',
        'hero_image' => 'assets/img/portada.webp',
        'quote' => '«Escribo y acompaño desde lo vivido, no desde la teoría.»',
        'about_kicker' => 'QUIÉN SOY',
        'about_title' => 'Facilitador y autor',
        'about_subtitle' => 'Desarrollo personal transgeneracional y sistémico',
        'about_text' => '<p>Mi trabajo une dos formas de decir lo mismo: el libro, que lo cuenta en poesía y reflexión, y la facilitación, que lo trabaja con las personas en pláticas y encuentros sobre los vínculos, lo que se hereda y cómo soltar en paz lo que pesa.</p>',
        'about_image' => 'assets/img/autor.webp',
        // Libros
        'books_title' => 'La serie',
        'books_text' => 'Poesía y reflexión sobre los vínculos.',
        // Facilitación
        'fac_title' => 'Acompañamiento en desarrollo personal',
        'fac_text' => '<p>Con enfoque sistémico y transgeneracional: mirar los vínculos, lo que se hereda y cómo soltar en paz lo que pesa.</p>',
        'fac_c1_title' => 'Los vínculos',
        'fac_c1_text' => 'Poner orden y honrar lo que fue, sin cortar a las personas.',
        'fac_c2_title' => 'Lo que se hereda',
        'fac_c2_text' => 'Una mirada transgeneracional a lo que llega de nuestra historia familiar.',
        'fac_c3_title' => 'Soltar en paz',
        'fac_c3_text' => 'Cerrar ciclos con respeto por lo vivido.',
        'talk_show' => '0',
        'talk_kicker' => 'PLÁTICA GRATUITA',
        'talk_title' => 'Los vínculos que permanecen después de una ruptura',
        'talk_text' => 'Lo que queda cuando una relación termina, cómo poner orden, honrar lo que fue y soltar en paz, sin cortar a las personas.',
        'talk_button' => 'Reservar mi lugar',
        // Escritos
        'posts_title' => 'Reflexiones, poemas y frases del día',
        'posts_text' => 'Textos nuevos y fragmentos que invitan a leer el libro completo.',
        // Contacto
        'contact_title' => 'Hablemos',
        'contact_text' => 'Para pláticas, encuentros, pedidos del libro o invitaciones, escríbeme por WhatsApp Business.',
        // Tienda / pagos
        'currency' => 'USD',
        'shipping' => '0.00',
        'pay_gateway' => 'stripe',
        'stripe_pk' => '',
        'stripe_sk' => '',
        'stripe_whsec' => '',
        'paypal_mode' => 'live',
        'paypal_client' => '',
        'paypal_secret' => '',
        // Correo
        'notify_email' => 'contacto@joseluiscornejo.com',
        'smtp_host' => 'smtp.hostinger.com',
        'smtp_port' => '465',
        'smtp_secure' => 'ssl',
        'smtp_user' => 'contacto@joseluiscornejo.com',
        'smtp_pass' => '',
        'smtp_from_name' => 'José Luis Cornejo',
    ];
}

<?php
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/mailer.php';
require_once __DIR__ . '/inc/payments.php';
require_once __DIR__ . '/views/partials.php';

$route = trim($_GET['r'] ?? '', '/');
$parts = $route === '' ? [''] : explode('/', $route);
$method = $_SERVER['REQUEST_METHOD'];

function render(string $view, array $vars = [], string $title = '', string $active = ''): void {
    extract($vars);
    $pageTitle = $title;
    $activeNav = $active;
    ob_start();
    require __DIR__ . '/views/' . $view . '.php';
    $content = ob_get_clean();
    require __DIR__ . '/views/layout.php';
    exit;
}

function not_found(): void {
    http_response_code(404);
    render('404', [], 'Página no encontrada');
}

switch ($parts[0]) {
    case '':
        $products = db()->query("SELECT * FROM products WHERE status <> 'oculto' ORDER BY sort, id")->fetchAll();
        $posts = db()->query('SELECT * FROM posts WHERE published = 1 ORDER BY created_at DESC, id DESC LIMIT 3')->fetchAll();
        render('home', compact('products', 'posts'), '', 'inicio');

    case 'libros':
        $products = db()->query("SELECT * FROM products WHERE status <> 'oculto' ORDER BY sort, id")->fetchAll();
        render('libros', compact('products'), 'Libros', 'libros');

    case 'facilitacion':
        render('facilitacion', [], 'Facilitación', 'facilitacion');

    case 'escritos':
        $type = in_array($_GET['tipo'] ?? '', ['escrito', 'video', 'frase'], true) ? $_GET['tipo'] : '';
        $sql = 'SELECT * FROM posts WHERE published = 1' . ($type ? ' AND type = ?' : '') . ' ORDER BY created_at DESC, id DESC';
        $st = db()->prepare($sql);
        $st->execute($type ? [$type] : []);
        $posts = $st->fetchAll();
        render('escritos', compact('posts', 'type'), 'Escritos y videos', 'escritos');

    case 'escrito':
        $st = db()->prepare('SELECT * FROM posts WHERE slug = ? AND published = 1');
        $st->execute([$parts[1] ?? '']);
        $post = $st->fetch();
        if (!$post) not_found();
        render('escrito', compact('post'), $post['title'], 'escritos');

    case 'contacto':
        if ($method === 'POST') {
            csrf_check();
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $msg = trim($_POST['message'] ?? '');
            if (!empty($_POST['website'])) redirect('contacto'); // anti-spam
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $msg === '') {
                flash('Completa nombre, correo válido y mensaje.', 'error');
                redirect('contacto#formulario');
            }
            db()->prepare('INSERT INTO messages (name, email, message) VALUES (?, ?, ?)')->execute([$name, $email, $msg]);
            notify_admin('Nuevo mensaje de ' . $name, '<p><b>De:</b> ' . e($name) . ' (' . e($email) . ')</p><p>' . nl2br(e($msg)) . '</p>', $email);
            flash('¡Gracias! Tu mensaje fue enviado.');
            redirect('contacto#formulario');
        }
        render('contacto', [], 'Contacto', 'contacto');

    case 'aviso':
        if ($method === 'POST') {
            csrf_check();
            $email = trim($_POST['email'] ?? '');
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                db()->prepare('INSERT IGNORE INTO subscribers (email, source) VALUES (?, ?)')->execute([$email, substr($_POST['source'] ?? 'web', 0, 60)]);
                notify_admin('Nuevo suscriptor', '<p>' . e($email) . ' quiere recibir avisos (' . e($_POST['source'] ?? 'web') . ').</p>');
                flash('¡Listo! Te avisaremos.');
            } else {
                flash('Escribe un correo válido.', 'error');
            }
        }
        redirect(($_POST['back'] ?? '') === 'libros' ? 'libros' : '#libros');

    case 'carrito':
        if ($method === 'POST') {
            csrf_check();
            $action = $_POST['action'] ?? '';
            if ($action === 'add') {
                cart_add((int)($_POST['pid'] ?? 0), $_POST['edition'] ?? 'estandar', (int)($_POST['qty'] ?? 1));
                if (!empty($_POST['buy'])) redirect('checkout');
            } elseif ($action === 'update') {
                foreach ((array)($_POST['qty'] ?? []) as $k => $q) cart_set((string)$k, (int)$q);
            } elseif ($action === 'remove') {
                cart_set((string)($_POST['key'] ?? ''), 0);
            }
            redirect('carrito');
        }
        $lines = cart_lines();
        $totals = cart_totals($lines);
        render('carrito', compact('lines', 'totals'), 'Carrito', 'libros');

    case 'checkout':
        $lines = cart_lines();
        if (!$lines) redirect('carrito');
        $totals = cart_totals($lines);
        if ($method === 'POST') {
            csrf_check();
            $f = array_map(fn($v) => trim((string)$v), $_POST);
            if (($f['name'] ?? '') === '' || !filter_var($f['email'] ?? '', FILTER_VALIDATE_EMAIL) || ($f['address'] ?? '') === '') {
                flash('Completa nombre, correo y dirección de envío.', 'error');
                redirect('checkout');
            }
            $code = 'JLC-' . strtoupper(bin2hex(random_bytes(3)));
            $gateway = s('pay_gateway') === 'paypal' ? 'paypal' : 'stripe';
            db()->prepare('INSERT INTO orders (code,name,email,phone,address,dedication,items,subtotal,shipping,total,currency,gateway) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$code, $f['name'], $f['email'], $f['phone'] ?? '', $f['address'], $f['dedication'] ?? '', json_encode($lines, JSON_UNESCAPED_UNICODE),
                    $totals['subtotal'], $totals['shipping'], $totals['total'], s('currency', 'USD'), $gateway]);
            $order = ['code' => $code, 'email' => $f['email']] + $totals;
            try {
                $payUrl = $gateway === 'paypal' ? paypal_create_order($order, $lines) : stripe_create_session($order, $lines);
                redirect($payUrl);
            } catch (Throwable $ex) {
                error_log($ex->getMessage());
                flash('No pudimos iniciar el pago en este momento. Escríbenos por WhatsApp y te ayudamos.', 'error');
                redirect('checkout');
            }
        }
        $hasSigned = (bool)array_filter($lines, fn($l) => $l['edition'] === 'Firmado y dedicado');
        render('checkout', compact('lines', 'totals', 'hasSigned'), 'Finalizar compra', 'libros');

    case 'pago':
        $code = null;
        try {
            if (($parts[1] ?? '') === 'stripe' && !empty($_GET['session_id'])) $code = stripe_verify_session($_GET['session_id']);
            if (($parts[1] ?? '') === 'paypal' && !empty($_GET['token'])) $code = paypal_capture($_GET['token']);
        } catch (Throwable $ex) {
            error_log($ex->getMessage());
        }
        if ($code) {
            $_SESSION['cart'] = [];
            $_SESSION['last_order'] = $code;
            redirect('gracias');
        }
        flash('El pago no se completó. Puedes intentarlo de nuevo.', 'error');
        redirect('carrito');

    case 'gracias':
        $code = $_SESSION['last_order'] ?? '';
        render('gracias', compact('code'), 'Gracias por tu compra');

    case 'webhook':
        if (($parts[1] ?? '') === 'stripe') stripe_webhook();
        exit;

    default:
        not_found();
}

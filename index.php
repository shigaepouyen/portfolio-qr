<?php
declare(strict_types=1);

// Serveur de développement PHP : servir les fichiers statiques, jamais data/ ni app/.
if (PHP_SAPI === 'cli-server') {
    $p = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
    if (preg_match('#^/(data|app)(/|$)#', $p)) {
        http_response_code(403);
        exit;
    }
    if ($p !== '/' && is_file(__DIR__ . $p)) {
        return false;
    }
}

require __DIR__ . '/app/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow, noimageindex');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$base = base_path();
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$parts = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));

if (($parts[0] ?? '') === 'admin') {
    if (($parts[1] ?? '') === 'api') {
        require ROOT . '/app/admin_api.php';
    } else {
        require ROOT . '/app/admin_page.php';
    }
    exit;
}

require ROOT . '/app/public.php';
render_public($parts);

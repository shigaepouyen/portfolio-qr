<?php
declare(strict_types=1);

/*
 * Portfolio QR : noyau (config, stockage JSON, authentification, images).
 * Aucune dépendance externe. PHP 8.1+ avec GD (WebP) et EXIF.
 */

define('ROOT', dirname(__DIR__));
define('DATA_DIR', ROOT . '/data');
define('MEDIA_DIR', ROOT . '/media');
define('STORE_FILE', DATA_DIR . '/portfolio.json');
define('AUTH_FILE', DATA_DIR . '/auth.json');
define('THROTTLE_FILE', DATA_DIR . '/throttle.json');
define('HISTORY_DIR', DATA_DIR . '/history');
define('HISTORY_KEEP', 20);

// Tailles générées, en pixels sur le plus grand côté.
const IMAGE_SIZES = ['s' => 640, 'm' => 1280, 'l' => 2560];
const WEBP_QUALITY = 82;
const RESERVED_SLUGS = ['admin', 'media', 'assets', 'data', 'app', 'api', 'index', 'index-php'];

$CONFIG = [
    // Adresse publique complète, sans slash final, si la détection automatique se trompe
    // (ex. derrière un proxy). Exemple : 'https://example.com/portfolio'
    'public_base_url' => '',
    'cookie_days' => 90,
];
if (is_file(ROOT . '/app/config.php')) {
    $CONFIG = array_merge($CONFIG, (array) require ROOT . '/app/config.php');
}

/* ---------- Utilitaires ---------- */

function base_path(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return rtrim($dir, '/');
}

function base_url(): string
{
    global $CONFIG;
    if ($CONFIG['public_base_url'] !== '') {
        return rtrim($CONFIG['public_base_url'], '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . base_path();
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function random_id(int $len = 12): string
{
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function slugify(string $s): string
{
    $s = trim($s);
    if (function_exists('transliterator_transliterate')) {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
        if (is_string($t)) {
            $s = $t;
        }
    } else {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        $s = strtolower($t !== false ? $t : $s);
    }
    $s = preg_replace('/[^a-z0-9]+/', '-', strtolower($s)) ?? '';
    return trim($s, '-');
}

function read_json(string $file, $default)
{
    if (!is_file($file)) {
        return $default;
    }
    $raw = file_get_contents($file);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : $default;
}

function write_json_atomic(string $file, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Encodage JSON impossible');
    }
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Écriture impossible dans data/');
    }
    rename($tmp, $file);
}

function ensure_dirs(): void
{
    foreach ([DATA_DIR, MEDIA_DIR, HISTORY_DIR] as $d) {
        if (!is_dir($d)) {
            mkdir($d, 0755, true);
        }
    }
}

/* ---------- Stockage du portfolio ---------- */

function default_store(): array
{
    return [
        'version' => 1,
        'site' => ['title' => '', 'subtitle' => 'Portfolio'],   // nom défini au premier lancement
        'access' => [
            'code' => random_id(10),   // adresse aléatoire, définitive
            'slug' => '',              // adresse lisible, optionnelle
            'oldSlugs' => [],          // anciens slugs, qui continuent de fonctionner
            'qr' => 'code',            // adresse encodée dans le QR : 'code' ou 'slug'
        ],
        'series' => [],
        'photos' => [],
    ];
}

function load_store(): array
{
    ensure_dirs();
    if (!is_file(STORE_FILE)) {
        $store = default_store();
        $store['series'][] = new_series($store, 'Sélection');
        write_json_atomic(STORE_FILE, $store);
        return $store;
    }
    return read_json(STORE_FILE, default_store());
}

/**
 * Modifie le portfolio sous verrou exclusif, garde une copie de la version précédente.
 * $fn reçoit le store par référence ; sa valeur de retour est renvoyée.
 */
function mutate_store(callable $fn)
{
    ensure_dirs();
    $lock = fopen(DATA_DIR . '/.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $store = load_store();
        $before = json_encode($store);
        $result = $fn($store);
        if (json_encode($store) !== $before) {
            backup_store();
            write_json_atomic(STORE_FILE, $store);
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function backup_store(): void
{
    if (!is_file(STORE_FILE)) {
        return;
    }
    copy(STORE_FILE, HISTORY_DIR . '/portfolio-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json');
    $files = glob(HISTORY_DIR . '/portfolio-*.json') ?: [];
    sort($files);
    while (count($files) > HISTORY_KEEP) {
        @unlink(array_shift($files));
    }
}

function new_series(array $store, string $title): array
{
    return [
        'id' => random_id(8),
        'title' => $title,
        'slug' => unique_series_slug($store, $title),
        'visible' => true,
        'cover' => null,
        'photos' => [],
    ];
}

function unique_series_slug(array $store, string $title, ?string $exceptId = null): string
{
    $base = slugify($title) ?: 'serie';
    $taken = [];
    foreach ($store['series'] as $s) {
        if ($s['id'] !== $exceptId) {
            $taken[] = $s['slug'];
        }
    }
    $slug = $base;
    $i = 2;
    while (in_array($slug, $taken, true)) {
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

function &find_series(array &$store, string $id): array
{
    foreach ($store['series'] as &$s) {
        if ($s['id'] === $id) {
            return $s;
        }
    }
    throw new InvalidArgumentException('Série introuvable');
}

function series_of_photo(array $store, string $photoId): ?string
{
    foreach ($store['series'] as $s) {
        if (in_array($photoId, $s['photos'], true)) {
            return $s['id'];
        }
    }
    return null;
}

/** Couverture effective : celle choisie, sinon la première photo. */
function series_cover(array $series): ?string
{
    if ($series['cover'] && in_array($series['cover'], $series['photos'], true)) {
        return $series['cover'];
    }
    return $series['photos'][0] ?? null;
}

/** Adresse publique demandée → vrai si elle correspond à ce portfolio. */
function access_matches(array $store, string $path): bool
{
    $a = $store['access'];
    if (hash_equals($a['code'], $path)) {
        return true;
    }
    $p = strtolower($path);
    return ($a['slug'] !== '' && $p === $a['slug']) || in_array($p, $a['oldSlugs'], true);
}

/** Nom affiché, avec repli neutre tant qu'il n'est pas défini. */
function site_name(array $store): string
{
    $t = trim((string) ($store['site']['title'] ?? ''));
    return $t !== '' ? $t : 'Portfolio';
}

function public_urls(array $store): array
{
    $a = $store['access'];
    $code = base_url() . '/' . $a['code'];
    $slug = $a['slug'] !== '' ? base_url() . '/' . $a['slug'] : null;
    $qr = ($a['qr'] === 'slug' && $slug) ? $slug : $code;
    return ['code' => $code, 'slug' => $slug, 'qr' => $qr];
}

/* ---------- Images ---------- */

function media_url(string $id, string $size): string
{
    return base_path() . '/media/' . $id . '-' . $size . '.webp';
}

function scaled_dims(int $w, int $h, int $max): array
{
    $ratio = min(1, $max / max($w, $h));
    return [max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio))];
}

/**
 * Traite un fichier envoyé : redresse, supprime les métadonnées (GD ne les recopie pas),
 * génère les trois tailles WebP. Renvoie les dimensions de la plus grande.
 */
function process_upload(string $tmpPath, string $id): array
{
    @ini_set('memory_limit', '768M');
    @set_time_limit(120);

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: '';
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        if (in_array($mime, ['image/heic', 'image/heif'], true)) {
            throw new InvalidArgumentException('Format HEIC non lu par le serveur. Exporte la photo en JPEG.');
        }
        throw new InvalidArgumentException('Format non pris en charge (JPEG, PNG ou WebP uniquement).');
    }
    $info = @getimagesize($tmpPath);
    if (!$info || $info[0] * $info[1] > 60_000_000) {
        throw new InvalidArgumentException('Image illisible ou trop grande.');
    }

    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmpPath),
        'image/png' => @imagecreatefrompng($tmpPath),
        'image/webp' => @imagecreatefromwebp($tmpPath),
    };
    if (!$img) {
        throw new InvalidArgumentException('Image illisible.');
    }

    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmpPath);
        $img = apply_orientation($img, (int) ($exif['Orientation'] ?? 1));
    }
    if (!imageistruecolor($img)) {
        imagepalettetotruecolor($img);
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $written = [];
    try {
        foreach (IMAGE_SIZES as $label => $max) {
            [$tw, $th] = scaled_dims($w, $h, $max);
            $out = imagecreatetruecolor($tw, $th);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagecopyresampled($out, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
            $file = MEDIA_DIR . '/' . $id . '-' . $label . '.webp';
            if (!imagewebp($out, $file, WEBP_QUALITY)) {
                throw new RuntimeException('Écriture de l\'image impossible.');
            }
            $written[] = $file;
            imagedestroy($out);
        }
    } catch (Throwable $t) {
        foreach ($written as $f) {
            @unlink($f);
        }
        throw $t;
    } finally {
        imagedestroy($img);
    }

    [$lw, $lh] = scaled_dims($w, $h, IMAGE_SIZES['l']);
    return ['w' => $lw, 'h' => $lh];
}

function apply_orientation(GdImage $img, int $o): GdImage
{
    switch ($o) {
        case 2: imageflip($img, IMG_FLIP_HORIZONTAL); break;
        case 3: $img = imagerotate($img, 180, 0); break;
        case 4: imageflip($img, IMG_FLIP_VERTICAL); break;
        case 5: imageflip($img, IMG_FLIP_HORIZONTAL); $img = imagerotate($img, 90, 0); break;
        case 6: $img = imagerotate($img, -90, 0); break;
        case 7: imageflip($img, IMG_FLIP_HORIZONTAL); $img = imagerotate($img, -90, 0); break;
        case 8: $img = imagerotate($img, 90, 0); break;
    }
    return $img;
}

function delete_media(string $id): void
{
    foreach (array_keys(IMAGE_SIZES) as $label) {
        @unlink(MEDIA_DIR . '/' . $id . '-' . $label . '.webp');
    }
}

/* ---------- Authentification ---------- */

const AUTH_COOKIE = 'pf_admin';

function auth_data(): array
{
    return read_json(AUTH_FILE, []);
}

function admin_configured(): bool
{
    return !empty(auth_data()['hash']);
}

function is_admin(): bool
{
    $auth = auth_data();
    $cookie = $_COOKIE[AUTH_COOKIE] ?? '';
    if (empty($auth['secret']) || !str_contains($cookie, '.')) {
        return false;
    }
    [$exp, $sig] = explode('.', $cookie, 2);
    if (!ctype_digit($exp) || (int) $exp < time()) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $exp, $auth['secret']), $sig);
}

function set_admin_cookie(): void
{
    global $CONFIG;
    $auth = auth_data();
    $exp = (string) (time() + $CONFIG['cookie_days'] * 86400);
    $value = $exp . '.' . hash_hmac('sha256', $exp, $auth['secret']);
    setcookie(AUTH_COOKIE, $value, cookie_options((int) $exp));
}

function clear_admin_cookie(): void
{
    setcookie(AUTH_COOKIE, '', cookie_options(time() - 3600));
}

function cookie_options(int $expires): array
{
    $https = str_starts_with(base_url(), 'https://');
    return [
        'expires' => $expires,
        'path' => (base_path() ?: '') . '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/** Définit le mot de passe et fait tourner le secret : toutes les sessions existantes tombent. */
function set_password(string $password): void
{
    if (mb_strlen($password) < 8) {
        throw new InvalidArgumentException('8 caractères minimum.');
    }
    write_json_atomic(AUTH_FILE, [
        'hash' => password_hash($password, PASSWORD_DEFAULT),
        'secret' => bin2hex(random_bytes(32)),
    ]);
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/** 5 essais ratés → blocage 15 minutes par adresse IP. */
function throttle_check(): void
{
    $t = read_json(THROTTLE_FILE, []);
    $entry = $t[client_ip()] ?? null;
    if ($entry && ($entry['until'] ?? 0) > time()) {
        $min = (int) ceil(($entry['until'] - time()) / 60);
        throw new InvalidArgumentException("Trop d'essais. Réessaie dans $min min.");
    }
}

function throttle_fail(): void
{
    $t = read_json(THROTTLE_FILE, []);
    $now = time();
    foreach ($t as $ip => $v) {
        if (($v['until'] ?? 0) < $now && ($v['last'] ?? 0) < $now - 3600) {
            unset($t[$ip]);
        }
    }
    $e = $t[client_ip()] ?? ['n' => 0, 'until' => 0, 'last' => 0];
    if (($e['last'] ?? 0) < $now - 3600) {
        $e['n'] = 0;
    }
    $e['n']++;
    $e['last'] = $now;
    if ($e['n'] >= 5) {
        $e['until'] = $now + 900;
        $e['n'] = 0;
    }
    $t[client_ip()] = $e;
    write_json_atomic(THROTTLE_FILE, $t);
}

function throttle_reset(): void
{
    $t = read_json(THROTTLE_FILE, []);
    unset($t[client_ip()]);
    write_json_atomic(THROTTLE_FILE, $t);
}

function verify_password(string $password): bool
{
    $auth = auth_data();
    return !empty($auth['hash']) && password_verify($password, $auth['hash']);
}

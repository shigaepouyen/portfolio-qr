<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): never
{
    respond(['error' => $message], $status);
}

function state_payload(): array
{
    $store = load_store();
    $photos = [];
    foreach ($store['photos'] as $id => $p) {
        $photos[$id] = $p + ['thumb' => media_url($id, 's'), 'large' => media_url($id, 'l')];
    }
    $store['photos'] = (object) $photos;
    return [
        'store' => $store,
        'urls' => public_urls($store),
        'maxUpload' => upload_limit_bytes(),
    ];
}

function upload_limit_bytes(): int
{
    $toBytes = function (string $v): int {
        $v = trim($v);
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
        };
    };
    return min($toBytes((string) ini_get('upload_max_filesize')), $toBytes((string) ini_get('post_max_size')));
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

// Protection CSRF : un en-tête personnalisé ne peut pas être envoyé par un autre site sans pré-vérification CORS.
if ($method === 'POST' && ($_SERVER['HTTP_X_PORTFOLIO'] ?? '') !== '1') {
    fail('Requête refusée.', 403);
}

$input = [];
if ($method === 'POST' && str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
}
$str = fn(string $k, int $max = 200): string => mb_substr(trim((string) ($input[$k] ?? '')), 0, $max);

try {
    /* ----- Accès public à l'API : état de connexion, création du mot de passe, connexion ----- */
    if ($action === 'status') {
        respond(['configured' => admin_configured(), 'loggedIn' => is_admin()]);
    }
    if ($action === 'setup' && $method === 'POST') {
        if (admin_configured()) {
            fail('Le mot de passe existe déjà.', 403);
        }
        $name = $str('title', 40);
        if ($name === '') {
            fail('Indique le nom à afficher sur le portfolio.');
        }
        set_password((string) ($input['password'] ?? ''));
        mutate_store(function (array &$store) use ($name) {
            $store['site']['title'] = $name;
        });
        set_admin_cookie();
        respond(['ok' => true]);
    }
    if ($action === 'login' && $method === 'POST') {
        throttle_check();
        if (!verify_password((string) ($input['password'] ?? ''))) {
            throttle_fail();
            usleep(400_000);
            fail('Mot de passe incorrect.', 401);
        }
        throttle_reset();
        set_admin_cookie();
        respond(['ok' => true]);
    }

    /* ----- Tout le reste exige d'être connectée ----- */
    if (!is_admin()) {
        fail('Session expirée, reconnecte-toi.', 401);
    }

    if ($action === 'state') {
        respond(state_payload());
    }
    if ($method !== 'POST') {
        fail('Méthode non autorisée.', 405);
    }

    switch ($action) {
        case 'logout':
            clear_admin_cookie();
            respond(['ok' => true]);

        case 'password':
            if (!verify_password((string) ($input['current'] ?? ''))) {
                fail('Mot de passe actuel incorrect.');
            }
            set_password((string) ($input['next'] ?? ''));
            set_admin_cookie();
            respond(['ok' => true]);

        case 'upload':
            $seriesId = (string) ($_POST['series'] ?? '');
            $file = $_FILES['photo'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                $code = $file['error'] ?? -1;
                fail($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
                    ? 'Fichier trop lourd pour le serveur.' : 'Envoi interrompu, réessaie.');
            }
            // Vérifie la série avant le traitement (coûteux).
            $store = load_store();
            find_series($store, $seriesId);
            $id = random_id(12);
            $dims = process_upload($file['tmp_name'], $id);
            mutate_store(function (array &$store) use ($seriesId, $id, $dims) {
                $s = &find_series($store, $seriesId);
                $store['photos'][$id] = ['caption' => '', 'w' => $dims['w'], 'h' => $dims['h'], 'added' => date('Y-m-d')];
                $s['photos'][] = $id;
            });
            break;

        case 'series.create':
            $title = $str('title', 80) ?: 'Nouvelle série';
            mutate_store(function (array &$store) use ($title) {
                $store['series'][] = new_series($store, $title);
            });
            break;

        case 'series.update':
            $id = $str('id');
            mutate_store(function (array &$store) use ($id, $input, $str) {
                $s = &find_series($store, $id);
                if (array_key_exists('title', $input)) {
                    $s['title'] = $str('title', 80) ?: $s['title'];
                    $s['slug'] = unique_series_slug($store, $s['title'], $id);
                }
                if (array_key_exists('visible', $input)) {
                    $s['visible'] = (bool) $input['visible'];
                }
                if (array_key_exists('cover', $input)) {
                    $cover = (string) $input['cover'];
                    if (!in_array($cover, $s['photos'], true)) {
                        throw new InvalidArgumentException('Cette photo n\'est pas dans la série.');
                    }
                    $s['cover'] = $cover;
                }
            });
            break;

        case 'series.delete':
            $id = $str('id');
            $removed = mutate_store(function (array &$store) use ($id) {
                $s = find_series($store, $id);
                foreach ($s['photos'] as $pid) {
                    unset($store['photos'][$pid]);
                }
                $store['series'] = array_values(array_filter($store['series'], fn($x) => $x['id'] !== $id));
                return $s['photos'];
            });
            foreach ($removed as $pid) {
                delete_media($pid);
            }
            break;

        case 'series.reorder':
            $ids = array_map('strval', (array) ($input['ids'] ?? []));
            mutate_store(function (array &$store) use ($ids) {
                $byId = array_column($store['series'], null, 'id');
                if (count($ids) !== count($byId) || array_diff($ids, array_keys($byId))) {
                    throw new InvalidArgumentException('Ordre invalide, recharge la page.');
                }
                $store['series'] = array_map(fn($id) => $byId[$id], $ids);
            });
            break;

        case 'photos.reorder':
            $sid = $str('series');
            $ids = array_map('strval', (array) ($input['ids'] ?? []));
            mutate_store(function (array &$store) use ($sid, $ids) {
                $s = &find_series($store, $sid);
                $a = $s['photos'];
                $b = $ids;
                sort($a);
                sort($b);
                if ($a !== $b) {
                    throw new InvalidArgumentException('Ordre invalide, recharge la page.');
                }
                $s['photos'] = $ids;
            });
            break;

        case 'photo.update':
            $pid = $str('id');
            $caption = $str('caption', 160);
            mutate_store(function (array &$store) use ($pid, $caption) {
                if (!isset($store['photos'][$pid])) {
                    throw new InvalidArgumentException('Photo introuvable.');
                }
                $store['photos'][$pid]['caption'] = $caption;
            });
            break;

        case 'photo.move':
            $pid = $str('id');
            $to = $str('to');
            mutate_store(function (array &$store) use ($pid, $to) {
                $from = series_of_photo($store, $pid);
                if ($from === null) {
                    throw new InvalidArgumentException('Photo introuvable.');
                }
                if ($from === $to) {
                    return;
                }
                $target = &find_series($store, $to);
                $target['photos'][] = $pid;
                unset($target);
                $src = &find_series($store, $from);
                $src['photos'] = array_values(array_diff($src['photos'], [$pid]));
                if ($src['cover'] === $pid) {
                    $src['cover'] = null;
                }
            });
            break;

        case 'photo.delete':
            $pid = $str('id');
            mutate_store(function (array &$store) use ($pid) {
                $sid = series_of_photo($store, $pid);
                if ($sid !== null) {
                    $s = &find_series($store, $sid);
                    $s['photos'] = array_values(array_diff($s['photos'], [$pid]));
                    if ($s['cover'] === $pid) {
                        $s['cover'] = null;
                    }
                }
                unset($store['photos'][$pid]);
            });
            delete_media($pid);
            break;

        case 'settings':
            $title = $str('title', 40);
            $subtitle = $str('subtitle', 60);
            $slug = strtolower($str('slug', 60));
            $qr = ($input['qr'] ?? 'code') === 'slug' ? 'slug' : 'code';
            if ($title === '') {
                fail('Le nom ne peut pas être vide.');
            }
            if ($slug !== '' && !preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38})[a-z0-9]$/', $slug)) {
                fail('Adresse lisible : 3 à 40 caractères, lettres minuscules, chiffres et tirets.');
            }
            if (in_array($slug, RESERVED_SLUGS, true)) {
                fail('Ce mot est réservé, choisis-en un autre.');
            }
            if ($qr === 'slug' && $slug === '') {
                $qr = 'code';
            }
            mutate_store(function (array &$store) use ($title, $subtitle, $slug, $qr) {
                $store['site']['title'] = $title;
                $store['site']['subtitle'] = $subtitle;
                $a = &$store['access'];
                if ($slug !== $a['slug']) {
                    // L'ancien slug reste valide : un QR déjà imprimé ne casse jamais.
                    if ($a['slug'] !== '' && !in_array($a['slug'], $a['oldSlugs'], true)) {
                        $a['oldSlugs'][] = $a['slug'];
                    }
                    $a['oldSlugs'] = array_values(array_diff($a['oldSlugs'], [$slug]));
                    $a['slug'] = $slug;
                }
                $a['qr'] = $qr;
            });
            break;

        case 'slug.forget':
            $old = strtolower($str('slug', 60));
            mutate_store(function (array &$store) use ($old) {
                $store['access']['oldSlugs'] = array_values(array_diff($store['access']['oldSlugs'], [$old]));
            });
            break;

        default:
            fail('Action inconnue.', 404);
    }

    respond(state_payload());
} catch (InvalidArgumentException $e) {
    fail($e->getMessage());
} catch (Throwable $e) {
    error_log('[portfolio] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail('Erreur serveur. Réessaie.', 500);
}

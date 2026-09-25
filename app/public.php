<?php
declare(strict_types=1);

function not_found(): void
{
    http_response_code(404);
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Introuvable</title>'
        . '<style>body{font:15px/1.5 system-ui,sans-serif;color:#555;display:grid;place-items:center;height:90vh;margin:0}</style>'
        . '</head><body>Page introuvable.</body></html>';
}

function render_public(array $parts): void
{
    $access = $parts[0] ?? '';
    if ($access === '' || count($parts) > 2) {
        not_found();
        return;
    }
    $store = load_store();
    if (!access_matches($store, $access)) {
        not_found();
        return;
    }

    // Aperçu : l'admin connectée voit aussi les séries masquées.
    $preview = isset($_GET['apercu']) && is_admin();
    $series = array_values(array_filter(
        $store['series'],
        fn($s) => ($preview || $s['visible']) && count($s['photos']) > 0
    ));
    $root = base_path() . '/' . $access;
    $q = $preview ? '?apercu' : '';

    header('Cache-Control: no-cache');

    if (isset($parts[1])) {
        foreach ($series as $s) {
            if ($s['slug'] === $parts[1]) {
                page_series($store, $s, $root, $q, count($series) > 1, $preview);
                return;
            }
        }
        not_found();
        return;
    }

    if (count($series) === 1) {
        page_series($store, $series[0], $root, $q, false, $preview);
        return;
    }
    page_index($store, $series, $root, $q, $preview);
}

function img_attrs(array $store, string $id, string $sizes): string
{
    $p = $store['photos'][$id];
    $srcset = [];
    foreach (IMAGE_SIZES as $label => $max) {
        [$w] = scaled_dims($p['w'], $p['h'], $max);
        $srcset[] = media_url($id, $label) . ' ' . $w . 'w';
    }
    [$mw, $mh] = scaled_dims($p['w'], $p['h'], IMAGE_SIZES['m']);
    return 'src="' . e(media_url($id, 'm')) . '" srcset="' . e(implode(', ', $srcset)) . '" sizes="' . e($sizes)
        . '" width="' . $mw . '" height="' . $mh . '" alt="' . e($p['caption'] ?? '') . '"';
}

function page_head(array $store, string $title): void
{
    $b = base_path();
    $v = '3';
    ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow,noimageindex">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#f6f5f2">
<meta property="og:title" content="<?= e(site_name($store)) ?>">
<title><?= e($title) ?></title>
<link rel="preload" href="<?= $b ?>/assets/fonts/jost.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $b ?>/assets/vendor/photoswipe.css?v=<?= $v ?>">
<link rel="stylesheet" href="<?= $b ?>/assets/site.css?v=<?= $v ?>">
</head>
<body>
<?php
}

function page_header(array $store, string $root, string $q, bool $preview): void
{
    ?>
<?php if ($preview): ?><div class="preview-bar">Aperçu · les séries masquées sont visibles</div><?php endif; ?>
<header class="masthead">
  <a class="brand" href="<?= e($root . $q) ?>"><?= e(site_name($store)) ?></a>
  <?php if (($store['site']['subtitle'] ?? '') !== ''): ?><span class="subtitle"><?= e($store['site']['subtitle']) ?></span><?php endif; ?>
</header>
<?php
}

function page_foot(): void
{
    $b = base_path();
    ?>
<script type="module" src="<?= $b ?>/assets/site.js?v=3"></script>
</body>
</html>
<?php
}

function page_index(array $store, array $series, string $root, string $q, bool $preview): void
{
    page_head($store, site_name($store));
    page_header($store, $root, $q, $preview);
    ?>
<main class="index">
  <?php if (!$series): ?>
    <p class="empty">Bientôt.</p>
  <?php endif; ?>
  <?php foreach ($series as $i => $s): $cover = series_cover($s); ?>
    <a class="series-card" href="<?= e($root . '/' . $s['slug'] . $q) ?>">
      <figure>
        <img <?= img_attrs($store, $cover, '(min-width: 900px) 45vw, 100vw') ?> <?= $i > 1 ? 'loading="lazy"' : 'fetchpriority="high"' ?> decoding="async">
        <figcaption>
          <span class="series-title"><?= e($s['title']) ?></span>
          <span class="series-count"><?= count($s['photos']) ?></span>
          <?php if (!$s['visible']): ?><span class="tag">masquée</span><?php endif; ?>
        </figcaption>
      </figure>
    </a>
  <?php endforeach; ?>
</main>
<?php
    page_foot();
}

function page_series(array $store, array $s, string $root, string $q, bool $hasIndex, bool $preview): void
{
    page_head($store, $s['title'] . ' · ' . site_name($store));
    page_header($store, $root, $q, $preview);
    ?>
<main class="series">
  <div class="series-head">
    <?php if ($hasIndex): ?><a class="back" href="<?= e($root . $q) ?>">Index</a><?php endif; ?>
    <h1><?= e($s['title']) ?><?php if (!$s['visible']): ?> <span class="tag">masquée</span><?php endif; ?></h1>
  </div>
  <div class="grid" id="gallery">
    <?php foreach ($s['photos'] as $i => $id):
        $p = $store['photos'][$id];
        $srcset = [];
        foreach (IMAGE_SIZES as $label => $max) {
            [$w] = scaled_dims($p['w'], $p['h'], $max);
            $srcset[] = media_url($id, $label) . ' ' . $w . 'w';
        }
    ?>
      <figure class="cell">
        <a href="<?= e(media_url($id, 'l')) ?>"
           data-pswp-width="<?= $p['w'] ?>" data-pswp-height="<?= $p['h'] ?>"
           data-pswp-srcset="<?= e(implode(', ', $srcset)) ?>"
           <?php if (($p['caption'] ?? '') !== ''): ?>data-caption="<?= e($p['caption']) ?>"<?php endif; ?>>
          <img <?= img_attrs($store, $id, '(min-width: 900px) 31vw, 100vw') ?> <?= $i > 1 ? 'loading="lazy"' : 'fetchpriority="high"' ?> decoding="async">
        </a>
        <?php if (($p['caption'] ?? '') !== ''): ?><figcaption><?= e($p['caption']) ?></figcaption><?php endif; ?>
      </figure>
    <?php endforeach; ?>
  </div>
</main>
<?php
    page_foot();
}

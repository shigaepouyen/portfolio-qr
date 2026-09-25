<?php
declare(strict_types=1);
$b = base_path();
$v = '4';
$name = site_name(load_store());
header('Cache-Control: no-store');
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#f6f5f2">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= e($name) ?>">
<link rel="apple-touch-icon" href="<?= $b ?>/assets/icon.png">
<link rel="icon" href="<?= $b ?>/assets/icon.png">
<title><?= e($name) ?> · gestion</title>
<link rel="stylesheet" href="<?= $b ?>/assets/admin.css?v=<?= $v ?>">
</head>
<body>
<div id="app" data-base="<?= e($b) ?>"><p class="loading">Chargement…</p></div>
<div id="toast" role="status" aria-live="polite"></div>
<script src="<?= $b ?>/assets/vendor/Sortable.min.js?v=<?= $v ?>"></script>
<script src="<?= $b ?>/assets/vendor/qrcode.js?v=<?= $v ?>"></script>
<script src="<?= $b ?>/assets/admin.js?v=<?= $v ?>"></script>
</body>
</html>

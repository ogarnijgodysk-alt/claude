<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex,nofollow">
<title><?= e(($title ?? '') !== '' ? $title . ' · ' . $siteName : $siteName) ?></title>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>

<?php

declare(strict_types=1);

/**
 * Espera, definidas pela página que faz o include:
 * @var array<string, mixed> $config
 * @var string $pageTitle
 * @var string|null $pageDescription
 * @var string|null $bodyClass
 */

$description = $pageDescription ?? $config['description'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= e($pageTitle) ?></title>
  <meta name="description" content="<?= e($description) ?>" />

  <meta property="og:type" content="website" />
  <meta property="og:title" content="<?= e($pageTitle) ?>" />
  <meta property="og:description" content="<?= e($description) ?>" />
  <meta property="og:site_name" content="<?= e($config['brand_name']) ?>" />

  <link rel="icon" href="<?= e(asset('favicon.png')) ?>" type="image/png" />
  <link rel="stylesheet" href="<?= e(asset('style.css')) ?>" />
</head>
<body class="<?= e($bodyClass ?? '') ?>">

<?php

declare(strict_types=1);

/**
 * Espera, definidas pela página que faz o include:
 * @var array<string, mixed> $config
 * @var string $pageTitle
 * @var string|null $pageDescription
 * @var string|null $bodyClass
 * @var string|null $pagePath  Caminho absoluto da página (ex.: '/precos.php').
 */

$description = $pageDescription ?? $config['description'];

/*
 * A URL canônica vem de $pagePath declarado pela página, não de
 * $_SERVER['REQUEST_URI'] — derivar do request traria query string para dentro
 * da canonical, que é justamente o que ela existe para evitar.
 */
$canonical = $config['site_url'] . ($pagePath ?? '/');

// Imagem de compartilhamento interina: reusa a screenshot do app (1349x592).
// Um asset dedicado de 1200x630 está registrado no backlog.
$ogImage = $config['site_url'] . '/' . asset('app-home.png');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-RNQM4DT19G"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', 'G-RNQM4DT19G');
  </script>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= e($pageTitle) ?></title>
  <meta name="description" content="<?= e($description) ?>" />

  <link rel="canonical" href="<?= e($canonical) ?>" />

  <meta property="og:type" content="website" />
  <meta property="og:title" content="<?= e($pageTitle) ?>" />
  <meta property="og:description" content="<?= e($description) ?>" />
  <meta property="og:site_name" content="<?= e($config['brand_name']) ?>" />
  <meta property="og:url" content="<?= e($canonical) ?>" />
  <meta property="og:locale" content="pt_BR" />
  <meta property="og:image" content="<?= e($ogImage) ?>" />
  <meta property="og:image:width" content="1349" />
  <meta property="og:image:height" content="592" />
  <meta property="og:image:alt" content="Tela inicial do <?= e($config['brand_name']) ?> com o resumo de despesas do grupo." />

  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="<?= e($pageTitle) ?>" />
  <meta name="twitter:description" content="<?= e($description) ?>" />
  <meta name="twitter:image" content="<?= e($ogImage) ?>" />

  <link rel="icon" href="/favicon.ico" sizes="any" />
  <link rel="icon" type="image/png" href="<?= e(asset('favicon.png')) ?>" />
  <link rel="stylesheet" href="<?= e(asset('style.css')) ?>" />
  <script src="<?= e(asset('nav.js')) ?>" defer></script>
</head>
<body class="<?= e($bodyClass ?? '') ?>">

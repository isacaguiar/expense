<?php

declare(strict_types=1);

/** @var array<string, mixed> $config */

/**
 * Arquivo da página corrente, para marcar o item ativo do menu.
 * Comparar por basename evita depender de query string ou do prefixo do domínio.
 */
$currentFile = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
?>
<header class="site-header">
  <div class="site-header-inner">
    <a class="logo" href="/" aria-label="<?= e($config['brand_name']) ?> — página inicial">
      <?php include __DIR__ . '/logo.php'; ?>
    </a>

    <button
      class="nav-toggle"
      type="button"
      aria-expanded="false"
      aria-controls="main-nav-list"
      aria-label="Abrir menu de navegação"
    >
      <span class="nav-toggle-bar" aria-hidden="true"></span>
      <span class="nav-toggle-bar" aria-hidden="true"></span>
      <span class="nav-toggle-bar" aria-hidden="true"></span>
    </button>

    <nav class="main-nav" aria-label="Navegação principal">
      <ul id="main-nav-list">
        <?php foreach ($config['nav'] as $item) : ?>
          <?php
            // Itens de âncora (ex.: /#como-funciona) têm path '/', cujo basename é
            // vazio — logo nunca são marcados como página corrente.
            $itemFile = basename((string) (parse_url($item['href'], PHP_URL_PATH) ?? ''));
            $isCurrent = $itemFile !== '' && $itemFile === $currentFile;
          ?>
          <li>
            <a
              href="<?= e($item['href']) ?>"
              <?= $isCurrent ? 'aria-current="page"' : '' ?>
            ><?= e($item['label']) ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    </nav>

    <div class="header-actions">
      <a class="link-action" href="<?= e($config['app_login_url']) ?>">Entrar</a>
      <a class="btn btn-primary btn-sm" href="<?= e($config['app_signup_url']) ?>">Cadastre-se</a>
    </div>
  </div>
</header>

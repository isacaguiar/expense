<?php

declare(strict_types=1);

/** @var array<string, mixed> $config */
?>
<header class="site-header">
  <div class="site-header-inner">
    <a class="logo" href="index.php" aria-label="<?= e($config['brand_name']) ?> — página inicial">
      <?php include __DIR__ . '/logo.php'; ?>
    </a>

    <nav class="main-nav" aria-label="Navegação principal">
      <ul>
        <?php foreach ($config['nav'] as $item) : ?>
          <li><a href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </nav>

    <div class="header-actions">
      <a class="link-action" href="<?= e($config['app_login_url']) ?>">Entrar</a>
      <a class="btn btn-primary btn-sm" href="<?= e($config['app_signup_url']) ?>">Cadastre-se</a>
    </div>
  </div>
</header>

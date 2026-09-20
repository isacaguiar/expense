<?php

declare(strict_types=1);

/** @var array<string, mixed> $config */
?>
  <footer class="legal-footer">
    <div class="legal-footer-inner">
      <span>&copy; <?= date('Y') ?> <?= e($config['legal_name']) ?></span>
      <nav aria-label="Links legais">
        <a href="privacidade.php">Política de Privacidade</a>
        <a href="termos.php">Termos de Serviço</a>
        <a href="https://novemax.com.br" target="_blank" rel="noopener" class="legal-footer-logo-link" aria-label="Novemax">
          <img src="<?= e(asset('logo-novemax.png')) ?>" alt="Novemax" class="legal-footer-logo" />
        </a>
      </nav>
    </div>
  </footer>
</body>
</html>

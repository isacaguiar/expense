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
        <a href="mailto:<?= e($config['contact_email']) ?>"><?= e($config['contact_email']) ?></a>
      </nav>
    </div>
  </footer>
</body>
</html>

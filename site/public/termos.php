<?php

declare(strict_types=1);

require __DIR__ . '/../src/helpers.php';
$config = require __DIR__ . '/../src/config.php';

$pageTitle = 'Termos de Serviço — ' . $config['legal_name'];
$pageDescription = 'Termos de uso do ' . $config['legal_name'] . '.';
$pagePath = '/termos.php';

require __DIR__ . '/../src/templates/header.php';
require __DIR__ . '/../src/templates/nav.php';
?>
<main class="page">
  <header class="doc-header">
    <h1>Termos de Serviço</h1>
    <p class="updated-at">Última atualização: <?= e(legal_updated_at('termos')) ?></p>
  </header>
<?php require __DIR__ . '/../src/legal/termos.php'; ?>
  <a class="back-link" href="index.php">&larr; Voltar</a>
</main>
<?php require __DIR__ . '/../src/templates/footer.php'; ?>

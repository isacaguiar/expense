<?php

declare(strict_types=1);

require __DIR__ . '/../src/helpers.php';
$config = require __DIR__ . '/../src/config.php';

$pageTitle = 'Política de Privacidade — ' . $config['legal_name'];
$pageDescription = 'Como o ' . $config['legal_name'] . ' coleta, usa e protege seus dados.';
$pagePath = '/privacidade.php';

require __DIR__ . '/../src/templates/header.php';
require __DIR__ . '/../src/templates/nav.php';
?>
<main class="page">
  <header class="doc-header">
    <h1>Política de Privacidade</h1>
    <p class="updated-at">Última atualização: <?= e(legal_updated_at('privacidade')) ?></p>
  </header>
<?php require __DIR__ . '/../src/legal/privacidade.php'; ?>
  <a class="back-link" href="index.php">&larr; Voltar</a>
</main>
<?php require __DIR__ . '/../src/templates/footer.php'; ?>

<?php

declare(strict_types=1);

require __DIR__ . '/../src/helpers.php';
$config = require __DIR__ . '/../src/config.php';

$pageTitle = 'Contato — ' . $config['brand_name'];
$pageDescription = 'Como falar com a equipe do ' . $config['brand_name']
    . ': dúvidas de uso, problemas na conta e questões sobre privacidade.';
$pagePath = '/contato.php';

$mailto = 'mailto:' . $config['contact_email'];

require __DIR__ . '/../src/templates/header.php';
require __DIR__ . '/../src/templates/nav.php';
?>
<main class="page">
  <header class="doc-header">
    <h1>Contato</h1>
    <p class="page-lede">
      Somos um time pequeno, e quem lê as mensagens é quem constrói o produto. Isso tende a
      dar respostas melhores — e um pouco menos rápidas que um suporte grande.
    </p>
  </header>

  <ul class="channel-list">
    <?php foreach ($config['contact_channels'] as $channel) : ?>
      <?php
        $href = $channel['href'] ?? $mailto;
        $linkLabel = $channel['link_label'] ?? $config['contact_email'];
      ?>
      <li class="channel">
        <?= icon($channel['icon']) ?>
        <div>
          <h2><?= e($channel['title']) ?></h2>
          <p><?= e($channel['text']) ?></p>
          <p><a href="<?= e($href) ?>"><?= e($linkLabel) ?></a></p>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>

  <section>
    <h2>O que incluir ao relatar um problema</h2>
    <p>
      Quanto mais específico, menos idas e vindas. Se puder, conte:
    </p>
    <ul>
      <li>O e-mail da conta em que o problema aconteceu.</li>
      <li>O que você estava tentando fazer, e o que aconteceu em vez disso.</li>
      <li>Em que tela — grupo, despesa, fechamento de ciclo, cobrança.</li>
      <li>Se foi no computador ou no celular, e qual navegador.</li>
      <li>Um print, se der. Costuma valer por vários parágrafos.</li>
    </ul>
    <p>
      <strong>Nunca envie a sua senha</strong> por e-mail. Ninguém da equipe vai pedir isso,
      e não precisamos dela para investigar nada.
    </p>
  </section>

  <section>
    <h2>Privacidade e seus dados</h2>
    <p>
      Pedidos sobre os seus dados pessoais — acesso, correção ou exclusão — podem ser feitos
      pelo mesmo e-mail. O que coletamos e por quê está na
      <a href="/privacidade.php">Política de Privacidade</a>.
    </p>
    <p>
      Esta página não tem formulário e não coleta nada: ela só te mostra para onde escrever.
    </p>
  </section>

  <a class="back-link" href="/">&larr; Voltar para a página inicial</a>
</main>
<?php require __DIR__ . '/../src/templates/footer.php'; ?>

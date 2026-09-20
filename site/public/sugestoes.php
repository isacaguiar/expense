<?php

declare(strict_types=1);

require __DIR__ . '/../src/helpers.php';
$config = require __DIR__ . '/../src/config.php';

$pageTitle = 'Sugestões — ' . $config['brand_name'];
$pageDescription = 'Como sugerir melhorias para o ' . $config['brand_name']
    . ' e o que acontece com a sua ideia depois que ela chega.';
$pagePath = '/sugestoes.php';

$mailto = 'mailto:' . $config['contact_email'] . '?subject=' . rawurlencode('Sugestão — ' . $config['brand_name']);

require __DIR__ . '/../src/templates/header.php';
require __DIR__ . '/../src/templates/nav.php';
?>
<main class="page">
  <header class="doc-header">
    <h1>Sugestões</h1>
    <p class="page-lede">
      Boa parte do que existe hoje no <?= e($config['brand_name']) ?> começou como incômodo
      de alguém que usava o produto. Se algo te irrita, queremos saber.
    </p>
  </header>

  <section>
    <h2>O que ajuda numa boa sugestão</h2>
    <p>
      Não precisa vir pronta nem bem escrita. O que faz diferença é entendermos o
      <strong>problema por trás da ideia</strong>:
    </p>
    <ul>
      <li><strong>O que você estava tentando fazer</strong> quando sentiu falta daquilo.</li>
      <li><strong>Como você resolve hoje</strong> — inclusive se a solução for uma planilha ou o grupo do WhatsApp.</li>
      <li><strong>Com que frequência</strong> isso acontece.</li>
    </ul>
    <p>
      "Queria um botão X" é útil. "Toda vez que fecho o mês eu preciso conferir Y na mão" é
      muito mais — porque abre espaço para uma solução melhor que a que você imaginou.
    </p>
  </section>

  <section>
    <h2>Para onde enviar</h2>
    <p>
      Escreva para <a href="<?= e($mailto) ?>"><?= e($config['contact_email']) ?></a>, de
      preferência com "Sugestão" no assunto.
    </p>
  </section>

  <section>
    <h2>O que acontece depois</h2>
    <p>
      Toda sugestão é lida e registrada, mesmo quando a resposta demora. A partir daí ela
      segue um de três caminhos:
    </p>
    <ul>
      <li><strong>Entra no planejamento</strong> — quando resolve um problema que vemos se repetir.</li>
      <li><strong>Fica anotada</strong> — quando faz sentido, mas ainda não é a coisa mais importante a fazer. Sugestões assim voltam à mesa quando alguém pede de novo.</li>
      <li><strong>Não entra</strong> — quando conflita com a proposta do produto. Nesse caso preferimos dizer isso, e o porquê, a deixar a sua ideia num limbo silencioso.</li>
    </ul>
    <p>
      O que não vamos fazer é prometer prazo. Um roadmap público com data vira dívida com
      quem leu, e preferimos não criar essa dívida.
    </p>
  </section>

  <section>
    <h2>Encontrou um defeito?</h2>
    <p>
      Se não é uma ideia nova, mas algo que está funcionando errado, o caminho é a
      <a href="/contato.php">página de contato</a> — lá tem a lista do que informar para
      acelerar a investigação.
    </p>
  </section>

  <a class="back-link" href="/">&larr; Voltar para a página inicial</a>
</main>
<?php require __DIR__ . '/../src/templates/footer.php'; ?>

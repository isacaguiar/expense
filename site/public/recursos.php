<?php

declare(strict_types=1);

require __DIR__ . '/../src/helpers.php';
$config = require __DIR__ . '/../src/config.php';

$pageTitle = 'Recursos — ' . $config['brand_name'];
$pageDescription = 'Grupos, despesas fixas e parceladas, divisão entre pagadores, '
    . 'fechamento de ciclo, acerto de contas e cobrança via Pix.';
$pagePath = '/recursos.php';

/*
 * Regra de redação desta página: nada aqui pode ser aspiracional. Cada item
 * corresponde a um fluxo descrito em docs/sdd/01-specify.md §3. Se uma
 * funcionalidade sair do produto, ela sai daqui junto.
 */
$blocks = [
    [
        'title' => 'Grupos e pessoas',
        'items' => [
            ['icon' => 'users', 'title' => 'Grupos para cada contexto', 'text' => 'Uma república, a família, a viagem com os amigos. Cada grupo tem os seus participantes e as suas despesas, sem se misturar.'],
            ['icon' => 'user-plus', 'title' => 'Convite por e-mail', 'text' => 'Convide alguém pelo e-mail. Quem ainda não tem conta recebe um link para definir a própria senha e já entra no grupo.'],
            ['icon' => 'settings', 'title' => 'Dia de fechamento por grupo', 'text' => 'Cada grupo define em que dia do mês o ciclo fecha, para bater com o calendário real das contas.'],
        ],
    ],
    [
        'title' => 'Despesas do jeito que elas acontecem',
        'items' => [
            ['icon' => 'layers', 'title' => 'Fixa, parcelada ou à vista', 'text' => 'Aluguel todo mês, a geladeira em 10x, o mercado de sábado. Os três tipos convivem no mesmo grupo.'],
            ['icon' => 'calculator', 'title' => 'Divisão entre os pagadores', 'text' => 'Você escolhe quem entra em cada despesa, e o valor é dividido igualmente entre essas pessoas — não entre o grupo inteiro.'],
            ['icon' => 'file-plus', 'title' => 'Parcelas com cronograma', 'text' => 'A despesa parcelada já nasce com todas as parcelas posicionadas nos meses certos.'],
        ],
    ],
    [
        'title' => 'Fechar o mês e acertar as contas',
        'items' => [
            ['icon' => 'pie-chart', 'title' => 'Saldo por pessoa', 'text' => 'Quem deve, quanto e para quem. O cálculo é refeito a cada mudança, sem planilha paralela.'],
            ['icon' => 'check-circle', 'title' => 'Fechamento de ciclo', 'text' => 'No fim da competência o ciclo fecha e vira histórico. O que ficou em aberto continua visível até ser acertado.'],
            ['icon' => 'bar-chart', 'title' => 'Histórico e relatórios', 'text' => 'Ciclos anteriores ficam consultáveis mês a mês, com o que foi gasto e como ficou o acerto.'],
        ],
    ],
    [
        'title' => 'Cobrar sem constrangimento',
        'items' => [
            ['icon' => 'dollar', 'title' => 'Pix com QR Code', 'text' => 'Cadastre a sua chave e o sistema gera o QR Code e o copia-e-cola com o valor certo. O pagamento acontece direto entre as pessoas, pelos bancos delas.'],
            ['icon' => 'credit-card', 'title' => 'Comprovante anexado', 'text' => 'Quem paga anexa o comprovante, e ele fica guardado junto da despesa — acabou o print perdido no chat.'],
            ['icon' => 'bell', 'title' => 'Avisos de pagamento', 'text' => 'Notificação no app quando alguém lança despesa, paga ou confirma um acerto. Por WhatsApp, para quem optar por receber.'],
        ],
    ],
];

require __DIR__ . '/../src/templates/header.php';
require __DIR__ . '/../src/templates/nav.php';
?>
<main>
  <section class="section">
    <div class="container">
      <div class="section-head">
        <h1 class="section-title">O que o <span class="accent"><?= e($config['brand_name']) ?></span> faz</h1>
        <p class="section-lede">
          Do primeiro convite ao último Pix: tudo que o grupo precisa para saber quem deve
          quanto, sem ninguém virar o cobrador oficial.
        </p>
      </div>

      <?php foreach ($blocks as $index => $block) : ?>
        <div class="resource-block">
          <h2 class="resource-block-title"><?= e($block['title']) ?></h2>
          <div class="feature-grid">
            <?php foreach ($block['items'] as $item) : ?>
              <div class="feature-card">
                <span class="icon-box"><?= icon($item['icon']) ?></span>
                <h3><?= e($item['title']) ?></h3>
                <p><?= e($item['text']) ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="cta-banner">
    <div class="container cta-banner-inner">
      <div class="cta-banner-heading">
        <?php $logoVariant = 'dark'; include __DIR__ . '/../src/templates/logo.php'; ?>
        <div>
          <h2>Tudo isso no plano Gratuito</h2>
          <p>Até <?= (int) $config['free_groups_limit'] ?> grupos criados por você, sem cobrança.</p>
        </div>
      </div>
      <div class="cta-banner-actions">
        <a class="btn btn-primary" href="<?= e($config['app_signup_url']) ?>">
          Criar minha conta grátis <?= icon('arrow-right') ?>
        </a>
        <a class="btn btn-on-dark" href="/precos.php">Ver os planos</a>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../src/templates/footer.php'; ?>

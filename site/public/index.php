<?php

declare(strict_types=1);

require __DIR__ . '/../src/helpers.php';
$config = require __DIR__ . '/../src/config.php';

$pageTitle = $config['brand_name'] . ' — ' . $config['tagline'];

// Dados ilustrativos do preview do dashboard (não vêm da API — é um mock visual da landing).
$dashExpenses = [
    ['icon' => 'building', 'name' => 'Aluguel', 'meta' => 'Fixa · Todo dia 05', 'value' => 'R$ 1.600,00', 'payer' => 'CA', 'payerName' => 'Carla', 'split' => '4 pessoas', 'status' => 'Paga'],
    ['icon' => 'wifi', 'name' => 'Internet', 'meta' => 'Fixa · Todo dia 15', 'value' => 'R$ 120,00', 'payer' => 'IA', 'payerName' => 'Isac', 'split' => '4 pessoas', 'status' => 'Paga'],
    ['icon' => 'shopping-cart', 'name' => 'Mercado', 'meta' => 'Variável · 10/05/2024', 'value' => 'R$ 320,50', 'payer' => 'MA', 'payerName' => 'Marcos', 'split' => '4 pessoas', 'status' => 'Pendente'],
];

$dashBalances = [
    ['initials' => 'IA', 'name' => 'Isac', 'value' => 'R$ 230,00', 'direction' => 'receive'],
    ['initials' => 'CA', 'name' => 'Carla', 'value' => 'R$ 150,00', 'direction' => 'receive'],
    ['initials' => 'MA', 'name' => 'Marcos', 'value' => 'R$ 80,00', 'direction' => 'receive'],
    ['initials' => 'JU', 'name' => 'Juliana', 'value' => 'R$ 460,00', 'direction' => 'pay'],
];

$sidebarItems = [
    ['icon' => 'home', 'label' => 'Resumo', 'active' => true],
    ['icon' => 'list', 'label' => 'Despesas', 'active' => false],
    ['icon' => 'users', 'label' => 'Participantes', 'active' => false],
    ['icon' => 'credit-card', 'label' => 'Pagamentos', 'active' => false],
    ['icon' => 'bar-chart', 'label' => 'Relatórios', 'active' => false],
    ['icon' => 'settings', 'label' => 'Configurações', 'active' => false],
];

$featureCards = [
    ['icon' => 'users', 'title' => 'Grupos ilimitados', 'text' => 'Crie quantos grupos quiser e organize diferentes despesas facilmente.'],
    ['icon' => 'layers', 'title' => 'Despesas fixas e variáveis', 'text' => 'Cadastre contas mensais ou despesas eventuais em poucos segundos.'],
    ['icon' => 'calculator', 'title' => 'Divisão automática', 'text' => 'O sistema divide os valores igualmente entre os membros e calcula tudo para você.'],
    ['icon' => 'dollar', 'title' => 'Saldos e pagamentos', 'text' => 'Veja quem pagou, quem deve e quem precisa receber de forma clara.'],
    ['icon' => 'bar-chart', 'title' => 'Relatórios inteligentes', 'text' => 'Acompanhe o histórico e tenha relatórios simples e completos.'],
    ['icon' => 'shield', 'title' => 'Seguro e privado', 'text' => 'Seus dados são protegidos com segurança de ponta e privacidade garantida.'],
];

$steps = [
    ['number' => 1, 'title' => 'Crie seu grupo', 'text' => 'Cadastre um grupo e convide as pessoas que vão compartilhar as despesas.', 'illustration' => 'user-plus'],
    ['number' => 2, 'title' => 'Adicione despesas', 'text' => 'Informe as despesas fixas ou variáveis e quem foi o responsável pelo pagamento.', 'illustration' => 'file-plus'],
    ['number' => 3, 'title' => 'Divisão e acompanhamento', 'text' => 'O sistema divide igualmente os valores e mostra quem deve, quem recebe e o saldo de cada um.', 'illustration' => 'smartphone'],
];

require __DIR__ . '/../src/templates/header.php';
require __DIR__ . '/../src/templates/nav.php';
?>
<main>
  <section class="hero">
    <div class="hero-inner">
      <div class="hero-copy">
        <h1>Despesas compartilhadas,<span class="accent">contas em dia.</span></h1>
        <p class="hero-lede"><?= e($config['description']) ?></p>

        <div class="hero-highlights" id="recursos">
          <div class="highlight">
            <span class="icon-box"><?= icon('users') ?></span>
            <div>
              <h3>Grupos</h3>
              <p>Crie grupos e convide amigos ou familiares.</p>
            </div>
          </div>
          <div class="highlight">
            <span class="icon-box"><?= icon('list') ?></span>
            <div>
              <h3>Despesas</h3>
              <p>Cadastre despesas fixas ou variáveis facilmente.</p>
            </div>
          </div>
          <div class="highlight">
            <span class="icon-box"><?= icon('pie-chart') ?></span>
            <div>
              <h3>Divisão igualitária</h3>
              <p>Divida os valores de forma automática.</p>
            </div>
          </div>
        </div>

        <div class="hero-ctas">
          <a class="btn btn-primary" href="<?= e($config['app_signup_url']) ?>">
            Começar agora é grátis <?= icon('arrow-right') ?>
          </a>
          <a class="btn btn-outline" href="#como-funciona">
            <?= icon('play') ?> Ver como funciona
          </a>
        </div>

        <p class="trust-line">
          <?= icon('shield') ?> Seguro e confiável <span class="dot">&bull;</span> Seus dados protegidos
        </p>
      </div>

      <div class="dash-card" aria-hidden="true">
        <aside class="dash-sidebar">
          <span class="logo">
            <?php include __DIR__ . '/../src/templates/logo.php'; ?>
          </span>
          <ul>
            <?php foreach ($sidebarItems as $item) : ?>
              <li class="<?= $item['active'] ? 'active' : '' ?>">
                <?= icon($item['icon']) ?> <?= e($item['label']) ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </aside>

        <div class="dash-main">
          <div class="dash-topbar">
            <h2>Resumo do grupo <span class="dash-group-select">Casa dos Amigos <?= icon('chevron-down') ?></span></h2>
            <div class="dash-topbar-right">
              <?= icon('bell') ?>
              <span class="dash-avatar">IA</span>
              Isac Aguiar <?= icon('chevron-down') ?>
            </div>
          </div>

          <div class="dash-stats">
            <div class="dash-stat">
              <div class="label">Total de despesas</div>
              <div class="value">R$ 3.240,00</div>
              <div class="sub">Este mês</div>
            </div>
            <div class="dash-stat">
              <div class="label">Pago</div>
              <div class="value">R$ 1.920,00</div>
              <div class="sub">59% do total</div>
            </div>
            <div class="dash-stat">
              <div class="label">A pagar</div>
              <div class="value pay">R$ 1.320,00</div>
              <div class="sub">41% do total</div>
            </div>
          </div>

          <div class="dash-panels">
            <div class="dash-panel">
              <h3>Despesas do mês</h3>
              <?php foreach ($dashExpenses as $expense) : ?>
                <div class="dash-expense-row">
                  <span class="dash-expense-icon"><?= icon($expense['icon']) ?></span>
                  <div>
                    <div class="dash-expense-name"><?= e($expense['name']) ?></div>
                    <div class="dash-expense-meta"><?= e($expense['meta']) ?></div>
                  </div>
                  <div class="dash-expense-paidby">
                    <span class="dash-avatar"><?= e($expense['payer']) ?></span>
                    Pago por <?= e($expense['payerName']) ?>
                  </div>
                  <div class="dash-expense-value">
                    <?= e($expense['value']) ?><br />
                    <span class="badge <?= $expense['status'] === 'Paga' ? 'badge-paid' : 'badge-pending' ?>">
                      <?= e($expense['status']) ?>
                    </span>
                  </div>
                </div>
              <?php endforeach; ?>
              <a class="dash-see-all" href="#">Ver todas as despesas</a>
            </div>

            <div class="dash-panel">
              <h3>Saldos por pessoa</h3>
              <?php foreach ($dashBalances as $balance) : ?>
                <div class="dash-balance-row">
                  <span class="dash-avatar"><?= e($balance['initials']) ?></span>
                  <span class="dash-balance-name"><?= e($balance['name']) ?></span>
                  <div class="dash-balance-value <?= $balance['direction'] ?>">
                    <?= e($balance['value']) ?>
                    <div class="dash-balance-sub"><?= $balance['direction'] === 'receive' ? 'A receber' : 'A pagar' ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section" id="beneficios">
    <div class="container">
      <h2 class="section-title">Tudo o que você precisa para <span class="accent">organizar as despesas</span></h2>
      <div class="feature-grid">
        <?php foreach ($featureCards as $card) : ?>
          <div class="feature-card">
            <span class="icon-box"><?= icon($card['icon']) ?></span>
            <h3><?= e($card['title']) ?></h3>
            <p><?= e($card['text']) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="section section-soft" id="como-funciona">
    <div class="container">
      <h2 class="section-title">Como funciona em <span class="accent">3 passos simples</span></h2>
      <div class="steps">
        <?php foreach ($steps as $step) : ?>
          <div class="step">
            <div class="step-number"><?= $step['number'] ?></div>
            <h3><?= e($step['title']) ?></h3>
            <p><?= e($step['text']) ?></p>
            <div class="step-illustration"><?= icon($step['illustration']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="cta-banner">
    <div class="container cta-banner-inner">
      <div class="cta-banner-heading">
        <?php include __DIR__ . '/../src/templates/logo.php'; ?>
        <div>
          <h2>Pronto para simplificar suas contas?</h2>
          <p>Comece agora mesmo e tenha controle total das despesas do seu grupo.</p>
        </div>
      </div>
      <div class="cta-banner-actions">
        <a class="btn btn-primary" href="<?= e($config['app_signup_url']) ?>">
          Criar minha conta grátis <?= icon('arrow-right') ?>
        </a>
        <a class="btn btn-on-dark" href="#beneficios">Saiba mais</a>
      </div>
      <div class="cta-banner-badges">
        <span><?= icon('check-circle') ?> Grátis para começar</span>
        <span><?= icon('check-circle') ?> Sem cartão de crédito</span>
        <span><?= icon('check-circle') ?> Cancelamento fácil</span>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../src/templates/footer.php'; ?>

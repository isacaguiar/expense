<?php

declare(strict_types=1);

require __DIR__ . '/../src/helpers.php';
$config = require __DIR__ . '/../src/config.php';

$pageTitle = 'Dividir despesas em grupo | ' . $config['brand_name'];
$pageDescription = 'Divida despesas de viagens, da casa ou da república. Escolha quem participa de cada gasto, veja quem deve a quem e acerte as contas via Pix.';
$pagePath = '/';
$bodyClass = 'home-page';

$featureCards = [
    // O texto anterior ("Grupos ilimitados") contradizia
    // MAX_GROUPS_CREATED_PER_USER = 3 no backend. O número vem do config para
    // não ser escrito solto aqui.
    ['icon' => 'users', 'title' => sprintf('Até %d grupos, de graça', $config['free_groups_limit']), 'text' => 'Crie um grupo para cada contexto — a casa, a viagem, a turma — e participe de quantos você for convidado.'],
    ['icon' => 'layers', 'title' => 'Despesas fixas e variáveis', 'text' => 'Cadastre contas mensais ou despesas eventuais em poucos segundos.'],
    ['icon' => 'calculator', 'title' => 'Divisão automática', 'text' => 'Escolha quem participa de cada despesa. O valor é dividido igualmente entre essas pessoas.'],
    ['icon' => 'dollar', 'title' => 'Saldos e pagamentos', 'text' => 'Veja quem pagou, quem deve e quem precisa receber de forma clara.'],
    ['icon' => 'bar-chart', 'title' => 'Relatórios inteligentes', 'text' => 'Acompanhe o histórico e tenha relatórios simples e completos.'],
    ['icon' => 'smartphone', 'title' => 'Acerto via Pix', 'text' => 'Use o QR Code ou Pix Copia e Cola. A transferência acontece direto entre os participantes.'],
];

$steps = [
    ['number' => 1, 'title' => 'Crie seu grupo', 'text' => 'Cadastre um grupo e convide as pessoas que vão compartilhar as despesas.', 'illustration' => 'user-plus'],
    ['number' => 2, 'title' => 'Adicione despesas', 'text' => 'Registre quem pagou e escolha quem participa da divisão de cada gasto.', 'illustration' => 'file-plus'],
    ['number' => 3, 'title' => 'Acerte as contas', 'text' => 'Veja quem deve, quanto e para quem. Se quiser, faça a transferência via Pix pelo seu banco.', 'illustration' => 'smartphone'],
];

require __DIR__ . '/../src/templates/header.php';
require __DIR__ . '/../src/templates/nav.php';
?>
<main>
  <section class="hero">
    <div class="hero-inner">
      <div class="hero-copy">
        <h1>Divida despesas em grupo <span class="accent">e saiba quem deve a quem</span></h1>
        <p class="hero-lede">Registre quem pagou cada conta, escolha quem entra na divisão e veja quanto cada pessoa precisa pagar ou receber. Para viagens, contas da casa e repúblicas.</p>

        <div class="hero-ctas">
          <a class="btn btn-primary" href="<?= e($config['app_signup_url']) ?>">
            Criar conta grátis <?= icon('arrow-right') ?>
          </a>
          <a class="btn btn-outline" href="#exemplo">Ver um exemplo</a>
        </div>

        <p class="hero-plan">
          Crie até <?= (int) $config['free_groups_limit'] ?> grupos grátis, sem cartão.<br />
          Participe de quantos grupos for convidado.
        </p>
      </div>

      <section class="settlement-example" id="exemplo" aria-labelledby="example-title" tabindex="-1">
        <p class="example-label">Exemplo de divisão</p>
        <h2 id="example-title">Fim de semana com amigos</h2>
        <p>Três pessoas dividiram os gastos da viagem igualmente.</p>
        <dl class="example-expenses">
          <div><dt>Ana pagou a hospedagem</dt><dd>R$ 600</dd></div>
          <div><dt>Bruno pagou as compras</dt><dd>R$ 300</dd></div>
          <div><dt>Carla não adiantou nada</dt><dd>R$ 0</dd></div>
        </dl>
        <p class="example-total"><strong>R$ 900</strong> no total · <strong>R$ 300</strong> por pessoa</p>
        <div class="example-result">
          <p><strong>Carla transfere R$ 300 para Ana.</strong></p>
          <p>Bruno já pagou a parte dele. O acerto pode ser feito via Pix.</p>
        </div>
      </section>
    </div>
  </section>

  <section class="section" id="beneficios">
    <div class="container" id="recursos">
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
      <p class="section-more">
        <a href="/recursos.php">Ver todos os recursos em detalhe <?= icon('arrow-right') ?></a>
      </p>
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

  <section class="section" aria-labelledby="preview-title">
    <div class="container">
      <h2 class="section-title" id="preview-title">Veja como o grupo <span class="accent">acompanha as despesas</span></h2>
      <figure class="product-preview">
        <img
          src="<?= e(asset('app-home.png')) ?>"
          width="1349"
          height="592"
          loading="lazy"
          decoding="async"
          alt="Painel do Shared Expense com totais do ciclo, lista de despesas e saldos por pessoa."
        />
      </figure>
    </div>
  </section>

  <section class="cta-banner">
    <div class="container cta-banner-inner">
      <div class="cta-banner-heading">
        <?php $logoVariant = 'dark'; include __DIR__ . '/../src/templates/logo.php'; ?>
        <div>
          <h2>Pronto para simplificar suas contas?</h2>
          <p>Comece agora mesmo e tenha controle total das despesas do seu grupo.</p>
        </div>
      </div>
      <div class="cta-banner-actions">
        <a class="btn btn-primary" href="<?= e($config['app_signup_url']) ?>">
          Criar minha conta grátis <?= icon('arrow-right') ?>
        </a>
        <a class="btn btn-on-dark" href="/precos.php">Ver os planos</a>
      </div>
      <div class="cta-banner-badges">
        <span><?= icon('check-circle') ?> Grátis para usar</span>
        <span><?= icon('check-circle') ?> Sem dados de pagamento</span>
        <span><?= icon('check-circle') ?> Cadastro por e-mail</span>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../src/templates/footer.php'; ?>

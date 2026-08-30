<?php

declare(strict_types=1);

require __DIR__ . '/../src/helpers.php';
$config = require __DIR__ . '/../src/config.php';

$pageTitle = $config['brand_name'] . ' — ' . $config['tagline'];

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

      <figure class="hero-preview">
        <img
          src="assets/app-home.png"
          width="1349"
          height="592"
          loading="lazy"
          decoding="async"
          alt="Tela inicial do Shared Expense: resumo do grupo com total de despesas, valores pagos e a pagar, a lista de despesas do ciclo e os saldos por pessoa."
        />
      </figure>
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

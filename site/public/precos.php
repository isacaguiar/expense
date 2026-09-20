<?php

declare(strict_types=1);

require __DIR__ . '/../src/helpers.php';
$config = require __DIR__ . '/../src/config.php';

$pageTitle = 'Preços — ' . $config['brand_name'];
$pageDescription = 'O ' . $config['brand_name'] . ' é gratuito para usar, com até '
    . $config['free_groups_limit'] . ' grupos criados por pessoa. Veja o que está incluso.';
$pagePath = '/precos.php';

require __DIR__ . '/../src/templates/header.php';
require __DIR__ . '/../src/templates/nav.php';
?>
<main>
  <section class="section">
    <div class="container">
      <div class="section-head">
        <h1 class="section-title">Comece grátis. <span class="accent">Pague só se precisar de mais.</span></h1>
        <p class="section-lede">
          O plano Gratuito não é uma demonstração: é o produto inteiro, com um limite de
          <?= (int) $config['free_groups_limit'] ?> grupos criados por pessoa. Você participa de
          quantos grupos for convidado, sem custo.
        </p>
      </div>

      <div class="plan-grid">
        <?php foreach ($config['plans'] as $plan) : ?>
          <article class="plan-card<?= $plan['highlight'] ? ' plan-card-highlight' : '' ?>">
            <header class="plan-head">
              <h2><?= e($plan['name']) ?></h2>
              <?php if ($plan['badge'] !== null) : ?>
                <span class="plan-badge"><?= e($plan['badge']) ?></span>
              <?php endif; ?>
            </header>

            <p class="plan-price">
              <strong><?= e($plan['price']) ?></strong>
              <span><?= e($plan['period']) ?></span>
            </p>

            <p class="plan-summary"><?= e($plan['summary']) ?></p>

            <ul class="plan-features">
              <?php foreach ($plan['features'] as $feature) : ?>
                <li>
                  <?= icon('check-circle') ?>
                  <span><?= e(sprintf($feature, $config['free_groups_limit'])) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>

            <?php /* Sem checkout: o Pro não é comprável, então todo CTA leva ao cadastro. */ ?>
            <a
              class="btn <?= $plan['highlight'] ? 'btn-primary' : 'btn-outline' ?> plan-cta"
              href="<?= e($config['app_signup_url']) ?>"
            ><?= e($plan['cta_label']) ?></a>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="section section-soft">
    <div class="container">
      <h2 class="section-title">Perguntas sobre os planos</h2>

      <div class="faq">
        <div class="faq-item">
          <h3>O plano Pro já está disponível?</h3>
          <p>
            Ainda não. Ele está publicado aqui para você saber o que vem pela frente e quanto
            vai custar. Não há como assiná-lo hoje, e nenhum recurso que você já usa deixou
            de funcionar por causa dele.
          </p>
        </div>

        <div class="faq-item">
          <h3>O que acontece quando eu chegar em <?= (int) $config['free_groups_limit'] ?> grupos?</h3>
          <p>
            Você continua usando normalmente os grupos que já tem — o limite é só para criar
            grupos novos. Participar de grupos criados por outras pessoas não conta para esse
            limite.
          </p>
        </div>

        <div class="faq-item">
          <h3>Vocês vão cobrar por algo que hoje é gratuito?</h3>
          <p>
            Não. O plano Pro adiciona coisas que não existem hoje. O que você já usa —
            histórico completo, relatórios, membros ilimitados por grupo, Pix — continua no
            plano Gratuito.
          </p>
        </div>

        <div class="faq-item">
          <h3>Preciso de cartão de crédito para começar?</h3>
          <p>
            Não. O cadastro é por e-mail, com um código de confirmação de 6 dígitos. Não
            pedimos dado de pagamento em nenhum momento.
          </p>
        </div>

        <div class="faq-item">
          <h3>Tenho uma dúvida que não está aqui.</h3>
          <p>
            Fale com a gente pela <a href="/contato.php">página de contato</a>, ou consulte o
            <a href="/manual.php">manual de uso</a>.
          </p>
        </div>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../src/templates/footer.php'; ?>

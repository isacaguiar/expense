<?php

declare(strict_types=1);

require __DIR__ . '/../src/helpers.php';
$config = require __DIR__ . '/../src/config.php';

$pageTitle = 'Termos de Serviço — ' . $config['legal_name'];
$pageDescription = 'Termos de uso do ' . $config['legal_name'] . '.';

require __DIR__ . '/../src/templates/header.php';
require __DIR__ . '/../src/templates/nav.php';
?>
<main class="page">
  <header class="doc-header">
    <h1>Termos de Serviço</h1>
    <p class="updated-at">Última atualização: <?= e($config['updated_at']) ?></p>
  </header>

  <section>
    <h2>1. Aceitação dos termos</h2>
    <p>
      Ao criar uma conta ou usar o <?= e($config['legal_name']) ?> ("o serviço"), você
      concorda com estes Termos de Serviço e com a nossa
      <a href="privacidade.php">Política de Privacidade</a>. Se você não concorda com estes
      termos, não utilize o serviço.
    </p>
  </section>

  <section>
    <h2>2. Descrição do serviço</h2>
    <p>
      O serviço permite criar grupos, registrar despesas compartilhadas, dividir custos entre
      participantes e gerar cobranças (incluindo via Pix) entre os membros de um grupo.
    </p>
  </section>

  <section>
    <h2>3. Conta e responsabilidades do usuário</h2>
    <ul>
      <li>Você é responsável por manter a confidencialidade das suas credenciais de acesso.</li>
      <li>Você é responsável pela exatidão das despesas, valores e informações de pagamento que lançar no serviço.</li>
      <li>Você concorda em não usar o serviço para fins ilegais ou para lançar informações falsas que prejudiquem outros participantes de um grupo.</li>
    </ul>
  </section>

  <section>
    <h2>4. Pagamentos e cobranças via Pix</h2>
    <p>
      O serviço pode facilitar a geração de cobranças via Pix entre participantes de um grupo.
      O serviço não processa nem custodia os pagamentos — a transação financeira ocorre
      diretamente entre os usuários através de suas próprias instituições financeiras. Não nos
      responsabilizamos por erros de digitação de valores ou dados de pagamento inseridos pelos
      usuários.
    </p>
  </section>

  <section>
    <h2>5. Disponibilidade do serviço</h2>
    <p>
      Fazemos esforços razoáveis para manter o serviço disponível, mas não garantimos operação
      ininterrupta ou livre de erros. O serviço pode passar por manutenções ou indisponibilidades
      temporárias.
    </p>
  </section>

  <section>
    <h2>6. Limitação de responsabilidade</h2>
    <p>
      Na máxima extensão permitida por lei, não nos responsabilizamos por perdas financeiras
      decorrentes de erros de lançamento de despesas, atrasos ou falhas em pagamentos realizados
      fora do serviço, ou uso indevido da conta por terceiros com acesso às suas credenciais.
    </p>
  </section>

  <section>
    <h2>7. Encerramento de conta</h2>
    <p>
      Você pode solicitar o encerramento da sua conta a qualquer momento. Podemos suspender ou
      encerrar contas que violem estes termos.
    </p>
  </section>

  <section>
    <h2>8. Alterações a estes termos</h2>
    <p>
      Podemos atualizar estes Termos de Serviço periodicamente. A data da última atualização é
      sempre indicada no topo desta página. O uso continuado do serviço após uma alteração
      representa concordância com os novos termos.
    </p>
  </section>

  <section>
    <h2>9. Contato</h2>
    <p>
      Dúvidas sobre estes Termos de Serviço podem ser enviadas para
      <a href="mailto:<?= e($config['contact_email']) ?>"><?= e($config['contact_email']) ?></a>.
    </p>
  </section>

  <a class="back-link" href="index.php">&larr; Voltar</a>
</main>
<?php require __DIR__ . '/../src/templates/footer.php'; ?>

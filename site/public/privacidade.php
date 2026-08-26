<?php

declare(strict_types=1);

require __DIR__ . '/../src/helpers.php';
$config = require __DIR__ . '/../src/config.php';

$pageTitle = 'Política de Privacidade — ' . $config['legal_name'];
$pageDescription = 'Como o ' . $config['legal_name'] . ' coleta, usa e protege seus dados.';

require __DIR__ . '/../src/templates/header.php';
require __DIR__ . '/../src/templates/nav.php';
?>
<main class="page">
  <header class="doc-header">
    <h1>Política de Privacidade</h1>
    <p class="updated-at">Última atualização: <?= e($config['updated_at']) ?></p>
  </header>

  <section>
    <h2>1. Quem somos</h2>
    <p>
      O <?= e($config['legal_name']) ?> ("nós", "o aplicativo") é um serviço para organizar
      grupos, despesas compartilhadas e cobranças entre participantes (incluindo geração de
      cobrança via Pix). Esta página descreve quais dados coletamos, para que os usamos e como
      você pode entrar em contato conosco sobre eles.
    </p>
  </section>

  <section>
    <h2>2. Quais dados coletamos</h2>
    <ul>
      <li><strong>Dados de conta:</strong> nome, e-mail e senha (ou identificador de login social, quando aplicável).</li>
      <li><strong>Dados de grupos e despesas:</strong> grupos que você cria ou participa, despesas lançadas, parcelas, categorias e participantes.</li>
      <li><strong>Dados de pagamento:</strong> informações necessárias para gerar cobranças via Pix e comprovantes de pagamento anexados por você.</li>
      <li><strong>Dados técnicos:</strong> registros de acesso e uso básicos, necessários para segurança e funcionamento do serviço.</li>
    </ul>
  </section>

  <section>
    <h2>3. Como usamos seus dados</h2>
    <p>
      Usamos os dados coletados exclusivamente para operar o aplicativo: autenticar seu acesso,
      exibir e calcular despesas e saldos dos grupos dos quais você participa, gerar cobranças
      Pix e permitir que os participantes de um grupo acompanhem o que é devido entre si.
    </p>
  </section>

  <section>
    <h2>4. Compartilhamento de dados</h2>
    <p>
      Não vendemos nem compartilhamos seus dados pessoais com terceiros para fins de publicidade.
      Dados de um grupo (despesas, saldos, participantes) são visíveis apenas para os membros
      daquele grupo. Podemos compartilhar informações estritamente necessárias com provedores que
      viabilizam funcionalidades do serviço (por exemplo, geração de cobrança Pix), sempre limitado
      ao necessário para a funcionalidade.
    </p>
  </section>

  <section>
    <h2>5. Retenção e exclusão</h2>
    <p>
      Mantemos seus dados enquanto sua conta estiver ativa. Registros de negócio excluídos (grupos,
      despesas) são marcados como removidos e deixam de aparecer no aplicativo. Solicitações de
      exclusão definitiva de conta ou dados pessoais podem ser feitas pelo contato abaixo.
    </p>
  </section>

  <section>
    <h2>6. Seus direitos</h2>
    <p>
      Você pode solicitar acesso, correção ou exclusão dos seus dados pessoais a qualquer momento,
      entrando em contato pelo e-mail informado abaixo.
    </p>
  </section>

  <section>
    <h2>7. Alterações a esta política</h2>
    <p>
      Podemos atualizar esta política periodicamente. A data da última atualização é sempre
      indicada no topo desta página.
    </p>
  </section>

  <section>
    <h2>8. Contato</h2>
    <p>
      Dúvidas sobre esta Política de Privacidade podem ser enviadas para
      <a href="mailto:<?= e($config['contact_email']) ?>"><?= e($config['contact_email']) ?></a>.
    </p>
  </section>

  <a class="back-link" href="index.php">&larr; Voltar</a>
</main>
<?php require __DIR__ . '/../src/templates/footer.php'; ?>

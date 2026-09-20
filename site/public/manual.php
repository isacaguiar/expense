<?php

declare(strict_types=1);

require __DIR__ . '/../src/helpers.php';
$config = require __DIR__ . '/../src/config.php';

$pageTitle = 'Manual de uso — ' . $config['brand_name'];
$pageDescription = 'Guia passo a passo: criar conta, montar o grupo, lançar despesas, '
    . 'entender a divisão, cobrar via Pix e fechar o ciclo.';
$pagePath = '/manual.php';

// O índice e as seções vêm da mesma lista, para não saírem de sincronia.
$steps = [
    'criar-conta' => 'Criar a sua conta',
    'criar-grupo' => 'Criar o grupo',
    'convidar' => 'Convidar as pessoas',
    'chave-pix' => 'Cadastrar a sua chave Pix',
    'lancar-despesa' => 'Lançar uma despesa',
    'entender-divisao' => 'Entender a divisão',
    'pagar' => 'Pagar e comprovar',
    'fechar-ciclo' => 'Fechar o ciclo',
    'historico' => 'Consultar o histórico',
];

require __DIR__ . '/../src/templates/header.php';
require __DIR__ . '/../src/templates/nav.php';
?>
<main class="page">
  <header class="doc-header">
    <h1>Manual de uso</h1>
    <p class="page-lede">
      Do cadastro ao primeiro acerto de contas. Se você só quer começar, os três primeiros
      passos bastam.
    </p>
  </header>

  <nav class="toc" aria-label="Índice do manual">
    <h2>Neste manual</h2>
    <ol>
      <?php foreach ($steps as $anchor => $label) : ?>
        <li><a href="#<?= e($anchor) ?>"><?= e($label) ?></a></li>
      <?php endforeach; ?>
    </ol>
  </nav>

  <section id="criar-conta">
    <h2>1. Criar a sua conta</h2>
    <p>
      O cadastro tem duas etapas. Na primeira você informa nome, e-mail, telefone (opcional)
      e senha. Na segunda, digita o código de 6 dígitos que chega por e-mail.
    </p>
    <p>
      O código vale <strong>15 minutos</strong>. Se ele expirar ou não chegar, peça um novo na
      própria tela — há um intervalo de 60 segundos entre pedidos. Sua conta só passa a
      existir depois que o código é confirmado, e você já entra logado.
    </p>
    <p>
      Também é possível entrar com a sua conta Google, sem criar senha.
    </p>
  </section>

  <section id="criar-grupo">
    <h2>2. Criar o grupo</h2>
    <p>
      Um grupo é o lugar onde as despesas compartilhadas vivem: a república, a casa, a
      viagem. Dê um nome, uma descrição opcional e escolha o <strong>dia de fechamento</strong>
      — o dia do mês em que aquele ciclo de contas se encerra.
    </p>
    <p>
      No plano Gratuito você cria até <?= (int) $config['free_groups_limit'] ?> grupos.
      Participar de grupos criados por outras pessoas não conta para esse limite.
    </p>
  </section>

  <section id="convidar">
    <h2>3. Convidar as pessoas</h2>
    <p>
      Dentro do grupo, adicione participantes pelo e-mail. Quem já tem conta passa a ver o
      grupo na hora. Quem ainda não tem recebe um e-mail com um link para definir a própria
      senha, e entra direto no grupo.
    </p>
  </section>

  <section id="chave-pix">
    <h2>4. Cadastrar a sua chave Pix</h2>
    <p>
      No seu perfil, informe a sua chave Pix. É ela que o sistema usa para gerar o QR Code
      quando alguém precisar te pagar.
    </p>
    <p>
      O pagamento em si acontece direto entre as pessoas, pelos bancos delas — o
      <?= e($config['brand_name']) ?> não recebe, não guarda e não repassa dinheiro.
    </p>
  </section>

  <section id="lancar-despesa">
    <h2>5. Lançar uma despesa</h2>
    <p>Ao criar uma despesa você escolhe o tipo:</p>
    <ul>
      <li><strong>Fixa</strong> — repete todo mês, como aluguel ou internet.</li>
      <li><strong>Parcelada</strong> — um valor dividido em N parcelas, já distribuídas nos meses seguintes.</li>
      <li><strong>À vista</strong> — acontece uma vez só, naquele mês.</li>
    </ul>
    <p>
      Depois indique <strong>quem pagou</strong> e <strong>quem são os pagadores</strong> — as
      pessoas que vão dividir aquele valor. Não precisa ser o grupo inteiro: a conta da
      internet pode ser de todos, e o jantar de sábado só de quem foi.
    </p>
  </section>

  <section id="entender-divisao">
    <h2>6. Entender a divisão</h2>
    <p>
      O valor é dividido <strong>igualmente entre os pagadores escolhidos</strong> naquela
      despesa. Não há peso por pessoa: se quatro pessoas entram numa despesa de R$ 200, cada
      uma deve R$ 50 a quem pagou.
    </p>
    <p>
      No resumo do grupo você vê o total do ciclo, quanto já foi pago, quanto falta, e o
      saldo de cada pessoa — quem deve e quem tem a receber.
    </p>
  </section>

  <section id="pagar">
    <h2>7. Pagar e comprovar</h2>
    <p>
      Quem deve usa o QR Code ou o copia-e-cola para pagar pelo próprio banco. Feito o
      pagamento, a despesa é marcada como paga e o <strong>comprovante pode ser anexado</strong>,
      ficando guardado junto do lançamento.
    </p>
    <p>
      As pessoas envolvidas recebem um aviso no app. Quem preencheu o telefone e optou por
      receber também é avisado por WhatsApp.
    </p>
  </section>

  <section id="fechar-ciclo">
    <h2>8. Fechar o ciclo</h2>
    <p>
      Chegado o dia de fechamento do grupo, o ciclo daquele mês se encerra e passa a ser
      histórico. O que ficou pendente não desaparece: continua visível até que o acerto seja
      confirmado pelas duas partes.
    </p>
  </section>

  <section id="historico">
    <h2>9. Consultar o histórico</h2>
    <p>
      Os ciclos anteriores ficam disponíveis mês a mês, com as despesas daquele período e
      como ficou o acerto entre as pessoas. É o registro para quando alguém perguntar
      "mas e em março?".
    </p>
  </section>

  <section id="duvidas">
    <h2>Perguntas frequentes</h2>

    <div class="faq">
      <div class="faq-item">
        <h3>Não recebi o código de confirmação.</h3>
        <p>
          Confira a caixa de spam. Se não estiver lá, peça um novo código na própria tela de
          confirmação — respeitando o intervalo de 60 segundos entre pedidos.
        </p>
      </div>

      <div class="faq-item">
        <h3>Dá para dividir uma despesa em partes desiguais?</h3>
        <p>
          Ainda não. A divisão é igual entre os pagadores escolhidos. O que dá para fazer é
          selecionar exatamente quem entra naquela despesa.
        </p>
      </div>

      <div class="faq-item">
        <h3>O sistema movimenta meu dinheiro?</h3>
        <p>
          Não. Ele gera a cobrança e organiza quem deve o quê. A transferência acontece
          diretamente entre as pessoas, pelas instituições financeiras delas.
        </p>
      </div>

      <div class="faq-item">
        <h3>Posso usar no celular?</h3>
        <p>
          Sim, pelo navegador do celular. As telas se adaptam à tela pequena.
        </p>
      </div>

      <div class="faq-item">
        <h3>Minha dúvida não está aqui.</h3>
        <p>
          Fale com a gente pela <a href="/contato.php">página de contato</a>. Se for uma ideia
          de melhoria, a <a href="/sugestoes.php">página de sugestões</a> é o lugar.
        </p>
      </div>
    </div>
  </section>

  <a class="back-link" href="/">&larr; Voltar para a página inicial</a>
</main>
<?php require __DIR__ . '/../src/templates/footer.php'; ?>

<?php

declare(strict_types=1);

/** @var array<string, mixed> $config — só o texto do documento; ver README.md deste diretório. */
?>

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
      <li>
        <strong>Dados de medição de uso (só com o seu consentimento):</strong> se você aceitar,
        usamos o Google Analytics para entender como o site e o aplicativo são usados — páginas
        e telas visitadas, origem da visita, tipo de dispositivo e navegador. Essa medição usa
        cookies. <strong>Enquanto você não aceitar, nenhum cookie de medição é criado e nenhum
        dado é enviado ao Google.</strong> Os endereços das telas são enviados sem identificadores:
        o endereço de uma despesa, por exemplo, é registrado como um padrão genérico, nunca com o
        número do seu grupo, da sua despesa, nem com dados que venham no endereço.
      </li>
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
    <p>
      Se — e somente se — você aceitar a medição de uso, os dados descritos no item 2 são tratados
      pelo <strong>Google</strong> (Google Analytics), que atua como operador desses dados e pode
      processá-los fora do Brasil. Nenhum dado de conta, grupo, despesa ou pagamento é enviado ao
      Google.
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
    <p>
      A medição de uso descrita no item 2 depende do seu consentimento, e esse consentimento é
      <strong>revogável a qualquer momento</strong>, sem prejuízo de nada: continuar usando o
      serviço não exige aceitá-la. Para rever ou mudar a sua escolha, use
      <strong>"Preferências de cookies"</strong>, no rodapé deste site, ou a seção
      <strong>Privacidade</strong> do seu perfil, dentro do aplicativo. Ao recusar, a medição
      deixa de acontecer a partir daquele momento.
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


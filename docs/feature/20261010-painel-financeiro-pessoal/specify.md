# Specify — Painel Financeiro Pessoal

> Feature: uma tela "Painel" que mostra, para a pessoa logada e para cada grupo dela, o que ela deve, o que tem a receber, o que já foi confirmado e o detalhe dos itens, no ciclo em foco, com as ações de confirmação de pagamento que o app já permite. Origem: pedido do usuário em 2026-10-10 ("tela de dashboard da situação financeira mensal por grupo da pessoa, inclusive apresentando se tem valores em aberto e possibilidade de confirmação de pagamento"). Não é migração de épico de `docs/sdd/03-tasks.md`.

Versão: 1.0 · Criado em: 20261010

---

## 1. Problema

O usuário não tem acesso fácil ao que deve e ao que já pagou, ao detalhe dos itens a pagar, a quem pagar e de quem receber, nem se há valores em aberto. Confirmado no código:

- **A tela chamada Dashboard não é financeira.** `/meus-grupos` (`frontend/src/App.tsx:64`) é administração de grupos. A árvore credor → devedores que cada linha expande (`GroupGrossDebtsPanel`) é do **grupo inteiro**, não da pessoa logada. Mostra só o que está em aberto, porque `ExpenseController::grossDebts` descarta o que já está pago (`ExpenseController.php:963`). O botão "Informar pagamento" é só estado visual local e nunca chama a API (`GroupGrossDebtsPanel.tsx:49-51`, fixado por `GroupGrossDebtsPanel.test.tsx:99`).
- **Home e Pagamentos de um grupo não destacam "eu".** Mostram os saldos e acertos de todos os membros, um grupo e um ciclo por vez (`useGroupCycle`). Só `PayableSettlementList` usa o usuário logado, para decidir quais botões aparecem.
- **O backend não tem visão por usuário entre grupos.** `GET /api/groups` (`GroupController.php:12-37`) não devolve valores, e `GET /api/dashboard` é um stub que devolve só o usuário (`AuthController.php:81-89`). Montar a tela só no cliente custaria 1 + 2N chamadas (`/groups`, depois `focus-cycle` e `summary` por grupo) e obrigaria o cliente a recompor rateio e ciclo. Isso contraria `docs/sdd/05-context-frontend.md` ("o cliente não duplica regra de negócio") e deixaria o app Flutter (`docs/sdd/07-context-mobile.md`) sem contrato pronto.
- **"Pago" tem três sentidos.** Parcela marcada paga pelo credor, acerto líquido confirmado com comprovante pelo devedor, e parcela retroativa nascida quitada (`bornPaid`). `gross-debts` e `settlements` divergem sobre o que o devedor deve (o acerto ignora `paid`; só `bornPaid` tira dívida dele, `ExpenseController.php` `computeCycleSummary`). Uma tela pessoal precisa fixar um número e rotulá-lo.

Consequência: quem participa de mais de um grupo abre cada um e navega ciclo a ciclo para saber se deve algo. O caso real de 2026-10-06 (setembro fechado, com acertos sem comprovante, que só aparecia navegando por setas; ver `docs/bugfix/concluidos/202610/20261006-acerto-comprovante-sem-cycles-ago.md`) é o tipo de situação que o painel deve deixar visível de imediato.

Referência visual: o mockup mobile (`assets/images/screen/movel.png`, tela "Meus grupos") já mostra, por grupo, "R$ 1.320,00 A pagar" ou "R$ 480,00 A receber".

## 2. Requisitos

Glossário (de `01-specify.md` e do código): **credor** = quem pagou a despesa (`user_payer_id`); **devedor** = participante que não é o credor; **acerto** = transferência líquida par a par entre dois membros no ciclo (`settlements`); **ciclo em foco** = o ciclo fechado ou em carência mais recente ainda com pendência, ou o vigente se não houver (a regra do `focus-cycle`, `ExpenseController::focusCycle`).

### 2.1 Endpoint de visão pessoal: `GET /api/me/overview`

Nome ajustável no `plan.md`. Somente leitura, sob `jwt.auth`.

- Devolve um item por grupo **não deletado em que o usuário logado é membro**, nunca grupos de outras pessoas. Usuário sem grupos recebe lista vazia.
- Cada item traz:
  - o grupo (`id`, `name`) e o **ciclo em foco**: `start`, `end`, `closes_at`, `status`, `settled` e **`cycles_ago`** (para o cliente nunca calcular ciclo);
  - **o que o usuário deve**: total, parte já confirmada com comprovante e parte em aberto, e a lista de acertos em que ele é devedor (credor com nome, avatar, e-mail e chave Pix, valor líquido, data e comprovante da confirmação, e `can_confirm`);
  - **o que o usuário tem a receber**: total, parte já confirmada e em aberto, e a lista de acertos em que ele é credor (devedor, valor, status do comprovante);
  - **os itens do ciclo de que participa**: despesa, data, valor da despesa, **parte dele** (valor por pessoa), credor, Paga ou Pendente, parcela (n de N), seu papel (`debtor` ou `creditor`) e `can_pay`/`can_unpay` (verdadeiros só quando ele é o credor);
  - `has_open` (há valor em aberto ou item pendente) e a contagem de itens pendentes.
- Ordenação: grupos com pendência primeiro, depois por nome.
- **Nunca grava**: não sela ciclo, não cria snapshot e não dispara notificação (diferente do ramo preguiçoso de `summary()`).
- Todas as flags (`can_confirm`, `can_pay`, `can_unpay`) e o `cycles_ago` vêm calculados do servidor, com as mesmas regras de `confirmSettlement`, `pay` e `unpay`.
- O contrato é único para o web e para o app Flutter.

### 2.2 Página "Painel" (frontend)

- Rota `/painel`, no layout sem grupo, com item de menu "Painel" (desktop e menu móvel). A landing pós-login não muda na v1 (ver D3).
- Um cartão por grupo: nome, chip de status do ciclo (os mesmos de hoje, e "Fecha em dd/mm" na carência), três totais (**A pagar**, **A receber**, **Já confirmado**), alerta quando há valor em aberto, e link "Ver ciclos" para Pagamentos e Relatórios do grupo.
- Ao abrir o cartão: "Você paga" (acertos em que é devedor), "Você recebe" (acertos em que é credor) e "Seus itens neste ciclo".
- Estados de carregamento, erro e vazio ("Você ainda não participa de nenhum grupo."). Depois de qualquer ação, a tela recarrega a visão.
- Responsivo (celular e desktop), em pt-BR, no padrão visual do projeto.

### 2.3 Ações de confirmação de pagamento (regras de hoje)

O painel não cria permissão nova; reaproveita as ações existentes:

| Papel | Ação no painel | Regra (já vigente) |
|---|---|---|
| Devedor | **Enviar comprovante** do acerto líquido | Só com o ciclo fechado (por data ou manualmente) e não selado; comprovante obrigatório; reenviar substitui (`confirmSettlement`) |
| Devedor | **Pagar via Pix** | Abre o diálogo de Pix com o valor do acerto (`PixPaymentDialog`); não grava nada |
| Credor | **Marcar como paga** / **Desfazer** uma despesa dele | Em qualquer ciclo, exceto futuro (`pay`/`unpay`); só o credor |

Não existe, e esta feature não cria: pagar apenas a própria parte de uma despesa (item 075), aprovação do comprovante pelo credor, nem desfazer a confirmação do acerto.

### 2.4 Semântica dos números

- **A pagar** = soma dos acertos líquidos em que o usuário é devedor. **Já confirmado** = a parte desses acertos que já tem comprovante. **Em aberto** = a diferença.
- **A receber** = soma dos acertos líquidos em que o usuário é credor, com a mesma divisão entre confirmado e em aberto.
- Os totais são o **valor líquido entre pares**, o mesmo de Pagamentos. Podem diferir da soma das partes dos itens, porque o acerto compensa dívidas nos dois sentidos. A tela rotula isso.
- Paga ou Pendente nos itens é só informação (o que o credor marcou); não altera o número de "A pagar".

### 2.5 Compatibilidade

- Mudança **aditiva** de API (Constitution §4.1): um endpoint novo. `summary`, `focus-cycle`, `gross-debts`, `cycles`, `pay`, `unpay` e `settlements/confirm` não mudam de contrato nem de comportamento.
- **Sem migration.**
- Sem evento de analytics novo; a rota nova já gera `page_view` pelo `RouteTracker` (`App.tsx:40`). Valores, ids de grupo e de usuário nunca vão para o Google (`docs/analytics/README.md`).

## 3. Fora de escopo desta feature

- Pagamento por parte de cada pessoa em cada despesa (item de backlog 075) e a quitação antecipada da parcelada (076). O contrato de `my_items[]` deixa espaço para campos aditivos depois.
- Relatórios de acompanhamento e de fechamento por grupo (feature `20261006-relatorios-acompanhamento-fechamento`).
- Qualquer mudança em "Meus Grupos", inclusive o botão "Informar pagamento" de `GroupGrossDebtsPanel`. Decidir depois que o Painel existir (backlog).
- Notificações ou cobrança automática (WhatsApp, e-mail) a partir do painel.
- Navegar por outros ciclos ou filtrar por mês dentro do painel (a navegação por ciclo continua em Pagamentos).
- Construir o app Flutter: esta feature só entrega o contrato que ele consumirá.
- Exportar ou imprimir o painel.

## 4. Regras adotadas

Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | "Confirmar pagamento" no painel segue **as regras de hoje** (§2.3). Decidido pelo usuário em 2026-10-10. |
| R2 | O endpoint é somente leitura: não sela, não grava snapshot e não notifica. |
| R3 | O escopo é estritamente o usuário logado: só grupos em que é membro e não deletados, filtrados na consulta, não no cliente. |
| R4 | O cliente não calcula ciclo, rateio nem permissão. Usa `cycles_ago` e as flags `can_*` que vêm do servidor. |
| R5 | Os acertos seguem as regras vigentes: confirmação só em ciclo fechado e não selado; o credor paga e desfaz em qualquer ciclo, exceto futuro. |
| R6 | Contrato aditivo (Constitution §4.1), sem migration, e único para web e app. |
| R7 | Valor nominal, sem juros nem desconto, formatado em pt-BR. |
| R8 | Toda lógica financeira nova tem teste PHPUnit (Constitution §2.2) e a rota declara quem acessa o quê, com checagem de membro (§1 item 6). |

## 5. Decisões em aberto

Fechadas na revisão deste specify: o usuário aprovou o documento em 2026-10-10, e com isso todas as recomendações abaixo (D1 a D8) passam a ser **adotadas**. A tabela fica como registro do porquê de cada escolha.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Número de "devo", "a receber" e "pago" | A) acerto líquido par a par, com status do comprovante<br>B) soma das parcelas marcadas pagas pelo credor | **A** | É o valor que a pessoa de fato transfere, o mesmo de Pagamentos. B divergiria do acerto e geraria dois "devo" diferentes. |
| D2 | Onde a tela entra | A) página nova `/painel` e item de menu<br>B) evoluir "Meus Grupos"<br>C) cartão na Home de um grupo | **A** | O pedido é entre grupos. "Meus Grupos" é administração (editar, excluir) e a Home vê um grupo só. |
| D3 | Landing pós-login | A) manter `/meus-grupos`<br>B) passar a `/painel` | **A** na v1 | Evita mexer nos redirects de login e nos testes. Promover o painel depois de validado. |
| D4 | Qual "mês" | A) ciclo em foco de cada grupo<br>B) mês calendário único | **A** | `closing_day` é por grupo; mês calendário contradiz o fechamento e a carência de 5 dias. |
| D5 | Grupo sem nada em aberto | A) aparece com "Em dia", abaixo dos pendentes<br>B) fica escondido | **A** | Responde "tenho algo em aberto?" de uma vez e confirma que o grupo foi considerado. |
| D6 | Mais de um ciclo pendente no mesmo grupo (ex.: agosto e setembro) | A) só o ciclo em foco, com link para Pagamentos<br>B) todos os ciclos pendentes de cada grupo | **A** | B exige varrer e calcular até 13 ciclos por grupo e se sobrepõe à aba Acompanhamento da feature de Relatórios. |
| D7 | Credor marca despesa paga no painel | A) foto do comprovante obrigatória, como em Pagamentos<br>B) um clique sem comprovante, como em Despesas | **A** | O painel é superfície de pagamento, e o comprovante deixa rastro. A API aceita os dois; é decisão de cliente. |
| D8 | Ordem frente à feature de Relatórios | A) Painel primeiro<br>B) Relatórios primeiro | **A** | Ataca "não vejo o que devo". Relatórios só tem specify e plan e herda a extração de cálculo de ciclo que o painel exige. |

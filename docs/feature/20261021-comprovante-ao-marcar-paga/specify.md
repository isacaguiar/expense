# Specify — Comprovante e descrição ao marcar como paga

> Feature: toda quitação de despesa feita pela interface web passa por um popup que pede o comprovante (foto) ou uma descrição do pagamento, e os dois popups de comprovante ganham um campo de texto. Pedido novo do usuário (conversa de 2026-10-21), sem épico correspondente em `docs/sdd/03-tasks.md`.

Versão: 1.0 · Criado em: 20261021

---

## 1. Problema

O usuário notou que, ao marcar uma despesa como paga, a tela **Despesas** não oferece anexar o comprovante. A investigação no git (2026-10-21) mostrou que **o anexo nunca foi removido** daquela tela:

- O ícone ✓ "Marcar como paga" faz `POST /api/expenses/{id}/pay` com um clique e sem corpo desde o TASK-177 (22/08, `35ed332286`).
- O anexo de foto nasceu só na tela **Pagamentos** (`8c5bd5670e`, 22/08). O commit registra que o `ExpenseManager` "continua chamando /pay sem corpo" e que o comprovante é "opcional na API de propósito" (`docs/feature/concluidas/202608/20260822-criacao-tela-pagamentos/plan.md` §2 e §6).
- O MANUAL (`MANUAL.md`, seção Pagamentos) descreve o anexo na tela Pagamentos, não na de Despesas.

O resultado prático é que o credor quita pela tela mais usada (Despesas) sem deixar nenhum registro do pagamento, e não há onde escrever uma informação como "pago em dinheiro", "Pix agendado" ou "ref. 12345". O pedido tem duas partes:

1. Verificar **todos** os momentos em que dá para marcar como paga sem abrir o popup de comprovante.
2. O popup passa a ter um campo de texto para descrever o pagamento.

Não contradiz `00-constitution.md`: a API muda de forma **aditiva** (§4.1, parâmetro e campos novos) e as migrations só acrescentam coluna nullable (§4.2). A regra nova ganha teste PHPUnit e Vitest.

## 2. Achados confirmados

### 2.1 Auditoria: momentos em que se quita sem popup

Busca em `backend/app`, `backend/routes` e `frontend/src` (sem testes). O único chamador de `POST /expenses/{id}/pay` no frontend é `usePaymentActions.handlePay`.

| # | Momento | Onde | Sem popup hoje? | Tratamento nesta feature |
|---|---|---|---|---|
| 1 | Ícone ✓ "Marcar como paga" na tabela e nos cartões da tela Despesas | `frontend/src/pages/ExpenseManager.tsx:465-476` (`renderExpenseActions`, usado em `:531` e `:742`) chama `handlePay(exp.id)` | **Sim, um clique** | **Corrigir**: abre o popup compartilhado |
| 2 | "Confirmar pagamento" (credor) na tela Pagamentos | `frontend/src/pages/Payments.tsx:278-288` e diálogo `:330-354` | Não (foto obrigatória) | Passa a usar o popup compartilhado |
| 3 | "Enviar comprovante" (devedor) em Valores a pagar | `frontend/src/pages/Payments.tsx:367-401`; endpoint `confirmSettlement` | Não (foto obrigatória) | Ganha o campo de descrição; foto segue obrigatória |
| 4 | Chamada direta à API | `POST /api/expenses/{id}/pay` (`backend/routes/api.php:60`); `comprovante` é `nullable` (`ExpenseController.php:1046`) | **Sim, qualquer cliente HTTP** | **Mantido permissivo** (D1) |
| 5 | Parcela retroativa que nasce quitada | `ExpenseController::store`, `:566-585` (`born_paid`, `paid_by = user_payer_id`) | Sim, automático na criação | **Fora de escopo** |
| 6 | Reenvio de `pay()` numa quota já paga | `pay()` aceita (`$wasPaid`), mas a UI não expõe: `canPay` exige `!exp.paid` (`usePaymentActions.ts:53-56`) | Só via API | Sem mudança; backlog |

Conclusão: na interface web, só o item 1 quita sem popup. Nenhum outro componente (`ExpenseView`, `CycleDetailPanel`, `Dashboard`, `Reports`) chama `/pay`. O item 4 é a única brecha restante e é deliberada.

### 2.2 Onde o comprovante e a descrição aparecem hoje

- Credor, na lista: ícone "Ver comprovante" só quando há foto (`ExpenseManager.tsx:425-438`), link no modal de detalhes (`:827-829`), link no cartão de Pagamentos (`Payments.tsx:269-275`) e em `ExpenseView.tsx:542-545`.
- Devedor: chip "Comprovante enviado" em `PayableSettlementList.tsx:86-99`, dependente de `confirmedProofUrl`.
- A foto é gravada via `ProofStorage` em disco privado por grupo e servida por URL assinada (`ADR-005`). Descrição não existe em nenhum lugar: não há coluna em `ex_quotas` nem em `ex_settlement_confirmations`.

## 3. Requisitos

### 3.1 Popup compartilhado de comprovante

Um único componente de popup, usado nos três pontos (Despesas ✓, Pagamentos credor, Pagamentos devedor), com seletor de foto e campo de texto multilinha.

### 3.2 Regras adotadas (não dependem de decisão)

| # | Regra |
|---|---|
| R1 | Popup do credor (Despesas e Pagamentos): `Confirmar` só habilita com foto **ou** descrição preenchida (texto não vazio após `trim`) |
| R2 | Popup do devedor: foto continua obrigatória (o backend já exige `required\|image`); descrição é opcional |
| R3 | Descrição: texto livre, no máximo **500** caracteres, `trim`; vazio é gravado como `null`. Exibida sempre como texto puro (React escapa), nunca como HTML |
| R4 | `unpay()` apaga também a descrição, como já apaga a foto |
| R5 | Reenviar o comprovante do devedor substitui foto e descrição (mesmo `updateOrCreate`) |
| R6 | Mudanças de API **aditivas**: parâmetro `descricao` em `pay` e `settlements/confirm`; campos `paymentNote` (entradas do summary) e `confirmedNote` (settlements). Migrations só com coluna nullable |
| R7 | A descrição aparece onde o comprovante aparece: modal de detalhes e cartão de Pagamentos (credor), `ExpenseView`, e junto do chip do acerto (devedor). Uma quota paga só com descrição não mostra o ícone "Ver comprovante" |
| R8 | Ciclos selados servem snapshot JSON antigo, sem `paymentNote`/`confirmedNote`: o front trata a ausência como `null` |

### 3.3 Decisões

| # | Decisão | Opções | Resolução | Por quê |
|---|---|---|---|---|
| D1 | O backend passa a exigir "foto OU descrição" em `/pay`? | A) Só o frontend exige. B) Backend também exige | **A**, adotada na aprovação do plano (2026-10-21) | `00-constitution.md` §4.1: tornar o contrato de um endpoint em produção mais restritivo é breaking. O app Flutter (`app/`, ADR-010) virá a consumir a API. Fechar a brecha do item 4 da §2.1 exigiria `/api/v2` ou depreciação assistida, em feature própria |
| D2 | O popup de Pagamentos (credor) deixa de exigir foto e passa a aceitar "foto OU descrição"? | A) Sim, um componente só. B) Mantém foto obrigatória lá | **A**, adotada na aprovação do plano | Mesma regra nas duas telas. É um relaxamento em relação a `plan.md` §6 da feature de 22/08 ("é aqui, e só aqui, que a foto vira obrigatória") |

Decisões já respondidas pelo usuário em 2026-10-21: regra do popup do credor = **foto OU descrição**; o popup do devedor também ganha descrição; parcela retroativa fica fora do escopo.

## 4. Fora de escopo desta feature

- **Parcela retroativa nascida quitada** (item 5 da §2.1): é regra de negócio automática, com aviso ao credor (`Notifier::expenseBornPaid`), não um "marcar como paga".
- **Fechar a brecha da API** (item 4): exigir foto ou descrição no servidor. Depende de `/api/v2` ou depreciação assistida (D1).
- **Anexar ou editar comprovante/descrição depois de a despesa estar paga** (item 6): hoje só se consegue desfazendo o pagamento e refazendo. Vira item em `docs/backlog/`.
- **Notificações** (in-app e WhatsApp) não levam a descrição; os templates da Meta não mudam.
- **Exigir foto ou descrição no acerto do devedor além da foto já obrigatória**: a regra do devedor (R2) não muda.

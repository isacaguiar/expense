# Tasks — Avatar de Usuário nas Listagens

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260904

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-001 | Expor `payerAvatarUrl`/`participantDetails`/`balances[].avatarUrl` em `computeCycleSummary`, com testes | backend | plan.md §1 | antes do merge | Concluída |
| TASK-002 | Criar componente `UserAvatar` (imagem + fallback iniciais + tooltip) e atualizar tipos do frontend (`SummaryExpense`, `SummaryBalance`, `GroupMember`, `GroupMemberPix`) | frontend | plan.md §2 | antes do merge | Concluída |
| TASK-003 | Aplicar `UserAvatar` na listagem de despesas / credor (`ExpenseManager.tsx`, `Payments.tsx`, `CycleDetailPanel.tsx`) | frontend | plan.md §3 | antes do merge | Concluída |
| TASK-004 | Aplicar `UserAvatar` nas abas Saldo e À pagar (`BalanceCards.tsx`, `SettlementList.tsx`, `PayableSettlementList.tsx`) | frontend | plan.md §4 | antes do merge | Concluída |
| TASK-005 | Aplicar `UserAvatar` no diálogo Pix de Pagamentos e no formulário de Entrada (`ExpenseForm.tsx`, `ExpenseView.tsx`) | frontend | plan.md §5 | antes do merge | Pendente |

TASK-001 e TASK-002 não dependem uma da outra. TASK-003 e TASK-004 dependem de TASK-001 **e** TASK-002 (precisam do campo novo do backend e do componente/tipo novos). TASK-005 depende só de TASK-002 (o dado de avatar da Entrada já vem hoje de `/members`, sem mudança de backend — ver `plan.md` §5).

## Critérios de aceite

- **TASK-001**:
  - `GET /api/groups/{id}/expenses/summary` (qualquer ciclo com despesas): cada item de `expenses[]` tem `payerAvatarUrl` (string com URL assinada quando o credor tem `photo_path`/`avatar_url`, `null` quando não tem) e `participantDetails` (array com `id`+`name`+`avatarUrl` de cada participante, mesmo conteúdo de pessoas que `participants` já lista, só que com id e avatar).
  - Cada item de `balances[]` tem `avatarUrl` no mesmo formato.
  - `participants` (string[]), `settlements[]`, `totals`, `cycle` continuam exatamente como estão hoje — nenhum campo removido ou com tipo alterado (Constitution §4.1).
  - Teste automatizado cobrindo: membro com foto própria (`photo_path`), membro só com `avatar_url` do Google, membro sem nenhuma das duas (`null` nos 3 campos novos).
  - `./vendor/bin/pint --test` e `php artisan test` (suite completa, sem regressão) verdes.

- **TASK-002**:
  - `UserAvatar` renderiza a imagem (`<img>` com o `src` recebido) quando `avatarUrl` não é `null`/`undefined`; renderiza as iniciais (`getInitials(name)`) quando é `null`/`undefined`; um `Tooltip` acessível com o `name` aparece ao hover sobre o avatar (verificável via `getByRole('tooltip')`/`title` do MUI em teste com `@testing-library/react`).
  - `SummaryExpense.payerAvatarUrl`, `SummaryExpense.participantDetails`, `SummaryBalance.avatarUrl`, `GroupMember.avatarUrl` (ExpenseForm/ExpenseView) e `GroupMemberPix.avatarUrl` (Payments) declarados e populados a partir do campo da API correspondente (`avatar_url` → `avatarUrl` nos tipos locais que consomem `/members`).
  - `npx tsc --noEmit` limpo; `npm run test -- UserAvatar` (ou equivalente) verde.

- **TASK-003**: nas 3 telas (Despesas cards/tabela/modal, Payments "Despesas do ciclo", Home do grupo/`CycleDetailPanel`), abrir um grupo com um membro que tem foto cadastrada e um que não tem — o credor com foto aparece com a imagem, o sem foto aparece com o círculo de iniciais, e passar o mouse sobre qualquer um dos dois mostra o nome em tooltip. `npx tsc --noEmit` limpo.

- **TASK-004**: nas abas Saldo e À pagar (`SummarySidePanel`, tanto em `ExpenseManager` quanto na Home do grupo) e na tela de Pagamentos ("Valores a pagar"), mesmo teste visual da TASK-003 (foto vs. iniciais vs. tooltip) para os avatares de origem e destino de cada linha de liquidação e de cada card de saldo. `npx tsc --noEmit` limpo.

- **TASK-005**: no diálogo de Pix (Pagamentos) e no formulário de criar/editar despesa (campo "Pagador" e lista de participantes, incluindo o modo leitura de `ExpenseView`), mesmo teste visual (foto vs. iniciais vs. tooltip) para cada membro listado. `npx tsc --noEmit` limpo.

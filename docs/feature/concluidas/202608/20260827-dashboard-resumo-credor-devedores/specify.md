# Specify — Dashboard: resumo Credor→devedores por grupo

> Feature: cada linha de grupo em `Dashboard.tsx` ganha uma linha expansível mostrando quem deve o quê a quem na competência vigente daquele grupo (valores brutos, não líquidos), com ação de copiar/pagar via Pix. Origem: promoção do item de backlog `031` (`docs/backlog/dashboard-visao-financeira-credor-devedores.md`), cuja decisão de produto já havia sido confirmada pelo usuário em 2026-08-21 numa branch depois descartada (`backend/20260821-regras-mensalidade-entrada-despesas`) e nunca implementada.

Versão: 1.0 · Criado em: 20260827

---

## 1. Problema

`Dashboard.tsx` (primeira tela após login, rota `/meus-grupos`) hoje só lista os grupos do usuário numa tabela (Nome, Responsável, Integrantes, Ações) — nenhum saldo ou valor devido aparece ali, mesmo esse dado já existindo e sendo calculado para cada grupo (`GroupSummary.tsx`/`ExpenseController::summary`). Para saber "quem me deve o quê" ou "quanto eu devo", o usuário precisa entrar em cada grupo individualmente.

**Reenquadramento em relação ao item original**: quando o item 031 foi registrado (2026-08-22), `Dashboard.tsx` usava cards de grupo. Desde então (feature `atualizacao-layout-paginas`), o Dashboard passou a usar uma tabela (`Table`/`TableRow`, ver `Dashboard.tsx:155-213`) — o layout de card não existe mais. Esta feature adapta a ideia original (decisão confirmada com o usuário ao promover este item, 2026-08-27): a árvore Credor→devedores vira uma **linha expansível** por grupo na tabela existente, não um card novo — evita reverter uma mudança de UI já feita por outra feature.

## 2. Requisitos

### 2.1 Endpoint de resumo bruto por grupo

Novo endpoint `GET /groups/{groupId}/expenses/gross-debts` (nome provisório — pode ajustar no `plan.md`), devolvendo, para a competência vigente do grupo (`BillingCycle::cycleFor($group->closing_day, now())`, mesmo conceito de competência já usado em `summary()`/`GroupReports` — não mês calendário puro, apesar do item original dizer "mês corrente"), uma árvore por credor: para cada `user_payer_id` de despesa do ciclo, a lista de devedores (outros participantes) e o valor bruto que cada um ainda deve a ele.

- "Bruto" = soma das parcelas de despesas (`Quota.value_quota`/participação por pessoa) daquele devedor para aquele credor **ainda não pagas** (`paid = false`) no ciclo — sem o netting que `computeCycleSummary`/`settlements` já faz (que soma saldos líquidos e sugere o menor número de transferências). Os dois cálculos coexistem: `summary()` continua servindo `GroupSummary`/`Payments` (visão líquida), este endpoint serve só o resumo do Dashboard (visão bruta, por despesa).
- Reaproveita `ExpenseController::collectCycleEntries($groupId, $start, $end)` (já usado por `computeCycleSummary`) como fonte das despesas do ciclo — mesma base de dado, agregação diferente (sem netting, agrupado por par credor→devedor, só entradas `paid = false`).
- Mesma autorização de `summary()` (`authorizeGroupMembership`), chamado uma vez por grupo listado no Dashboard.

### 2.2 Linha expansível no Dashboard

- Cada `TableRow` de grupo (`Dashboard.tsx:167-210`) ganha um botão de expandir/colapsar (ex.: `IconButton` com seta, padrão MUI de "collapsible table row").
- Expandido, mostra a árvore Credor→devedores daquele grupo (indentada: credor como nó pai, devedores como filhos, com o valor bruto de cada um) para a competência vigente.
- Navegação de competência **por linha expandida** (setas anterior/próximo, mesmo padrão de `cyclesAgo` já usado em `useGroupCycle`) — cada grupo navega seu próprio ciclo independentemente dos outros, sem afetar a tabela inteira.
- Cada devedor da árvore tem: nome, valor bruto devido, e um ícone de "pagar via Pix" que abre `PixPaymentDialog` (`frontend/src/components/PixPaymentDialog.tsx`, já existente e usado em `Payments.tsx`) mirado no credor daquele ramo — reaproveitado sem modificação.
- "Informar pagamento": ação só de UI (ex.: marca visualmente como "informado" no estado local do componente) — **não** persiste nada; `Quota.paid` continua sem endpoint de escrita a partir daqui (mesma decisão já confirmada quando o item foi registrado — marcar como pago de fato continua exclusivo do credor, via `POST /expenses/{id}/pay`, de dentro do grupo).

### 2.3 Carregamento sob demanda

O resumo bruto de um grupo só é buscado (`GET .../gross-debts`) quando a linha é expandida pela primeira vez (não numa chamada em lote ao carregar o Dashboard) — evita N requisições simultâneas para grupos que o usuário nunca expande, especialmente relevante se a lista de grupos crescer.

## 3. Fora de escopo desta feature

- Persistir "informar pagamento" ou qualquer escrita em `Quota.paid` a partir do Dashboard — decisão de produto já tomada (ver §2.2); confirmar pagamento continua fluxo do credor dentro do grupo.
- Alterar `computeCycleSummary`/`summary()`/`GroupReports` para usar o cálculo bruto — os dois cálculos (líquido vs. bruto) coexistem para propósitos diferentes.
- Reverter `Dashboard.tsx` para layout de cards — decisão já tomada ao promover o item (ver §1, Reenquadramento).
- Mudar o limite de busca/paginação da lista de grupos do Dashboard — fora do escopo deste achado.
- Item de backlog `020` (notificações) — nenhuma notificação nova é criada por esta feature.

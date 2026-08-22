# Tasks — Reestruturação do Resumo do Grupo

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260822

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-001 | Grid de duas colunas em `GroupSummary.tsx` (despesas + coluna lateral, breakpoints `xs`/`sm`/`lg`) | frontend | plan.md §1 | nenhum | Concluída |
| TASK-002 | Criar `SummarySidePanel.tsx` com abas Saldo/À pagar (reaproveitando `BalanceCards`/`SettlementList`) e integrar em `GroupSummary.tsx` | frontend | plan.md §2 | nenhum | Concluída |
| TASK-003 | Selo Prévia/Definitivo no `SummarySidePanel` a partir de `cycleStatus` | frontend | plan.md §3 | nenhum | Pendente |
| TASK-004 | Adaptar testes existentes de `GroupSummary.test.tsx` que dependiam do bloco "Quem paga a quem" sumir/aparecer | frontend | plan.md §4 | nenhum | Pendente |
| TASK-005 | Testes novos: aba padrão Saldo, troca de aba sem nova chamada de API, selo Prévia/Definitivo por status | frontend | plan.md §4 | nenhum | Pendente |

## Critérios de aceite

- **TASK-001**: abrir `/groups/{id}/summary` em viewport ≥1200px (`lg`) mostra "Despesas do ciclo" e o painel lateral lado a lado, coluna de despesas visivelmente maior (~66%/33%); redimensionar pra <1200px (ex.: 768px, tablet) e pra <600px (mobile) mostra os dois blocos empilhados verticalmente em ambos os casos, sem barra de rolagem horizontal em nenhuma largura. Cards de totais continuam no topo, fora do `Grid` de duas colunas, sem mudança visual.
- **TASK-002**: `SummarySidePanel` renderiza duas abas, "Saldo" e "À pagar". Aba "Saldo" mostra os mesmos cards de `BalanceCards` (nome, valor, cor verde/vermelho conforme sinal) sem diferença visual em relação ao bloco antigo. Clicar em "À pagar" mostra `SettlementList` (mesmo conteúdo do antigo bloco "Quem paga a quem") quando `settlements` não está vazio, ou o texto "Nenhuma pendência entre os membros neste ciclo." quando está vazio. `GroupSummary.tsx` não tem mais nenhum título solto "Saldos por pessoa" ou "Quem paga a quem" fora do painel.
- **TASK-003**: para `summary.cycle.status` igual a `open` ou `future`, aparece um `Chip` "Prévia" (ícone `Update`, cor `info`) no topo do painel lateral, visível independente de qual aba está selecionada. Para `closed` ou `closed_manually`, aparece `Chip` "Definitivo" (ícone `PaidOutlined`, cor `success`) no mesmo lugar. Nenhuma mudança no chip de status do ciclo já existente no topo da página (`GroupSummary.tsx:78-85`).
- **TASK-004**: `npx vitest run` verde depois de reescrever os testes que citavam `queryByText('Quem paga a quem')`/`findByText('Quem paga a quem')` (`GroupSummary.test.tsx:200-231` na numeração atual) pra primeiro clicar na aba "À pagar" (`userEvent.click(screen.getByRole('tab', { name: 'À pagar' }))`) antes de checar a ausência (estado vazio) ou presença (nomes/valores) do conteúdo.
- **TASK-005**: `npx vitest run` verde cobrindo três cenários novos: (a) ao carregar a tela, conteúdo de `BalanceCards` já visível sem nenhum clique (aba "Saldo" é a padrão); (b) clicar em "À pagar" e depois voltar em "Saldo" não altera a contagem de chamadas `axios.get` (`vi.mocked(axios.get).mock.calls.length` igual antes e depois dos cliques); (c) teste parametrizado (`it.each`) com `status` em `open`/`future` esperando o texto "Prévia", e `closed`/`closed_manually` esperando "Definitivo".

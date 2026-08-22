# Tasks — Layout de Duas Colunas na Página de Despesas

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260822

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-001 | Breakpoints do grid principal de `ExpenseManager.tsx` (`xs`/`sm`/`lg`) | frontend | plan.md §1 | nenhum | Concluída |
| TASK-002 | Tabela de despesas no lugar do grid de cards | frontend | plan.md §2 | nenhum | Concluída |
| TASK-003 | Painel lateral com `SummarySidePanel` (abas Saldo/À pagar) | frontend | plan.md §3 | nenhum | Concluída |
| TASK-004 | Adaptar os 2 testes existentes afetados pela tabela e pelo painel novo | frontend | plan.md §4 | nenhum | Concluída |
| TASK-005 | Teste novo: aba "À pagar" com `settlements` real desta página | frontend | plan.md §4 | nenhum | Pendente |

## Critérios de aceite

- **TASK-001**: abrir `/groups/{id}/expenses` em viewport ≥1200px (`lg`) mostra a listagem e o painel lateral lado a lado (~66%/33%); redimensionar pra <1200px (tablet) e <600px (mobile) empilha os dois blocos verticalmente em ambos os casos, sem rolagem horizontal na página.
- **TASK-002**: a listagem vira uma tabela com colunas Tipo, Despesa, Valor, Data, Credor, Pagadores, Status, Ações; clicar no link da célula "Despesa" navega pra `/groups/{groupId}/expenses/{id}` (mesmo `href` de hoje); os ícones de ação (remover fixa, editar, excluir, marcar paga, desfazer pagamento) aparecem/somem exatamente nas mesmas condições de hoje (`canEdit`/`canDelete`/`canPay`/`canUnpay`/`isFixed && cycleIsOpen`); com a competência sem despesas ou sem resultado de filtro, continua mostrando a mensagem de texto (`Nenhuma despesa encontrada...`) em vez de uma tabela vazia.
- **TASK-003**: painel lateral mostra `Tabs` "Saldo"/"À pagar" com o mesmo conteúdo/selo Prévia-Definitivo de `GroupSummary.tsx`; trocar de aba não dispara nova chamada `axios.get`.
- **TASK-004**: `npx vitest run src/pages/ExpenseManager.test.tsx` 100% verde depois de reescrever `shows status chip, credor and pagadores` (linhas 171-194 na numeração atual, sem os prefixos "Credor: "/"Pagadores: ") e o trecho do teste de fluxo completo que checava `screen.getByText('Saldo por pessoa')` (linha 847 na numeração atual, trocado por uma asserção que bata com a aba "Saldo" do `SummarySidePanel`).
- **TASK-005**: `npx vitest run src/pages/ExpenseManager.test.tsx` verde cobrindo um cenário com `settlements` não vazio no fixture de teste, clicando na aba "À pagar" e conferindo que o texto/valor da liquidação aparece (mesmo padrão usado em `GroupSummary.test.tsx`).

# Tasks — Aviso de ciclo fechado nomeia quem falta acertar

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261123

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-670 | Mostrar uma linha "devedor ainda não pagou o acerto a credor" por acerto pendente no `CycleClosingAlert` e ajustar os testes que afirmam o texto antigo | frontend | plan.md §1 e §2 | nenhum | Pendente |
| TASK-671 | Documentar a regra em `05-context-frontend.md` e `01-specify.md` | doc | plan.md §3 | nenhum | Pendente |
| TASK-672 | Conferir o aviso na Home e em Despesas na stack de validação | frontend | plan.md §4 | nenhum | Pendente |

Ordem: 670 primeiro (código e os testes que ele quebra, juntos, para não deixar a suíte vermelha entre tasks); 671 e 672 depois, nessa ordem.

## Critérios de aceite

- **TASK-670**: com o ciclo `closed`/`closed_manually`, não selado e ao menos um acerto sem `confirmedAt`, o `CycleClosingAlert` mostra, num único `Alert` `warning`, o título "O ciclo fechou e ainda falta acertar:" e uma linha "<devedor> ainda não pagou o acerto a <credor>." por acerto pendente, na ordem de `settlements`, sem valor e sem corte. Devedor com dois credores gera duas linhas; nome ausente em `balances` vira "Desconhecido" (devedor e credor); acerto confirmado não gera linha. Condição de aparecer e aviso de pré-fechamento inalterados. `CycleClosingAlert.test.tsx` e `ExpenseManager.test.tsx:2551-2579` afirmam as linhas novas e continuam cobrindo os casos "renders nothing". Mutantes (credor com `from_user_id`, voltar a deduplicar por devedor, inverter `confirmedAt`) derrubados por ao menos um teste cada. `npx tsc --noEmit` limpo e suíte do front verde.
- **TASK-671**: `docs/sdd/05-context-frontend.md` descreve o aviso "devedor → credor" por acerto pendente, com a versão subida; `docs/sdd/01-specify.md` §3.3 ganha o bullet com a versão subida; `git diff` não lista specify de feature arquivada.
- **TASK-672**: na stack de validação (MySQL 3307, backend 8001, Vite 3001), num grupo com ciclo fechado e acertos pendentes, incluindo um devedor com dois credores, Home e Despesas mostram uma linha por acerto pendente, e o aviso some quando o último acerto é confirmado; resultado em `implementation.md` com os comandos reais.

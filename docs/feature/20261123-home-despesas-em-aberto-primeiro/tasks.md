# Tasks — Despesas em aberto primeiro

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261123

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-663 | Ordenar as despesas em aberto primeiro em `sortExpensesByOccurrence` e ajustar os testes que afirmam a ordem antiga | frontend | plan.md §1 e §2 | nenhum | Pendente |
| TASK-664 | Cobrir a Home (`CycleDetailPanel`) com teste de em aberto primeiro e atualizar o comentário | frontend | plan.md §2 | nenhum | Pendente |
| TASK-665 | Cobrir Despesas (`ExpenseManager`) com teste de ordem sob filtro ativo e atualizar o comentário | frontend | plan.md §2 | nenhum | Pendente |
| TASK-666 | Cobrir Pagamentos (`Payments`) com teste de ordem sob filtro ativo e atualizar o comentário | frontend | plan.md §2 | nenhum | Pendente |
| TASK-667 | Cobrir o relatório de fechamento (`ClosingReportPanel`) com teste de ordem e atualizar o comentário | frontend | plan.md §2 | nenhum | Pendente |
| TASK-668 | Documentar a regra em `05-context-frontend.md` e `01-specify.md` | doc | plan.md §3 | nenhum | Pendente |
| TASK-669 | Conferir a ordem nas quatro telas na stack de validação | frontend | plan.md §4 | nenhum | Pendente |

Ordem: 663 primeiro (as demais dependem da regra nova); 664 a 667 são independentes entre si, nessa ordem de uso; 668 e 669 por último. A 663 inclui ajustar os três testes de tela que afirmam "não prioriza pendentes" (Home, Despesas, Pagamentos), porque a mudança da função os quebra: deixar a suíte vermelha entre tasks violaria o checklist de integração.

## Critérios de aceite

- **TASK-663**: `sortExpensesByOccurrence` põe `paid === false` (ou `paid` ausente) antes de `paid === true`; dentro de cada grupo vale data decrescente, depois `id` e parcela decrescentes; devolve cópia e não altera a entrada; lista vazia devolve `[]`. `cycleOrdering.test.ts` tem casos para: em aberto antes de pago com data mais antiga, desempate por `id` e parcela dentro do grupo, `paid` ausente tratado como em aberto, entrada não mutada. Os testes `CycleDetailPanel.test.tsx:53`, `ExpenseManager.test.tsx:2620` e `Payments.test.tsx:1187` passam a esperar em aberto primeiro. Mutantes: remover o critério `paid` e inverter o sentido são derrubados pelos testes. `tsc --noEmit` limpo.
- **TASK-664**: o teste de `CycleDetailPanel` mostra, num `summary` de ciclo aberto e noutro de ciclo selado, uma despesa em aberto de data antiga acima de pagas mais recentes; o comentário de `CycleDetailPanel.tsx` descreve a regra nova; mutante (voltar à ordem só por data) derruba o teste.
- **TASK-665**: com um filtro ativo em Despesas (por exemplo, busca por texto que mantém despesas pagas e em aberto), cartões e tabela mantêm em aberto primeiro; o comentário de `ExpenseManager.tsx` descreve a regra nova; mutante derruba o teste.
- **TASK-666**: o mesmo para Pagamentos, sobre os cartões da tela com filtro ativo; o comentário de `Payments.tsx` descreve a regra nova; mutante derruba o teste.
- **TASK-667**: `ClosingReportPanel.test.tsx` tem um teste em que o relatório traz despesa em aberto antiga e pagas recentes, e "Despesas do ciclo" mostra a em aberto primeiro; o comentário de `ClosingReportPanel.tsx` descreve a regra nova; mutante derruba o teste.
- **TASK-668**: `docs/sdd/05-context-frontend.md:36` descreve "em aberto primeiro e, dentro de cada grupo, por data da ocorrência decrescente" e cita a reversão da R1 e esta feature; `docs/sdd/01-specify.md` ganha o bullet em §3.3 com a versão subida; o specify arquivado de `20261026-organizacao-despesas-pagamentos` fica intacto (`git diff` não o lista).
- **TASK-669**: na stack de validação (MySQL 3307, backend 8001, Vite 3001), num grupo com despesas pagas e em aberto de datas misturadas, Home, Despesas, Pagamentos e o relatório de um ciclo fechado mostram as em aberto primeiro, e a ordem das pagas segue a data decrescente; o resultado vai em `implementation.md` com os comandos reais.

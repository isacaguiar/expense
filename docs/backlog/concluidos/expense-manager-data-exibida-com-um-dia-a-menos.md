# ExpenseManager exibe a data das despesas com 1 dia a menos em fusos negativos (ex.: horário do Brasil)

ID: 013
Origem: docs/feature/concluidas/202608/20260818-resumo-grupo-dashboard/implementation.md (achado durante validação no browser da TASK-063)
Criado em: 2026-08-19
Prioridade: MEDIA
Status: Promovido para TASK-133

## Descrição

`ExpenseManager.tsx` formata a data de cada despesa com `new Date(exp.date).toLocaleDateString('pt-BR')` (linha ~374, dentro do `map` da tabela). `exp.date` vem da API como string `YYYY-MM-DD` (ex.: `"2026-07-16"`); `new Date('2026-07-16')` é interpretado pelo JS como meia-noite **UTC**, não meia-noite local. Em qualquer fuso com offset negativo (ex.: `America/Sao_Paulo`, UTC-3), converter esse instante de volta para hora local cai no dia anterior — a UI mostra "15/07" para uma despesa cujo `date_payment` real é `2026-07-16`.

Confirmado na prática: a mesma função (`new Date(dateStr).toLocaleDateString(...)`) foi copiada para o esqueleto de `GroupSummary.tsx` (`docs/feature/concluidas/202608/20260818-resumo-grupo-dashboard/`) e, testado no browser local (fuso `America/Sao_Paulo`), exibiu "15 de jul." para um ciclo cujo início real era `2026-07-16` — corrigido nessa tela nova construindo a `Date` a partir dos componentes `year/month/day` em vez de parsear a string ISO diretamente. `ExpenseManager.tsx` não recebeu a mesma correção (fora do escopo desta feature, que só toca a tela de Resumo).

## Por que importa

Qualquer usuário no fuso do Brasil (ou outro fuso negativo) vê a data errada de toda despesa na tela "Despesas do Grupo" — inclusive despesas Fixa projetadas, onde o dia exibido é parte do que comunica "em que mês/dia essa cobrança cai". É um bug de exibição silencioso (sem erro, sem crash), mas visível em todo lançamento.

Tipo sugerido: frontend — trocar `new Date(exp.date).toLocaleDateString('pt-BR')` por uma construção a partir dos componentes locais (`year, month-1, day`), mesmo padrão já aplicado em `GroupSummary.tsx`.

## Resolução

Concluído em: 2026-08-21
Feature: docs/feature/concluidas/202608/20260821-expense-manager-mes-e-data-corretos/
Tasks: TASK-133
PRs: https://github.com/isacaguiar/expense/pull/43 (mergeado em `dev`, agrupado com item 012)

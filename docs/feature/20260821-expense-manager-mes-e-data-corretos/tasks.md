# Tasks — ExpenseManager: mês e data corretos

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs seguem a numeração global do projeto (maior existente antes desta feature: TASK-131).

Versão: 1.0 · Criado em: 20260821

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-132 | Projetar despesas `IN_INSTALLMENTS` mês a mês em `indexByGroup`, usando `value_quota` | backend | plan.md §1 | antes do merge | Implementada na branch da feature |
| TASK-133 | Corrigir parse de data em `ExpenseManager.tsx` (constrói `Date` a partir de componentes locais) | frontend | plan.md §2 | antes do merge | Pendente |

## Critérios de aceite

- **TASK-132**: despesa `IN_INSTALLMENTS` em 3x criada em março aparece em `GET /groups/{id}/expenses?year=2026&month=3`, `month=4` e `month=5`, cada uma com `value` igual ao `value_quota` da parcela correspondente (não `total_value`) e `date` igual ao `date_expected` da parcela. Não aparece em `month=6` (sem parcela nesse mês). Nenhuma duplicata no mês de criação (só 1 linha para a despesa, vinda do novo conjunto, não mais de `$direct`). Testes automatizados novos em `backend/tests/Feature/ExpenseControllerIndexByGroupTest.php` cobrindo os casos acima; suíte completa (`php artisan test`) continua verde.
- **TASK-133**: com o browser/timezone em `America/Sao_Paulo` (UTC-3), uma despesa com `date` `"2026-07-16"` aparece na tela como `16/07/2026` (não `15/07/2026`). Verificação via preview do dev server (`npm run dev`, DevTools com timezone override) ou teste de componente, se a suíte de frontend cobrir esse cenário.

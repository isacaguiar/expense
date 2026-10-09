# Tasks — Rateio de parcelas no backend

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261008

IDs a partir de TASK-397: o maior em `dev` é TASK-396 (feature `20261004-metricas-aquisicao-ativacao`). Reconfirmar a numeração se outra feature em andamento reservar IDs antes do merge.

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-397 | Criar `App\Support\InstallmentSchedule` com o rateio em centavos (resto na última parcela, meses com clamp de fim de mês) e testes unitários com vetores fixos | backend | plan.md §1 | nenhum | Concluída |
| TASK-398 | Conferir a paridade do `InstallmentSchedule` com `frontend/src/utils/installments.ts` nos mesmos vetores e registrar o resultado | backend | plan.md §1 | nenhum | Pendente |
| TASK-399 | Tornar `quotas` opcional em `POST /api/expenses`, gerando as parcelas no servidor, com teto de 120 parcelas | backend | plan.md §2 | nenhum | Pendente |
| TASK-400 | Gerar as parcelas no servidor ao trocar para parcelada em `PUT /api/expenses/{id}` sem `quotas`, com o mesmo teto | backend | plan.md §3 | nenhum | Pendente |
| TASK-401 | Regenerar as quotas na edição quando valor, data ou nº de parcelas mudam numa despesa não-fixa sem parcela paga | backend | plan.md §4 | nenhum | Pendente |
| TASK-402 | Devolver `value_per_person` por quota em `GET /api/expenses/{id}` | backend | plan.md §5 | nenhum | Pendente |
| TASK-403 | Revisar a feature com `security-reviewer` e `pr-readiness-checker` e rodar a suíte completa do backend | backend | plan.md §6 | antes do merge | Pendente |

Ordem: TASK-397 → 398 → 399 → 400 → 401 → 402 → 403 (plan.md §8). TASK-397 sustenta 399, 400 e 401; TASK-401 depende da 400; TASK-402 é independente das demais, e fica depois só para manter o caminho de criação e edição na frente.

## Critérios de aceite

- **TASK-397**: `cd backend && php artisan test --filter=InstallmentScheduleTest` passa, com `PHPUnit\Framework\TestCase` puro (sem banco). Os testes fixam:
  - rateio: 100 em 3 → 33,33 · 33,33 · 33,34; 10 em 3 → 3,33 · 3,33 · 3,34; 0,10 em 3 → 0,03 · 0,03 · 0,04; 1,00 em 3 → 0,33 · 0,33 · 0,34; 1000,01 em 7 → seis de 142,85 e a última de 142,91; total 0 em N parcelas → N parcelas de 0,00; N = 1 → uma parcela com o total e a data inicial; a soma das parcelas é igual ao total para N de 1 a 120;
  - datas: a partir de 31/01/2026 em 3 parcelas → 2026-01-31, 2026-02-28, 2026-03-31; a partir de 31/01/2028 em 2 → 2028-01-31, 2028-02-29; a partir de 31/12/2026 em 2 → 2026-12-31, 2027-01-31; `number` vai de 1 a N;
  - `build(…, 0, …)` lança `InvalidArgumentException`; `MAX_INSTALLMENTS` vale 120.
  `cd backend && ./vendor/bin/pint --test` limpo. Verificação por mutação: (a) dar o resto à primeira parcela e (b) somar 1 mês sobre a data anterior (em vez de i meses sobre a inicial) derrubam, cada uma, pelo menos um teste.
- **TASK-398**: um script descartável, fora do repositório, importa o `frontend/src/utils/installments.ts` real com o Node e imprime em JSON o resultado para N de 2 a 120, totais {0, 0,01, 0,10, 1, 10, 100, 1000,01, 99999,99} e datas iniciais {2026-01-29, 2026-01-30, 2026-01-31, 2026-08-15, 2026-12-31, 2028-01-31} (5.712 combinações); um script PHP faz o mesmo com `InstallmentSchedule::build`. O `diff` entre os dois JSON tem **zero diferenças**. Os comandos, a contagem de combinações e o resultado ficam em `implementation.md`; nenhum arquivo do script entra no commit. Se houver divergência, a task não fecha: ela é registrada e levada ao usuário antes de seguir.
- **TASK-399**: testes em `ExpenseControllerStoreTest` passam:
  - À Vista de 100,00 sem `quotas` → 201 com 1 quota (`number` 1, `date_expected` = `date_payment`, `value_quota` 100,00, pendente); Fixa sem `quotas` → 201 com 1 quota;
  - Parcelada de 100,00 em 3 sem `quotas`, com `date_payment` 2026-08-15 → 201 com 3 quotas de 33,33 · 33,33 · 33,34, datas 2026-08-15, 2026-09-15 e 2026-10-15, todas pendentes; as linhas gravadas são **idênticas** às do mesmo pedido com as `quotas` enviadas;
  - `installments` 1 e 121 sem `quotas` em Parcelada → 422 com `errors.installments`; 120 → 201 com 120 quotas; `installments` 121 **com** `quotas` coerentes continua como hoje (201);
  - `quotas: []` e `quotas: null` continuam 422;
  - À Vista sem `quotas` com `date_payment` em competência fechada → 422 de competência;
  - Parcelada retroativa sem `quotas` gera as quotas passadas com `born_paid`, como no teste existente de `:425`.
  Os 15 usos de `quotas` que já existem em `ExpenseControllerStoreTest` passam **sem edição**; Pint limpo. Mutação: remover o teto derruba o teste de 121; ler `$request->quotas` em vez de `$quotas` derruba o de À Vista sem `quotas`.
- **TASK-400**: testes em `ExpenseControllerShowUpdateDestroyTest` passam:
  - `PUT` de uma À Vista não paga com `expense_type` `IN_INSTALLMENTS`, `installments` 3 e **sem** `quotas` → 200, 3 quotas rateadas sobre o total final e a data final, `installments` 3 gravado;
  - sem `installments` e sem `quotas` → 422 com mensagem que cita `installments`;
  - `installments` 121 sem `quotas` → 422 de campo; 120 é aceito;
  - a troca para À Vista e a troca com `quotas` enviadas seguem como hoje.
  Os testes existentes de `:244` e `:265` (quantidade e soma) passam sem edição; Pint limpo.
- **TASK-401**: testes em `ExpenseControllerShowUpdateDestroyTest` passam:
  - À Vista não paga: `PUT` só com `total_value` 150 → 1 quota de 150,00; só com `date_payment` → a quota acompanha a data;
  - Parcelada sem parcela paga: `PUT` só com `total_value` → quotas rateadas de novo e somando o novo total; só com `installments` 4 → 4 quotas; só com `date_payment` → datas recalculadas;
  - **sem recriação**: reenviar os mesmos valores, ou mudar só `description`, mantém os mesmos IDs de quota;
  - despesa Fixa com `total_value` alterado → quotas intactas; À Vista com quota paga e `total_value` alterado → 422 (já existente); Parcelada com parcela paga → 422 (já existente);
  - `installments` 121 → 422 de campo;
  - `PUT` com `expense_type` continua pelo caminho de troca de tipo.
  Todos os testes existentes do arquivo passam sem edição; Pint limpo. Mutação: remover a comparação "difere do gravado" derruba o teste de IDs mantidos.
- **TASK-402**: testes de `show` em `ExpenseControllerShowUpdateDestroyTest` passam:
  - parcelada com 2 pagadores e quotas 33,33 · 33,33 · 33,34 → `value_per_person` 16,67 · 16,67 · 16,67; 1 pagador → igual ao `value_quota`; 3 pagadores e 100,00 → 33,33;
  - **0,29 com 2 pagadores → 0,15**, igual ao `valuePerPerson` que `computeCycleSummary()` dá para o mesmo valor (e diferente dos 0,14 que o `perPersonValue()` do web dá, ver plan.md §5);
  - despesa sem pagador usa divisor 1;
  - os campos já existentes da resposta não mudam (o teste atual de `show` passa sem edição);
  - o número de consultas SQL de `show` é o mesmo com 1 e com 10 quotas (sem N+1).
  Pint limpo. Mutação: trocar `round(…, 2)` por `floor` e tirar o `max(…, 1)` do divisor derrubam, cada um, pelo menos um teste. O resultado da varredura do arredondamento (11.687 de 1.600.008 combinações, 0,73%, sempre 1 centavo) é copiado para `implementation.md`.
- **TASK-403**: (a) o agent `security-reviewer` roda sobre as mudanças de `backend/app/Http/Controllers/ExpenseController.php` e não deixa achado crítico ou alto pendente, confirmando que a autorização não mudou e que o teto de parcelas barra a amplificação; (b) o agent `pr-readiness-checker` não aponta pendência; (c) `cd backend && ./vendor/bin/pint --test` limpo e `php artisan test` verde na **suíte completa**, contra um MySQL descartável (nunca o MySQL compartilhado de outro projeto na porta 3306); (d) `git diff origin/dev -- backend/tests` mostra só linhas **adicionadas** nos quatro arquivos que já usavam `quotas` (nenhuma asserção antiga removida ou alterada). Comandos e resultados reais em `implementation.md`.

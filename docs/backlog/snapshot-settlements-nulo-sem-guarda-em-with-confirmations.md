# Snapshot com `settlements` nulo derruba `withConfirmations` (sem guarda para `null`)

ID: 108
Origem: docs/feature/concluidas/202610/20261026-organizacao-despesas-pagamentos/specify.md §2.9 (ponto pré-existente, fora do escopo daquela feature)
Criado em: 2026-10-27
Prioridade: BAIXA
Status: Aberto

## Descrição
A coluna `settlements` de `ex_group_cycle_snapshots` é `json()->nullable()` (`backend/database/migrations/2026_08_22_140000_*.php:15`), acrescentada **no mesmo dia** da criação da tabela (`2026_08_22_015116_*`, às 01:51; a coluna, às 14:00). Os caminhos que servem a foto do snapshot a repassam sem checar `null`: `viewAt` (`backend/app/Services/CycleSummaryService.php:680`), `ExpenseController.php:684` e `:870`, e depois `withConfirmations(..., array $settlements)` (`CycleSummaryService.php:328`), cuja assinatura exige `array`. Com `settlements = NULL`, o PHP lança `TypeError` e a resposta seria 500 (por leitura de código; não reproduzido).

**Não verifiquei se existe linha assim**: nenhum dado de produção foi consultado. Os únicos candidatos são snapshots gravados na janela de poucas horas entre a criação da tabela e a migration da coluna, em 2026-08-22, por isso a probabilidade é baixa e talvez nula. O cliente novo (R12 da feature `20261026-organizacao-despesas-pagamentos`) já trata `settlements` ausente como lista vazia, mas só depois de a API responder; com 500 ele nunca chega lá.

Primeiro passo ao executar: contar as linhas (`SELECT COUNT(*) FROM ex_group_cycle_snapshots WHERE settlements IS NULL`) em produção. Se for zero, o item vira só uma guarda defensiva (`?? []`) com teste; se não for, decidir entre recalcular por `compute()` na leitura (sem regravar o snapshot) ou uma migration de dados (gate humano, Constitution).

## Por que importa
Um 500 ao abrir uma competência antiga é uma tela quebrada para o grupo inteiro naquele ciclo. A probabilidade é desconhecida e provavelmente baixa, e o custo de verificar é de uma consulta.

Tipo sugerido: backend (verificação em produção primeiro)

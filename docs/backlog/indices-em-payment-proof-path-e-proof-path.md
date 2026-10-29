# Índices em `ex_quotas.payment_proof_path` e `ex_settlement_confirmations.proof_path`

ID: 117
Origem: docs/feature/concluidas/202610/20261027-pagamento-no-cadastro-despesa/implementation.md (TASK-532, achado N9); `backend/app/Support/ProofStorage.php` (`deleteIfUnreferenced`), migrations `2026_08_22_200000_add_payment_proof_path_to_ex_quotas_table` e `2026_08_25_220000_create_ex_settlement_confirmations_table`
Criado em: 2026-10-29
Prioridade: BAIXA
Status: Aberto

## Descrição
Como um mesmo arquivo de comprovante pode ser compartilhado por várias quotas (compra parcelada paga no cadastro), `ProofStorage::deleteIfUnreferenced` roda `exists()` em `ex_quotas.payment_proof_path` e em `ex_settlement_confirmations.proof_path` antes de apagar. As duas colunas **não têm índice**, então cada consulta é uma varredura da tabela. O método é chamado em `pay()`, `unpay()`, `updatePaymentProof()`, no desfazer da compra (uma vez por arquivo distinto) e no `catch` do `store()`.

Hoje as tabelas são pequenas e isso não aparece. Cresce com o uso: cada ação de comprovante passa a custar uma varredura, e o desfazer em lote custa uma por arquivo. Proposta: migration **aditiva** com um índice em cada coluna.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Migration aditiva (só `CREATE INDEX`), sem mudar coluna nem dado; roda no deploy do merge em `main` (ADR-008), então o aval é no PR de promoção. |
| R2 | Antes de aplicar, confirmar a versão e o formato de linha do MySQL de produção: índice em `string(255)` com `utf8mb4` ocupa 1020 bytes, que cabe no limite de 3072 do `DYNAMIC` (MySQL 8 e 5.7 por padrão) mas não no de 767 do formato antigo. |
| R3 | O `down()` só remove os dois índices. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Quando | A) agora, junto da próxima migration do grupo de comprovantes<br>B) só depois de medir (`EXPLAIN` mostrando varredura relevante) | **B** | Com tabelas pequenas o ganho é zero e cada migration tem custo de deploy; medir com volume real evita índice sem uso. |

## Por que importa
Evita que o custo das ações de comprovante cresça linearmente com o número de quotas. Sem urgência hoje.

Tipo sugerido: backend

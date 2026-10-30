# Foto do cadastro apagada se o `commit` for ambíguo, e arquivo órfão se o processo morrer antes do `catch`

ID: 116
Origem: docs/feature/concluidas/202610/20261027-pagamento-no-cadastro-despesa/implementation.md (TASK-532, achado N8); `backend/app/Http/Controllers/ExpenseController.php` (`store()`, `catch` com `ProofStorage::delete($proofPath)`), `backend/app/Support/ProofStorage.php` (`deleteIfUnreferenced`)
Criado em: 2026-10-29
Prioridade: BAIXA
Status: Aberto

## Descrição
O `store()` grava a foto **antes** da transação (`ProofStorage::store`) e a apaga no `catch` se o banco falhar. Há dois buracos:

- **Commit ambíguo.** Se `DB::commit()` lançar exceção **depois** de o banco já ter confirmado (por exemplo, a conexão cai ao responder o `COMMIT`), o `catch` apaga a foto e as quotas ficam apontando para um arquivo que não existe. `ProofStorage::deleteIfUnreferenced`, que já existe e confere `ex_quotas.payment_proof_path` e `ex_settlement_confirmations.proof_path`, evitaria isso trocando uma linha.
- **Erro fatal.** Se o processo morrer entre `ProofStorage::store` e o `commit` (timeout do PHP, falta de memória, processo encerrado), o `catch` nem roda e o arquivo fica **órfão** em `comprovantes/`. O mesmo vale para `pay()` e `updatePaymentProof()`, que também gravam antes de gravar no banco.

Na validação local da feature, dois envios com texto em codificação inválida deram erro de banco (500) e as duas fotos foram removidas: o caminho comum do `catch` funciona. Os dois casos acima são os que ele não cobre.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | A foto só pode ser removida se nenhuma quota nem acerto a referencia (a regra do R18 da feature). |
| R2 | Sem agendador (ADR-006 e ADR-008): qualquer varredura é comando manual ou preguiçoso, não cron. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Commit ambíguo | A) trocar `ProofStorage::delete` por `deleteIfUnreferenced` no `catch` do `store()`<br>B) manter | **A** | Quase uma linha, e só apaga se nenhuma quota referencia o caminho. Se o banco estiver fora do ar a consulta também falha: proteger com `try/catch` e deixar o arquivo (órfão, que o D2 limpa, em vez de quebrado). |
| D2 | Arquivo órfão | A) comando `artisan` manual que lista (`--dry-run`) e remove arquivos de `comprovantes/` sem referência e mais velhos que 24 h<br>B) nada | **A** | Resolve também os órfãos de `pay()` e `updatePaymentProof()`; o limite de 24 h evita apagar upload em andamento. |

## Por que importa
O caso 1 apaga uma prova de pagamento que o banco já considera registrada (perda de dado, rara). O caso 2 só ocupa disco. Os dois são raros, mas um comprovante é a prova de uma dívida.

Tipo sugerido: backend

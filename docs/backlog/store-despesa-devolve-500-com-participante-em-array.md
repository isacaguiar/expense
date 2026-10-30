# `POST /api/expenses` devolve 500 (erro de SQL) quando `payers` traz um elemento em array

ID: 125
Origem: TASK-552 da feature `docs/feature/20261030-saida-e-remocao-de-membro/` (achado ao escrever o teste de entrada malformada do `update()`); `backend/app/Http/Controllers/ExpenseController.php`, `store()` (regra `payers.*` e `syncWithoutDetaching($request->payers)`)
Criado em: 2026-10-30
Prioridade: BAIXA
Status: Aberto

## Descrição
- `payers.*` no `store()` usa `Rule::exists('ex_groups_members', 'user_id')`. Para um elemento que é um array (`"payers": [[5]]`), o `exists` confere os ids de dentro do array e passa; depois `syncWithoutDetaching($request->payers)` tenta gravar o array como chave do pivô e o MySQL falha com `Unknown column '0'`. A resposta é **500**, não 422.
- **Reproduzido** nesta sessão, com um teste descartável (não versionado): `POST /api/expenses` com `payers: [[<id de membro>]]` devolveu 500. O `PUT /api/expenses/{id}` tinha o mesmo defeito e, na TASK-552, deixou de ter, **só como efeito colateral**: a regra do `update()` passou a ser `Rule::in`, que recusa array com 422 (`ExpenseControllerUpdateFormerMemberTest::test_a_malformed_participant_is_a_422_not_a_server_error`).
- **Não verificado:** se `user_payer_id` enviado como array também derruba o `store()`. Conferir junto.

## Por que importa
Só quem monta o JSON à mão manda isso (a tela web não manda), e nada vaza: é um 500 com SQLSTATE no log do servidor. Mesmo assim é entrada inválida virando erro de servidor, polui o log e pode disparar alerta; a validação deveria responder 422.

Tipo sugerido: bugfix. Caminho do BFF (`/novo-bug`, com Triagem): correção de poucas linhas na validação do `store()` (por exemplo `payers.*` com `integer` além do `exists`), com teste. Não muda contrato para cliente correto.

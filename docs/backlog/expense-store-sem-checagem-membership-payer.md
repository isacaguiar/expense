# POST /api/expenses não checa se user_payer_id/payers são membros do grupo

ID: 010
Origem: docs/feature/20260818-fluxo-despesas-grupo/plan.md §3 (achado durante execução da TASK-036, confirmado por revisão do agent security-reviewer)
Criado em: 2026-08-18
Prioridade: ALTA
Status: Aberto

## Descrição

`ExpenseController::store` (`backend/app/Http/Controllers/ExpenseController.php`) valida `user_payer_id` e `payers[]` só com `exists:ex_users,id` — confirma que o usuário existe no sistema, mas não que ele é membro do grupo (`group_id`) informado na mesma requisição. A TASK-036 desta feature adicionou checagem de membership para o usuário autenticado (`group_id` + `user_creator_id` forçado no servidor), mas deixou esse ponto de fora deliberadamente, por escopo.

Na prática: um membro legítimo de um grupo pode criar uma despesa nesse grupo e atribuir `user_payer_id`/`payers` a um `user_id` qualquer do sistema — inclusive alguém que nunca participou daquele grupo.

## Por que importa

Pode gerar cobrança ou participação indevida para um usuário que não tem relação com o grupo (ex.: Pix de cobrança gerado para alguém que nunca fez parte da despesa compartilhada). Não é uma escalada de privilégio de um atacante totalmente externo (exige já ser membro do grupo, autenticado), mas é uma falha de integridade de dado financeiro que cresce em risco conforme mais grupos/usuários usam o sistema.

Tipo sugerido: backend

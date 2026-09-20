# Sem rotina de expurgo de `ex_user_pre_create`

ID: 044
Origem: docs/feature/20260919-cadastro-de-usuarios/specify.md §3
Criado em: 2026-09-19
Prioridade: BAIXA
Status: Aberto

## Descrição

A tabela `ex_user_pre_create` guarda uma linha por tentativa de cadastro e **nunca apaga nada** —
a linha consumida é marcada com `consumed_at`, e a expirada simplesmente fica. Não há comando
agendado nem `php artisan` para limpar.

A feature já esvazia `password`, `code_hash` e `handle_hash` no consumo, então o que sobra é
PII (nome, e-mail, telefone) de quem começou e de quem abandonou o cadastro, acumulando
indefinidamente.

Expurgar é **hard delete**, que exige aprovação humana explícita (`docs/sdd/00-constitution.md`
§5.2) — foi por isso que ficou fora do escopo da feature, e é por isso que este item precisa de
decisão humana sobre a política de retenção antes de virar task.

## Por que importa

Retenção indefinida de PII de gente que nem chegou a ter conta. O volume também cresce com
tentativas abandonadas, sem teto.

Tipo sugerido: backend

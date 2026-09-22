# `01-specify.md` §3.2 descreve o convite de grupo com token errado

ID: 056
Origem: docs/feature/20260921-convite-e-recuperacao-senha-link-dominio-errado/specify.md §1.3
Criado em: 2026-09-21
Prioridade: BAIXA
Status: Aberto

## Descrição

`docs/sdd/01-specify.md:43` descreve o fluxo de convite de grupo
(`GroupMemberController::store`) dizendo que ele "gera token via
`Password::getRepository()`". O código atual não faz isso —
`backend/app/Http/Controllers/GroupMemberController.php:60-63` usa um cache
key dedicado (`invitation-token:<email>`, TTL de 2 dias), com um comentário
explícito no próprio arquivo dizendo que **não** usa
`Password::getRepository()`/`config('auth.passwords.users.expire')`
("infraestrutura genérica de esqueci-senha, 60 min").

A baseline (`01-specify.md`, "o que o sistema faz hoje") ficou desatualizada
em relação ao código real — provavelmente um resíduo de quando o fluxo de
convite de grupo ainda usava o password broker padrão do Laravel, antes de
migrar para o token dedicado.

## Por que importa

`01-specify.md` é citado como fonte de verdade por outras features (é o
baseline que `README.md` do SDD manda ler antes de qualquer desenvolvimento
novo). Uma descrição errada do mecanismo de token pode levar alguém a
assumir, por exemplo, que o token de convite respeita o TTL de 60 min do
password broker (na verdade são 2 dias) ou que reaproveita configuração do
`auth.passwords.users`, que na prática não é tocada por esse fluxo.

Correção sugerida: reescrever `01-specify.md §3.2` item 2 para refletir o
mecanismo real (cache key `invitation-token:`, TTL 2 dias, gerado com
`bin2hex(random_bytes(32))`), e conferir se os demais itens da seção 3.2
ainda batem com o código.

Tipo sugerido: doc

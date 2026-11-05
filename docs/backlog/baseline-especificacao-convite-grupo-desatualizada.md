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

## Nota de 2026-11-05 (TASK-581 da feature convite-pendente-no-grupo)
A TASK-581 reescreveu o **item 2** do §3.2 do `01-specify.md` (agora descreve o convite pendente, o token de cache `invitation-token:` de 2 dias, o `UserInvitedMail` e o `POST /invitations/verify`) e o §3.3. Isto responde à primeira metade da correção sugerida acima. Ao conferir "os demais itens da seção 3.2", como este item pede, achei o que **ficou** e que a TASK-581 não tocou (fora do que o plano dela aprovou):
- **Item 1** do §3.2 descreve `POST /invitations` (`InvitationController::invite`), que **não existe mais**: foi removido na TASK-192 (commit `597d99f5d5`, "remove fluxo de convite duplicado"); só o `POST /invitations/verify` ficou. A rota e o método sumiram de `routes/api.php` e do controller.
- O título da seção ("dois fluxos distintos e independentes") e o bloco que a fecha ("Os fluxos 1 e 2 resolvem o mesmo problema... não compartilham código — ver 'Divergências'") dependem do item 1. A seção 6 não tem linha sobre convites, então o "ver Divergências" aponta para o nada. O último ponto do §5 ("Unificar os dois fluxos de convite em um só") foi cumprido pela própria TASK-192.
- `02-plan.md:89` e `03-tasks.md` também falam dos dois fluxos, mas são congelados pelo ADR-002 e ficam como estão.
- A linha 42 ("comparado por hash") é o outro item, o 124/120, e não é deste.

Status: segue **Aberto**, com o que sobrou sendo só texto do `01-specify.md` (tipo doc).

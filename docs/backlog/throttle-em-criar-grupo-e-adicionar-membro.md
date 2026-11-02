# Sem `throttle` em `POST /api/groups` e `POST /api/groups/{id}/members`: depois da saída do criador, o teto de 3 grupos deixou de limitar o abuso

ID: 128
Origem: TASK-556 (revisão de segurança) da feature `docs/feature/concluidas/202610/20261030-saida-e-remocao-de-membro/`; `backend/routes/api.php`, `backend/app/Http/Controllers/GroupController.php` (`store`, `:41-76`) e `GroupMemberController.php` (`store`)
Criado em: 2026-10-31
Prioridade: BAIXA
Status: Aberto

## Descrição
- `routes/api.php` só aplica `throttle` a `/pre-register*` e a `/me/overview`. `POST /groups` e `POST /groups/{id}/members` não têm.
- Antes do item 086, o teto de 3 grupos criados por conta (`GroupController::MAX_GROUPS_CREATED_PER_USER`) limitava quantos grupos um usuário podia criar e, com isso, quantas vezes podia colocar alguém num grupo sem consentimento (D1-A). Com a D4-A, sair de um grupo libera a vaga, então a sequência criar grupo, adicionar a vítima, sair e repetir não tem limite.
- Cada repetição gera um grupo novo na lista da vítima e uma notificação `group_member_added`. A vítima precisa sair de um por vez. O revisor cita ainda e-mail de convite quando a conta é nova; **isso não foi conferido**.
- Por desenho da feature (D1-A e D4-A), registrado como risco aceito.

## Por que importa
Spam de grupos e de notificações contra uma pessoa, por qualquer conta autenticada. O teto antigo era a única barreira.

## Decisão a tomar na triagem

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como limitar | A) `throttle` por usuário nas duas rotas (ex.: 30 por minuto)<br>B) contar grupos em que o usuário foi adicionado por terceiros e limitar<br>C) convite pendente com aceite (D1-B do item 086) | **A** agora, C como solução de fundo | A é uma linha por rota e barra o abuso em volume; C remove a causa (entrar sem consentimento) mas é feature inteira. |

Tipo sugerido: melhoria pequena de segurança; `throttle` com teste de 429.

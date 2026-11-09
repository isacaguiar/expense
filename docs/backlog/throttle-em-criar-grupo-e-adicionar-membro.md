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

## Achado adicional (2026-11-03): cancelar e convidar de novo contorna a janela de 24 h do aviso
Revisão de segurança da feature `docs/feature/concluidas/202611/20261103-convite-pendente-no-grupo/` (TASK-576, achado A7):
- A opção C da D1 acima (convite pendente com aceite) **já está implementada** por essa feature: entrar num grupo exige aceite, então o abuso que sobra é de volume (avisos e e-mails), não de consentimento.
- A janela de 24 h entre avisos do mesmo convite (D5 daquela feature, `notified_at`) não vale para o ciclo `POST /groups/{id}/members` mais `DELETE /groups/{id}/invitations/{invitationId}`: reabrir um convite cancelado ou vencido zera `notified_at` (`GroupInvitationService::openInvitation`) e avisa na hora. Cada volta do ciclo cria uma notificação `group_invitation` nova para a vítima. O teto efetivo hoje é o limiter global `api` (60 requisições por minuto por usuário, `RouteServiceProvider`).
- O nome do grupo (até 255 caracteres, editável por qualquer membro) e o nome de quem convida aparecem a um estranho na notificação e na lista de convites. Estão escapados (sem XSS), mas é texto livre vindo do convidante.
- Ao tratar este item: além do `throttle` das duas rotas (D1-A), avaliar não zerar `notified_at` na reabertura depois de cancelamento ou vencimento (zerar só depois de recusa, D9-A daquela feature).

# Demais pontos que registram `getMessage()` da exceção em log

ID: 165
Origem: varredura de `getMessage()` dentro de `Log::` em `backend/app`, feita na promoção do item 163 (feature `20261124-expense-store-nao-loga-mensagem-da-excecao`, D2-A)
Criado em: 2026-11-24
Prioridade: BAIXA
Status: Promovido para TASK-683 e TASK-684 (feature 20261125-log-sem-mensagem-da-excecao-restantes); TASK-680 e 681 (PR #312) já resolveram 780, 132 e 228

## Descrição
O `Notifier::guard` (TASK-676) e o `ExpenseController::afterCommit` (TASK-678) passaram a registrar só a classe da exceção. A varredura achou mais sete pontos com o mesmo padrão, sem item próprio, e o `security-reviewer` da feature achou um oitavo, que a busca por `getMessage()` não pegava (o `WhatsAppNotifier.php:146`, que é o item 164). Os três pontos de `QueryException` (SQL com bindings) já foram resolvidos (ver a nota abaixo); restam os cinco abaixo, de falha de e-mail e do Socialite, sem SQL:

| Arquivo:linha | O que o log leva além da mensagem | Observação |
|---|---|---|
| `backend/app/Services/GroupInvitationService.php:254` | `group_id`, `user_id`, chave `error` | falha ao enviar o e-mail de convite; a mensagem do transporte de e-mail pode trazer o destinatário |
| `backend/app/Http/Controllers/InvitationController.php:97` | `email` do usuário, chave `exception` | falha ao enviar o e-mail de recuperação de senha; o log já grava o e-mail em claro, além da mensagem |
| `backend/app/Services/PreRegistrationService.php:288` | `pre_create_id`, chave `exception` | o comentário do código diz "sem o código e sem o e-mail no log", mas a mensagem do transporte pode trazê-los |
| `backend/app/Http/Controllers/GoogleAuthController.php:116` | `user_id`, `get_class($e).': '.$e->getMessage()` | falha do Socialite ao obter o usuário Google (vínculo) |
| `backend/app/Http/Controllers/GoogleAuthController.php:180` | `get_class($e).': '.$e->getMessage()` | falha do Socialite no login |

## Já resolvido
Feature `docs/feature/concluidas/202611/20261124-log-sem-sql-nos-pontos-de-queryexception/` (PR #312) (TASK-680 e TASK-681): `ExpenseController.php:780` (que logava a exceção inteira; o comentário do item 080/D10 foi revisto), `GoogleAuthController.php:132` (vínculo) e `:228` (login) passaram a registrar só `$e::class`. Os três eram os que vazavam SQL com bindings.

Não entram: os `getMessage()` devolvidos na resposta 422 de exceções de domínio (`PurchaseUndoRefused`, `MemberResponsibleRefused`, `SettlementDecisionRefused`, `PreRegistrationThrottled`), que são texto próprio da aplicação e não de log; e a checagem de `isDuplicateRequestKey` (`ExpenseController.php:894`), que lê a mensagem para reconhecer o índice único e não a registra.

## Decisões em aberto
A fechar no specify, se o item for promovido.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | O que registrar | A) só a classe (`$e::class`)<br>B) a classe e o código (`getCode()`) | **A** | Mesmo critério do `Notifier::guard` e do `afterCommit`. |
| D2 | Agrupar com o item 164 | A) uma feature para os 5 pontos e o 164<br>B) só estes 5, o 164 à parte | **A** | Mesma correção e mesmo tipo de teste de log. |
| D3 | O e-mail em claro no log do `InvitationController.php:97` | A) manter<br>B) trocar por `user_id` | **B** | O e-mail é dado pessoal sem uso no diagnóstico; `user_id` identifica o caso. É mudança de log além de tirar a mensagem, por isso a decisão é separada. |
| D4 | ~~Ordem dos pontos~~ | Resolvida na feature `20261124-log-sem-sql-nos-pontos-de-queryexception` (D4-B): os de `QueryException` saíram primeiro. | — | Restam só os de transporte de e-mail e Socialite. |

## Por que importa
Higiene de log: dado pessoal (e-mail, nomes) e SQL com bindings em texto livre, sem uso para o diagnóstico. Nenhuma exploração hoje.

Tipo sugerido: backend (sem mudança de contrato nem migration)

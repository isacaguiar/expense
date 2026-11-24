# Demais pontos que registram `getMessage()` da exceção em log

ID: 165
Origem: varredura de `getMessage()` dentro de `Log::` em `backend/app`, feita na promoção do item 163 (feature `20261124-expense-store-nao-loga-mensagem-da-excecao`, D2-A)
Criado em: 2026-11-24
Prioridade: BAIXA
Status: Aberto

## Descrição
O `Notifier::guard` (TASK-676) e o `ExpenseController::afterCommit` (TASK-678) passaram a registrar só a classe da exceção. A varredura achou mais sete pontos com o mesmo padrão, sem item próprio (o oitavo, `WhatsAppNotifier.php:146`, é o item 164):

| Arquivo:linha | O que o log leva além da mensagem | Observação |
|---|---|---|
| `backend/app/Services/GroupInvitationService.php:254` | `group_id`, `user_id`, chave `error` | falha ao enviar o e-mail de convite; a mensagem do transporte de e-mail pode trazer o destinatário |
| `backend/app/Http/Controllers/InvitationController.php:97` | `email` do usuário, chave `exception` | falha ao enviar o e-mail de recuperação de senha; o log já grava o e-mail em claro, além da mensagem |
| `backend/app/Services/PreRegistrationService.php:288` | `pre_create_id`, chave `exception` | o comentário do código diz "sem o código e sem o e-mail no log", mas a mensagem do transporte pode trazê-los |
| `backend/app/Http/Controllers/GoogleAuthController.php:116` | `user_id`, `get_class($e).': '.$e->getMessage()` | falha do Socialite ao obter o usuário Google (vínculo) |
| `backend/app/Http/Controllers/GoogleAuthController.php:132` | `user_id`, `$e->getMessage()` | `QueryException` ao salvar o vínculo: traz SQL com bindings |
| `backend/app/Http/Controllers/GoogleAuthController.php:180` | `get_class($e).': '.$e->getMessage()` | falha do Socialite no login |
| `backend/app/Http/Controllers/GoogleAuthController.php:228` | `$e->getMessage()` | `QueryException` ao salvar no login: traz SQL com bindings (e-mail e nome do usuário Google) |

Não entram: os `getMessage()` devolvidos na resposta 422 de exceções de domínio (`PurchaseUndoRefused`, `MemberResponsibleRefused`, `SettlementDecisionRefused`, `PreRegistrationThrottled`), que são texto próprio da aplicação e não de log; e a checagem de `isDuplicateRequestKey` (`ExpenseController.php:894`), que lê a mensagem para reconhecer o índice único e não a registra.

## Decisões em aberto
A fechar no specify, se o item for promovido.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | O que registrar | A) só a classe (`$e::class`)<br>B) a classe e o código (`getCode()`) | **A** | Mesmo critério do `Notifier::guard` e do `afterCommit`. |
| D2 | Agrupar com o item 164 | A) uma feature para os 8 pontos<br>B) só estes 7, o 164 à parte | **A** | Mesma correção e mesmo tipo de teste de log. |
| D3 | O e-mail em claro no log do `InvitationController.php:97` | A) manter<br>B) trocar por `user_id` | **B** | O e-mail é dado pessoal sem uso no diagnóstico; `user_id` identifica o caso. É mudança de log além de tirar a mensagem, por isso a decisão é separada. |
| D4 | Ordem dos pontos | A) tudo numa feature só<br>B) primeiro os de `QueryException` (`GoogleAuthController` 132 e 228), depois os de transporte de e-mail | **A** | A mudança é pequena e igual em todos; separar só multiplicaria PRs. |

## Por que importa
Higiene de log: dado pessoal (e-mail, nomes) e SQL com bindings em texto livre, sem uso para o diagnóstico. Nenhuma exploração hoje.

Tipo sugerido: backend (sem mudança de contrato nem migration)

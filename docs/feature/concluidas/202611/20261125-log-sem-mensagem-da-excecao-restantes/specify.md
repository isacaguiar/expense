# Specify — Log sem a mensagem da exceção nos pontos restantes

> Feature: parar de gravar `getMessage()` nos seis pontos de log que ainda o fazem (falha de e-mail, do Socialite e do WhatsApp), registrando só a classe. Origem: backlog 165 (os 5 pontos que restaram depois da feature `20261124-log-sem-sql-nos-pontos-de-queryexception`) e backlog 164, agrupados por escolha dele (escopo B).

Versão: 1.0 · Criado em: 20261125

---

## 1. Problema

As features 155, 163 e `20261124-log-sem-sql-nos-pontos-de-queryexception` tiraram a mensagem da exceção do log nos pontos que vazavam SQL com bindings. Restam seis pontos com o mesmo padrão, de risco menor: a falha vem de um transporte externo (SMTP, Google, Meta) e a mensagem pode trazer o destinatário, o e-mail, a URL ou o corpo da resposta de terceiro, em texto livre e sem uso para o diagnóstico (a classe, a mensagem do log e o contexto já bastam). Nenhuma exploração hoje; é higiene de log (`00-constitution.md`, dado pessoal).

## 2. Achados confirmados

Verificados no código real em 2026-11-25. Nenhum teste existente afirma o conteúdo desses contextos de log.

| # | Arquivo:linha | O que o contexto leva hoje | Observação |
|---|---|---|---|
| 2.1 | `backend/app/Services/GroupInvitationService.php:254` (`sendActivationMail`) | `group_id`, `user_id`, `'error' => $e->getMessage()` | Falha ao enviar o e-mail de convite; roda depois da resposta (`app()->terminating`). A mensagem do transporte pode trazer o destinatário. |
| 2.2 | `backend/app/Http/Controllers/InvitationController.php:97` (`forgotPassword`) | `'email' => $user->email` e `'exception' => $e->getMessage()` | Falha ao enviar o e-mail de recuperação de senha. Além da mensagem, o log grava o e-mail em claro. A resposta 500 genérica ao cliente não muda. |
| 2.3 | `backend/app/Services/PreRegistrationService.php:288` (`sendCode`) | `pre_create_id`, `'exception' => $e->getMessage()` | Falha ao enviar o código de pré-cadastro. O comentário diz "Sem o código e sem o e-mail no log", mas a mensagem do transporte pode trazê-los. A exceção continua sendo relançada (`throw $e`). |
| 2.4 | `backend/app/Http/Controllers/GoogleAuthController.php:116` (vínculo) | `user_id`, `'exception' => get_class($e).': '.$e->getMessage()` | Falha do Socialite ao obter o usuário Google. |
| 2.5 | `backend/app/Http/Controllers/GoogleAuthController.php:180` (login) | `'exception' => get_class($e).': '.$e->getMessage()` | Falha do Socialite no login. |
| 2.6 | `backend/app/Support/WhatsApp/WhatsAppNotifier.php:146` (envio) | `user_id`, `template`, `'error' => $e->getMessage()` | Falha de `MetaCloudClient::sendTemplate` (HTTP): a mensagem pode trazer a URL ou o corpo da resposta da Meta; o telefone é dado pessoal. É o backlog 164. |

### 2.7 Requisito comum

Nos seis pontos o log passa a registrar só a classe da exceção (`$e::class`), mantendo as demais chaves de contexto (`group_id`, `user_id`, `pre_create_id`, `template`), a mensagem de log e o fluxo. Nenhuma resposta ao cliente, redirect, `throw` ou código de status muda. Sem mudança de contrato nem migration.

### 2.8 Decisões

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | O que registrar | A) só a classe (`$e::class`)<br>B) a classe e o código (`getCode()`) | **A** | Mesmo critério das features 155, 163 e da anterior. |
| D2 | O e-mail em claro no log do 2.2 | A) manter<br>B) trocar por `user_id` | **B** | O e-mail é dado pessoal sem uso no diagnóstico; `user_id` identifica o caso. É mudança de log além de tirar a mensagem, por isso a decisão é separada. |
| D3 | Nome da chave com a classe | A) `exception` em todos (hoje 2.1 e 2.6 usam `error`)<br>B) manter `error` onde já existe | **A** | Uma só chave facilita a busca no log, e nenhum teste nem consumidor depende de `error`. |
| D4 | Teste por ponto | A) um teste por ponto, forçando a falha do transporte e afirmando que o log só leva a classe<br>B) só os pontos de Google e e-mail, sem o WhatsApp | **A** | Os seis têm o mesmo risco; sem teste voltam por refatoração. |
| D5 | O comentário "Sem o código e sem o e-mail no log" (2.3) | A) ajustar para dizer que também a mensagem da exceção fica de fora<br>B) deixar | **A** | O comentário hoje afirma uma garantia que a mensagem do transporte quebrava. |

## 3. Fora de escopo desta feature

- O item 166 (ainda sem registro): os `catch (\Throwable)` que apagam arquivo órfão e relançam (`ExpenseController.php` 1698 e 1850, `SettlementDecisionController.php:198`) e caem no reporter padrão do Laravel, que pode logar SQL com bindings.
- Os `getMessage()` devolvidos na resposta 422 de exceções de domínio.
- O item 155 (pontos 1, 3 e 4), 162 e 097.
- Mascarar a mensagem em vez de omiti-la (frágil e sem uso para o diagnóstico).

# Plan — Log sem a mensagem da exceção nos pontos restantes

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261125

---

## 1. As trocas de código (specify §2.1 a §2.7, D1-A, D2-B, D3-A, D5-A)

Em todos os pontos, o `getMessage()` sai do contexto e entra `'exception' => $e::class`. Mensagens de log, níveis (`warning`/`error`), `catch`, respostas, redirects e o `throw $e` do 2.3 ficam como estão.

| Specify | Arquivo:linha | Troca |
|---|---|---|
| 2.1 | `backend/app/Services/GroupInvitationService.php:254` | `'error' => $e->getMessage()` vira `'exception' => $e::class` (D3-A); mantém `group_id` e `user_id`. |
| 2.2 | `backend/app/Http/Controllers/InvitationController.php:97` | `'email' => $user->email` vira `'user_id' => $user->id` (D2-B) e `'exception' => $e->getMessage()` vira `$e::class`. A resposta 500 genérica não muda. |
| 2.3 | `backend/app/Services/PreRegistrationService.php:288` | `'exception' => $e->getMessage()` vira `$e::class`; mantém `pre_create_id`; o `throw $e` fica. O comentário de cima passa a dizer "Sem o código, sem o e-mail e sem a mensagem da exceção no log" (D5-A). |
| 2.4 | `backend/app/Http/Controllers/GoogleAuthController.php:116` | `get_class($e).': '.$e->getMessage()` vira `$e::class`; mantém `user_id`. |
| 2.5 | `backend/app/Http/Controllers/GoogleAuthController.php:180` | `get_class($e).': '.$e->getMessage()` vira `$e::class`. |
| 2.6 | `backend/app/Support/WhatsApp/WhatsAppNotifier.php:146` | `'error' => $e->getMessage()` vira `'exception' => $e::class` (D3-A); mantém `user_id` e `template`. |

**Por que assim e não outra:** mesmo formato das features anteriores (`$e::class`). Mascarar a mensagem é frágil, e a classe mais a mensagem do log e o contexto bastam para agir. Trocar o e-mail por `user_id` (D2-B) segue o critério de não pôr dado pessoal no log sem uso no diagnóstico.

## 2. Testes (specify §2.8 D4-A)

Um teste novo por ponto, em RED antes do código. Em todos: `Log::spy()`, falha forçada no transporte com um texto sentinela na mensagem da exceção, `Log::shouldHaveReceived(<nível>)->withArgs(...)` filtrando pela mensagem do log (outros logs do mesmo fluxo não podem derrubar a asserção) e afirmando o contexto **exato**; o `json_encode` do contexto não contém o sentinela. A resposta, o redirect ou a exceção relançada é a de sempre.

| Ponto | Onde | Como forçar a falha | Contexto esperado |
|---|---|---|---|
| 2.1 | `backend/tests/Feature/GroupInvitationCreateTest.php` | `Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp: ana@sentinela.example recusou'))` e chamar `sendActivationMail` por `ReflectionMethod` (o método é privado e roda em `app()->terminating`; a reflexão testa o contrato sem depender do ciclo de vida). Se o `terminating` rodar no teste HTTP, usar o fluxo `invite()` e não a reflexão. | `['group_id' => ..., 'user_id' => ..., 'exception' => \RuntimeException::class]` |
| 2.2 | `backend/tests/Feature/InvitationControllerForgotPasswordTest.php` | Mesmo padrão do teste existente (`Mail::shouldReceive('send')->once()->andThrow(...)`), com o sentinela na mensagem. | `['user_id' => $user->id, 'exception' => \Exception::class]`, sem o e-mail do usuário (nem o sentinela) no JSON; resposta 500. |
| 2.3 | `backend/tests/Feature/PreRegistrationServiceTest.php` | Mesmo padrão do `test_falha_no_envio_nao_prende_a_pessoa_no_cooldown` (`Mail::shouldReceive('to')->andThrow(...)`). | `['pre_create_id' => ..., 'exception' => \RuntimeException::class]`; a exceção continua subindo. |
| 2.4 | `backend/tests/Feature/GoogleAuthControllerTest.php` | `Socialite::shouldReceive('driver->stateless->user')->andThrow(new \RuntimeException('google: token ...'))` com o estado de vínculo; redirect `linked=error`. | `['user_id' => ..., 'exception' => \RuntimeException::class]` |
| 2.5 | idem | Mesmo, com o estado de login; redirect `?google_error=1`. | `['exception' => \RuntimeException::class]` |
| 2.6 | `backend/tests/Unit/WhatsApp/WhatsAppNotifierTest.php` | `Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'sentinela da Meta']], 400)])`: a exceção real de `MetaCloudClient::sendTemplate` leva o corpo na mensagem. | `['user_id' => ..., 'template' => ..., 'exception' => <classe da exceção real>]` |

- **Mutantes** (como nas features anteriores): em cada ponto, (A) voltar a `getMessage()` e (B) somar a mensagem à classe; cada um precisa derrubar ao menos um teste; aplicar com backup e `cmp`, conferindo "mutante aplicado". No 2.2, um terceiro (C) volta o `'email'` ao contexto.
- **Verificação:** os testes novos em RED, depois os 6 arquivos tocados inteiros, a suíte cheia e `./vendor/bin/pint --test`.

## 3. Backlog 164 e 165 (specify §1)

- **Onde:** `docs/backlog/whatsapp-notifier-nao-loga-mensagem-da-excecao.md` (164) e `docs/backlog/log-sem-mensagem-da-excecao-nos-demais-pontos.md` (165), mais `docs/backlog/README.md`. Na aprovação das tasks os dois passam a "Promovido para TASK-0xx"; no fechamento vão para `docs/backlog/concluidos/` com a seção "Resolução", como manda o skill (os dois fecham juntos).
- **Sem edição em `docs/sdd/`.**

## 4. Ordem de execução

Uma task de código por grupo, cada uma com testes em RED primeiro: (1) e-mails (2.1, 2.2, 2.3), (2) Google (2.4, 2.5), (3) WhatsApp (2.6). Depois o fechamento (suíte cheia, `security-reviewer`, `pr-readiness-checker`) e a sua validação, e por último o backlog (seção 3), código antes de doc. Sem dependência entre os grupos.

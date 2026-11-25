# Implementation — Log sem a mensagem da exceção nos pontos restantes

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261125

---

## 1. Desvios do fluxo padrão

Nenhum.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-683 | Concluída | 2026-11-25 | Claude | 3 testes novos em RED (`GroupInvitationCreateTest::test_a_failed_activation_mail_logs_only_the_exception_class`, `InvitationControllerForgotPasswordTest::test_mail_failure_logs_user_id_and_only_the_exception_class`, `PreRegistrationServiceTest::test_falha_no_envio_loga_so_a_classe_da_excecao`; falhavam por contexto diferente), depois `'exception' => $e::class` nos 3 pontos, `'user_id'` no lugar do `'email'` no `InvitationController` e comentário do `PreRegistrationService` ajustado. Os 3 arquivos de teste: OK (29, 6 e 21 tests); `./vendor/bin/pint --test`: PASS (275 files). Mutantes A (`getMessage()`), B (classe + mensagem) nos 3 pontos e C (`'email'` de volta) no `InvitationController`: 7 de 7 derrubados, aplicados com backup e `cmp` ("mutante aplicado"), arquivos restaurados idênticos. | `sendActivationMail` é privado e roda em `app()->terminating`: o teste usa `invite()` + `app()->terminate()`, como o teste existente da A1. |
| TASK-684 | Concluída | 2026-11-25 | Claude | 2 testes novos em `GoogleAuthControllerTest` (`test_link_callback_logs_only_the_exception_class_when_socialite_fails`, `test_login_callback_logs_only_the_exception_class_when_socialite_fails`) em RED (o contexto trazia `RuntimeException: google: sentinela...`), depois `'exception' => $e::class` nas linhas 116 e 180. `./vendor/bin/phpunit tests/Feature/GoogleAuthControllerTest.php`: OK (25 tests, 89 assertions); `./vendor/bin/pint --test`: PASS (275 files). Mutantes A e B em cada ponto: 4 de 4 derrubados, aplicados com backup e `cmp`, arquivo restaurado idêntico. | Falha forçada com `Socialite::shouldReceive('driver->stateless->user')->andThrow(...)`. |
| TASK-685 | Concluída | 2026-11-25 | Claude | Teste novo `WhatsAppNotifierTest::test_falha_no_envio_loga_so_a_classe_da_excecao` em RED (o contexto trazia `error` com a mensagem da Meta), depois `'exception' => $e::class` no lugar de `'error' => $e->getMessage()`. `./vendor/bin/phpunit tests/Unit/WhatsApp`: OK (13 tests, 23 assertions); `./vendor/bin/pint --test`: PASS (275 files). Mutantes A (`getMessage()`), B (classe + mensagem) e D (voltar a chave `error`): 3 de 3 derrubados, aplicados com backup e `cmp`, arquivo restaurado idêntico. | Falha real: `Http::response(['error' => ['message' => 'sentinela da Meta']], 400)` gera a `RequestException` de `MetaCloudClient`. O `setUp` já registra um `Http::fake` de 200 que venceria o novo; o teste faz `Http::swap(new Factory)` antes. |

## 3. Fechamento

Executado em 2026-11-25 na branch da feature, depois das TASK-683 a 685 (a TASK-686, de backlog, roda depois da validação).

| Verificação | Resultado |
|---|---|
| `./vendor/bin/phpunit` (suíte cheia do backend) | OK (1755 tests, 9472 assertions), 6 min 2 s |
| `./vendor/bin/pint --test` | PASS (275 files) |
| `security-reviewer` | 0 crítico, 0 alto, 0 médio. Os 6 pontos só gravam ids, nome do template e a classe; respostas, redirects e fluxos inalterados; nenhum outro `Log::*` com `getMessage()` ou exceção inteira em `backend/app`. Informativos fora do escopo (candidatos a backlog): `AuthController.php` (linhas 20-22, 31, 37, 45-48 e 72-75) grava o e-mail em claro em logs de login/logout, e `GroupController.php:51` grava o model `User` inteiro. |
| `pr-readiness-checker` | Pronto para PR, depois da validação humana e da TASK-686: 17 arquivos (5 de `backend/app`, 5 de teste, 3 de backlog, 4 da feature), 5 arquivos de teste tocados OK (88 tests, 381 assertions), rastreabilidade das 3 tasks, sem migration, sem edição em `docs/sdd/`, sem segredo no diff (só sentinelas de teste). |

## 4. Validação local (delegada a mim, "valide por mim", 2026-11-25)

Sem tela: a mudança é só de log. Validei com falhas de transporte **reais**, num script que sobe o app no ambiente de teste (log num arquivo temporário), com um usuário, um grupo e um pré-cadastro criados e apagados no fim (0 sobras conferidas). O SMTP foi apontado para `127.0.0.1:1` (conexão recusada de verdade). O WhatsApp usou `Http::fake` com 400 e um corpo da Meta de sentinela, para não chamar a Meta de verdade. Comparei o código atual com o de `origin/dev` (`git checkout origin/dev -- backend/app`, depois `git checkout HEAD -- backend/app`; árvore conferida limpa).

| Ponto | Código atual | `origin/dev` (comparação) |
|---|---|---|
| Recuperação de senha | 500; `{"user_id":…,"exception":"Symfony\Component\Mailer\Exception\TransportException"}` | `{"email":"<e-mail do usuário>","exception":"Connection could not be established with host \"127.0.0.1:1\" …"}` |
| Convite (após a resposta) | `{"group_id":…,"user_id":…,"exception":"…TransportException"}` | `"error":"Connection could not be established with host …"` |
| Pré-cadastro | exceção relançada (`TransportException`); `{"pre_create_id":…,"exception":"…TransportException"}` | `"exception":"Connection could not be established …"` |
| WhatsApp (Meta 400) | `{"user_id":…,"template":"tpl_e","exception":"Illuminate\Http\Client\RequestException"}` | `"error":"HTTP request returned status code 400: …"` com o corpo da Meta |

No código atual o log não contém o e-mail do usuário, o host nem a porta do SMTP, nem o corpo da Meta; no antigo contém os três. Os dois `catch` do Socialite (vínculo e login) ficam cobertos só pelos testes da TASK-684: provocar a falha real exigiria chamar o Google.

# Tasks — Log sem a mensagem da exceção nos pontos restantes

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261125

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-683 | Os três pontos de envio de e-mail (`GroupInvitationService`, `InvitationController::forgotPassword`, `PreRegistrationService::sendCode`) registram só a classe da exceção | backend | plan.md §1 (2.1 a 2.3) e §2 | nenhum | Concluída |
| TASK-684 | `GoogleAuthController` registra só a classe da exceção nos `catch` do Socialite (vínculo e login) | backend | plan.md §1 (2.4 e 2.5) e §2 | nenhum | Concluída |
| TASK-685 | `WhatsAppNotifier` registra só a classe da exceção na falha de envio | backend | plan.md §1 (2.6) e §2 | nenhum | Pendente |
| TASK-686 | Fechar os itens 164 e 165 do backlog (movê-los para `concluidos/` com a Resolução) | doc | plan.md §3 | nenhum | Pendente |

Ordem: 683, 684 e 685 cada uma com o teste em RED antes do código; 686 por último (código antes de doc). A 683 agrupa três pontos do mesmo tipo (e-mail) e do mesmo padrão de teste; se algum ponto pedir mudança de abordagem, vira task própria. No fechamento da feature rodar a suíte cheia, o `security-reviewer` e o `pr-readiness-checker`. Os itens 164 e 165 passam a "Promovido para TASK-683" na aprovação destas tasks e continuam em `docs/backlog/` até a 686.

## Critérios de aceite

- **TASK-683**:
  - `GroupInvitationService.php` (`sendActivationMail`): o `Log::warning('GroupInvitationService: falha ao enviar o e-mail de convite', ...)` passa a ter o contexto `['group_id' => ..., 'user_id' => ..., 'exception' => $e::class]` (a chave `error` sai).
  - `InvitationController.php` (`forgotPassword`): o contexto do `Log::error('Falha ao enviar e-mail de recuperação de senha', ...)` passa a ser `['user_id' => $user->id, 'exception' => $e::class]`, sem o e-mail; a resposta 500 genérica não muda.
  - `PreRegistrationService.php` (`sendCode`): o contexto passa a ser `['pre_create_id' => ..., 'exception' => $e::class]`; o `throw $e` fica; o comentário diz que o log não leva o código, o e-mail nem a mensagem da exceção.
  - Mensagens de log, níveis e fluxos não mudam.
  - Três testes novos, um por ponto, em `GroupInvitationCreateTest.php`, `InvitationControllerForgotPasswordTest.php` e `PreRegistrationServiceTest.php`: `Log::spy()`, falha forçada no `Mail` com um sentinela na mensagem da exceção, `Log::shouldHaveReceived(<nível>)->withArgs(...)` filtrando pela mensagem do log com o contexto exato, e `json_encode` do contexto sem o sentinela (no 2.2, também sem o e-mail do usuário).
  - Os testes nascem em RED antes do código.
  - Mutantes (A: voltar a `getMessage()`; B: somar a mensagem à classe; C, só no 2.2: devolver o `'email'` ao contexto) derrubados por ao menos um teste cada, aplicados com backup e `cmp`, conferindo "mutante aplicado".
  - Os três arquivos de teste inteiros continuam verdes e `./vendor/bin/pint --test` fica limpo.
- **TASK-684**:
  - `GoogleAuthController.php`: os contextos das linhas 116 (vínculo) e 180 (login) passam a ter `'exception' => $e::class` (sem `get_class($e).': '.$e->getMessage()`), mantendo `user_id` no vínculo.
  - Mensagens de log e redirects (`linked=error`, `?google_error=1`) não mudam; as linhas 132 e 228 não são tocadas.
  - Em `GoogleAuthControllerTest.php`, dois testes novos com `Log::spy()` e `Socialite::shouldReceive('driver->stateless->user')->andThrow(...)` com um sentinela na mensagem: cada um afirma o redirect de erro, o `Log::shouldHaveReceived('warning')->withArgs(...)` com o contexto exato e o `json_encode` do contexto sem o sentinela.
  - Testes em RED antes do código; mutantes A e B derrubados em cada ponto, aplicados com backup e `cmp`.
  - O arquivo inteiro continua verde e o Pint fica limpo.
- **TASK-685**:
  - `WhatsAppNotifier.php`: o `Log::warning('WhatsApp: falha ao enviar notificação', ...)` passa a ter o contexto `['user_id' => ..., 'template' => ..., 'exception' => $e::class]` (a chave `error` sai); o fluxo não muda.
  - Em `backend/tests/Unit/WhatsApp/WhatsAppNotifierTest.php`, um teste novo com `Log::spy()` e `Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'sentinela da Meta']], 400)])`: afirma o contexto exato com a classe da exceção real e o `json_encode` do contexto sem o sentinela.
  - Teste em RED antes do código; mutantes A e B derrubados, aplicados com backup e `cmp`.
  - O arquivo inteiro continua verde e o Pint fica limpo.
- **TASK-686**:
  - `docs/backlog/whatsapp-notifier-nao-loga-mensagem-da-excecao.md` (164) e `docs/backlog/log-sem-mensagem-da-excecao-nos-demais-pontos.md` (165) ganham a seção "Resolução" (data, pasta da feature, tasks 683 a 685, PRs) e vão para `docs/backlog/concluidos/`.
  - No `docs/backlog/README.md` as duas linhas saem do Índice e entram em "Concluídos" apontando para `concluidos/`.
  - `git diff` sem edição em `docs/sdd/` nem em feature arquivada.
  - O item 166 continua sem registro (decisão dele pendente).

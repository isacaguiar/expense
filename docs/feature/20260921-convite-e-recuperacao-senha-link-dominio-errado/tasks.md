# Tasks — Link de e-mail (convite de grupo e recuperação de senha) aponta pro domínio errado

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260921

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-328 | Trocar `url()` por `config('services.frontend_url')` no link do convite de grupo | backend | plan.md §1 | nenhum | Concluída |
| TASK-329 | Trocar `url()` por `config('services.frontend_url')` no link de recuperação de senha | backend | plan.md §1 | nenhum | Concluída |
| TASK-330 | Adicionar teste que prova o host do link de `UserInvitedMail` | backend | plan.md §3 | nenhum | Concluída |
| TASK-331 | Adicionar teste que prova o host do link de `forgotPassword` | backend | plan.md §3 | nenhum | Concluída |
| TASK-332 | Dar a `AcceptInvitePage` a prop `mode` e a copy condicional de `mode="reset"` | frontend | plan.md §2 | nenhum | Concluída |
| TASK-333 | Registrar a rota `/recuperar-senha` em `App.tsx` | frontend | plan.md §2 | nenhum | Concluída |
| TASK-334 | Teste de `AcceptInvitePage` para `mode="reset"` | frontend | plan.md §3 | nenhum | Concluída |
| TASK-335 | Revisão de segurança (`security-reviewer`) antes do PR | doc | plan.md §4 | antes do merge | Concluída |

## Critérios de aceite

- **TASK-328**: `UserInvitedMail.php:33` monta `$activationLink` com `config('services.frontend_url')` em vez de `url()`. `php artisan tinker --execute="echo (new App\Mail\UserInvitedMail(...))->build()->viewData['activationLink'];"` (ou teste equivalente) mostra um link começando com o valor de `FRONTEND_URL`, não `APP_URL`. `./vendor/bin/pint --test app/Mail/UserInvitedMail.php` limpo.

- **TASK-329**: `InvitationController.php:84` monta `$resetLink` com `config('services.frontend_url')` em vez de `url()`. Nenhuma outra linha do método `forgotPassword` muda. `./vendor/bin/pint --test app/Http/Controllers/InvitationController.php` limpo.

- **TASK-330**: `php artisan test --filter=GroupMemberInvitationMailTest` verde, com o closure de `Mail::assertSent(UserInvitedMail::class, ...)` (linha ~51) passando a afirmar que `$mail->build()->viewData['activationLink']` começa com `config('services.frontend_url')`. Reverter TASK-328 localmente faz este teste falhar (prova que ele pega a regressão).

- **TASK-331**: `php artisan test --filter=InvitationControllerForgotPasswordTest` verde, com um caso novo que captura, via `Mail::shouldReceive('send')` (Mockery, mesmo padrão já usado nesta classe), o array de dados passado para a view e afirma que `$data['resetLink']` começa com `config('services.frontend_url')`. Reverter TASK-329 localmente faz este teste falhar.

- **TASK-332**: `AcceptInvitePage.tsx` aceita `mode?: 'invite' | 'reset'` (default `'invite'`). Com `mode="reset"`: título "Redefinir senha", botão "Redefinir senha", alerta de parâmetros faltando fala em "recuperação" (não "convite"), snackbar de sucesso fala em "redefinida". Renderizar `<AcceptInvitePage />` sem prop mostra exatamente o texto de hoje — `AcceptInvitePage.test.tsx` (suíte existente, sem alteração) continua 100% verde.

- **TASK-333**: `frontend/src/App.tsx` ganha `<Route path="/recuperar-senha" element={<AcceptInvitePage mode="reset" />} />`, ao lado de `/aceitar-convite`. Com backend e frontend rodando, navegar para `/app/recuperar-senha?email=...&token=...` (token válido gerado por `POST /api/forgot-password`) mostra a página em modo reset e, ao submeter, redefine a senha e redireciona para `/`. `npx tsc --noEmit` sem erro.

- **TASK-334**: novo bloco em `AcceptInvitePage.test.tsx` renderizando `<AcceptInvitePage mode="reset" />` sob `/recuperar-senha`, cobrindo pelo menos: submissão bem-sucedida mostra o snackbar de "redefinida"; botão tem o rótulo "Redefinir senha"; falta de `email`/`token` mostra o alerta de recuperação (não o de convite). `npx vitest run src/pages/AcceptInvitePage.test.tsx` verde.

- **TASK-335**: agent `security-reviewer` executado sobre o diff final (`InvitationController.php`, `UserInvitedMail.php`) antes de abrir o PR, sem achado bloqueante pendente — achado não-bloqueante vira item de backlog, não trava esta feature.

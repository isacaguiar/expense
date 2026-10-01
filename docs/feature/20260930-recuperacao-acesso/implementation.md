# Implementation — Recuperação de Acesso

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260930

---

## 1. Desvios do fluxo padrão

Nenhum desvio — segue `docs/sdd/04-implementation.md` sem exceção.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-380 | Concluída | 2026-09-30 | Claude (IA) | `php artisan test --filter=EmailBrandingTest` — 4/4 passed (8 assertions). `php artisan test --filter="InvitationControllerMailViewsTest\|GroupMemberInvitationMailTest\|PreRegisterCodeMailTest\|InvitationControllerForgotPasswordTest"` — 12/12 passed (30 assertions). `./vendor/bin/pint --test` — 164 files, PASS. | Logo copiada para `backend/resources/images/mail/logo-expense.png`; CID embed nas 4 views; assunto do e-mail de recuperação de senha corrigido em `InvitationController.php:92`. |
| TASK-381 | Concluída | 2026-09-30 | Claude (IA) | `npx vitest run src/pages/ForgotPasswordPage.test.tsx src/pages/LoginPage.test.tsx` — 16/16 passed. `npx vitest run` (suíte completa) — 297/297 passed, 44 arquivos. `npx tsc --noEmit` — sem erros. `npm run build` — build ok (warning pré-existente de chunk size, não relacionado). | Nova rota `/recuperar-acesso`, `ForgotPasswordPage.tsx` + `ForgotPasswordFormCard.tsx`; link morto do login corrigido em `LoginFormCard.tsx`; `LoginPage.test.tsx:193` atualizado para o novo href. |
| TASK-382 | Concluída | 2026-09-30 | Claude (IA) | Validação manual via Browser pane contra backend/frontend locais já em execução (`php artisan serve` :8000, `npm run dev` :3000) e Mailpit (:8025): (1) login → "Esqueci minha senha" → `/recuperar-acesso`; (2) envio com e-mail real de usuário local → mensagem de sucesso genérica exibida; (3) e-mail recebido no Mailpit, assunto "Recuperação de senha - Shared Expense", logo embutida (CID, badge "Inline image (1)"), sem nenhum texto "Novemax", rodapé "© 2026 Shared Expense"; (4) reenvio imediato → UI exibe a mensagem de rate limit (429) do backend; (5) token do e-mail usado para abrir `/recuperar-senha` localmente e concluir a troca de senha → sucesso, redirecionamento para `/`; (6) segundo e-mail "Conta ativada com sucesso" recebido, mesma marca correta. | `FRONTEND_URL` do `.env` local não foi alterado (sandbox do Claude Code bloqueou leitura/edição do arquivo por "Credential Materialization") — o link do e-mail aponta para produção (`https://expense-app.novemax.com.br`); a etapa 2 foi validada navegando direto para a URL local com o token capturado no e-mail, sem precisar do link. Senha do usuário de teste local (`<e-mail omitido>`, banco de dev local) foi alterada para `<omitido>` durante o teste — avisar o usuário. |

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


## 3. Integração com `dev` e revisão de segurança (2026-10-30)

A branch ficou parada de 30/09 a 30/10 sem virar PR. Antes do PR, foi integrada com o `dev` atual e revisada.

| Passo | Comando e resultado |
|---|---|
| Merge de `dev` | `git merge --no-ff dev` → sem conflito (a simulação com `git merge-tree` já tinha dado limpa). Os arquivos que a branch toca mudaram pouco em `dev` (`App.tsx` 1 commit, `LoginPage.test.tsx` 1, `LoginFormCard.tsx` nenhum). |
| Banco de teste | MySQL isolado na porta 3307; alvo conferido com `tinker` (`127.0.0.1:3307/ex-db`) antes de qualquer migration; `php artisan migrate:fresh --env=testing --force` → 33 migrações. |
| Backend | `php artisan test --filter "EmailBrandingTest\|InvitationControllerMailViewsTest\|GroupMemberInvitationMailTest\|PreRegisterCodeMailTest\|InvitationControllerForgotPasswordTest"` → **16 passed (38 assertions)**; `php artisan test` (suíte completa) → **874 passed (5159 assertions)**, 286 s; `./vendor/bin/pint --test` → PASS (198 arquivos). |
| Frontend | `npx tsc --noEmit` → limpo; `npx vitest run src/pages/ForgotPasswordPage.test.tsx src/pages/LoginPage.test.tsx src/App.test.tsx src/analytics --maxWorkers=1` → 9 arquivos, 59 passed; `npx vitest run --maxWorkers=1` (suíte inteira) → **73 arquivos, 1009 passed**, 1248,98 s. A 1ª tentativa da suíte inteira travou por falta de memória (425 MB livres) e foi encerrada; a 2ª rodou sozinha, com o MySQL de teste parado. |
| Revisão de segurança | agent `security-reviewer`, somente leitura: **nenhuma vulnerabilidade nova de código**; rotas, middleware e configuração não mudaram. Os riscos do fluxo de recuperação já existiam em `dev`; a tela pública os torna visíveis. |

### Achados e tratamento

| Achado | Severidade | Origem | Tratamento |
|---|---|---|---|
| F1: só o limite global por IP (60/min) e corrida no limite por e-mail | média | pré-existente | item **118** do backlog |
| F2: enumeração de e-mail; a tela nova confirma a conta pelo 429 | baixa | pré-existente (a tela acrescenta o oráculo do 429) | **opção A, risco aceito e documentado** (`specify.md` §3, critério da TASK-381, comentário da tela); conserto no item **119** |
| F3, F4, F5: `===` no token, token em texto puro no cache, `verify` sem revogar outros tokens nem sessões | baixa e informativa | pré-existente | item **120** |
| F6: eventos automáticos de medição aprimorada do GA4 podem levar e-mail e token da URL | média, condicional | pré-existente | item **122** (ação no console do GA4) |
| F7: o link não codifica o e-mail (`+` vira espaço) | média (funcional) | pré-existente | item **121** |
| F8: senha de teste e e-mail no log da TASK-382 | baixa | **introduzido pela branch** | tratado (abaixo) |
| F9: UX da tela e remetente "SCD Novemax" | informativa | UX da branch; remetente pré-existente | itens **123** e **124** |

### F8: senha de teste no log, e a publicação da branch

O log da TASK-382 (linha 21 deste arquivo) registrava, em texto puro, a senha de um usuário de teste local e o e-mail do mantenedor, desde o commit da TASK-381. A branch foi publicada como backup em 2026-10-30, num repositório público, antes de o conteúdo ser varrido. Tratamento: a branch remota foi apagada; os quatro commits que não estão em `dev` foram reescritos (`git filter-branch --tree-filter`), trocando os dois valores por `<omitido>`; a versão limpa foi republicada. Conferido no GitHub: a senha não existe em nenhum dos commits publicados nem em `dev`. **O valor não é repetido aqui.** Pendente, com o usuário: conferir que aquela senha não é usada em nenhuma conta real.

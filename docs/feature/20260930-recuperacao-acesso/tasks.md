# Tasks — Recuperação de Acesso

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260930

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-380 | Corrigir marca "Novemax"→"Shared Expense" nos 4 templates de e-mail (CID embed da logo) | backend | plan.md §1 | nenhum | Concluída |
| TASK-381 | Criar tela pública "Esqueci minha senha" (etapa 1) e ligar o link morto do login | frontend | plan.md §2 | nenhum | Concluída |
| TASK-382 | Validar fluxo completo de recuperação de senha enviando e-mail real via Mailpit local | doc | plan.md §3 | nenhum | Concluída |

## Critérios de aceite

- **TASK-380**: `php artisan test --filter=EmailBrandingTest` verde — as 4 views renderizadas não contêm a string "Novemax" e o `src` da logo contém `cid:`; assunto do e-mail de recuperação de senha é "Recuperação de senha - Shared Expense"; suítes `InvitationControllerMailViewsTest`, `GroupMemberInvitationMailTest`, `PreRegisterCodeMailTest` continuam verdes.
- **TASK-381**: navegar para `/` → clicar em "Esqueci minha senha" → chega em `/recuperar-acesso`; preencher e-mail e enviar retorna a mesma mensagem de sucesso tanto para e-mail existente quanto inexistente; reenviar imediatamente mostra a mensagem de rate limit (429) vinda do backend (**nota de risco aceito, revisão de segurança de 2026-10-30:** os dois comportamentos juntos fazem a tela confirmar a existência da conta no 429; aceito, conserto no item 119 do backlog); `ForgotPasswordPage.test.tsx` (novo) e `LoginPage.test.tsx` (atualizado) passam.
- **TASK-382**: e-mail chega em `http://localhost:8025` com a logo correta e sem nenhum texto "Novemax"; o link do e-mail abre `/recuperar-senha` com `email`+`token` preenchidos; completar a troca de senha funciona e dispara o segundo e-mail ("Conta ativada com sucesso"), também com a marca correta; passo a passo e comandos executados registrados em `implementation.md`.

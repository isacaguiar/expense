# Bugfix — Logo e marca "Novemax" nos e-mails do sistema (deveria ser Shared Expense)

Versão: 1.0 · Criado em: 20260930 · Branch: `fix/20260930-logo-email-marca-novemax`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [x] **Auth / autorização / dado sensível**
- [ ] **Migration ou contrato de API**
- [ ] **Causa raiz obscura / correção ampla**
- [ ] **Decisão de produto/arquitetura**

Caixa marcada → **não segue no BFF**. Ver "Escalonamento" abaixo.

## 1. Problema

- **Sintoma:** os 4 e-mails transacionais do sistema (código de pré-cadastro, convite de grupo, recuperação de senha, confirmação de ativação) exibem a logo e o nome de marca "Novemax" em vez da marca do produto (Shared Expense).
- **Reprodução:** disparar qualquer um dos 4 fluxos (`/pre-register`, convite de membro de grupo, `/api/forgot-password`, `/api/invitations/verify`) e inspecionar o e-mail recebido (local, via Mailpit em `http://localhost:8025`, ou em produção).
- **Esperado vs. atual:** esperado — logo e textos de marca do Shared Expense. Atual — logo externa `https://novemax.com.br/assets/images/logo.png` (alt="Novemax") e textos "Novemax"/"Novemax Soluções em Informática" hardcoded.
- **Causa raiz:** resquício de outro projeto/cliente nos templates Blade:
  - `backend/resources/views/email/pre-register-code.blade.php:5,50,69`
  - `backend/resources/views/email/password-reset.blade.php:5,18,36`
  - `backend/resources/views/email/invitation.blade.php:5,48,51,55,73`
  - `backend/resources/views/email/activation-confirmation.blade.php:5,39,46,51`
  - Assunto hardcoded em `backend/app/Http/Controllers/InvitationController.php:92` (`'Recuperação de senha - Novemax'`).

## Escalonamento

A correção exige editar `backend/app/Http/Controllers/InvitationController.php` (assunto do e-mail de recuperação de senha), que é um controller de autenticação/recuperação de acesso — caixa 1 da Triagem marcada. Além disso, o mesmo fluxo de autoatendimento tem uma lacuna de desenvolvimento novo (tela de "esqueci minha senha", etapa 1, inexistente no frontend), que por si só já exigiria `/nova-feature`.

Por decisão do usuário, as duas frentes foram unificadas em uma única feature:

→ **`docs/feature/concluidas/202609/20260930-recuperacao-acesso/`**

Este arquivo fica só como registro do achado original e do motivo da escalada; a correção, os arquivos tocados e o log de implementação estão na feature acima.

## Resolução
Concluído em: 2026-10-30
Branch: backend/20260930-recuperacao-acesso (a branch `fix/20260930-logo-email-marca-novemax` não chegou a existir: a correção foi feita na branch da feature)
PR: https://github.com/isacaguiar/expense/pull/257

Resolvido **dentro da feature para a qual o bug escalou**, e não pelo BFF: a TASK-380 de `docs/feature/concluidas/202609/20260930-recuperacao-acesso/` trocou a marca "Novemax" e a logo pela do Shared Expense nos 4 templates de e-mail e no assunto do e-mail de recuperação de senha. A feature foi mergeada em `dev` em 2026-10-30 (PR #257) e promovida a `main` pelo PR #258. **Ficou de fora** o nome do remetente (`MAIL_FROM_NAME="SCD Novemax"` em `.github/workflows/deploy-backend.yml:53`), que não está nos templates: está registrado como item 124 do backlog (`docs/backlog/remetente-scd-novemax-e-doc-comparado-por-hash.md`).

# Specify — Notificação por WhatsApp ao enviar comprovante de pagamento

> Feature: quando alguém anexa o comprovante de um pagamento, a contraparte recebe um aviso no WhatsApp com um link para ver no app. Pedido novo (2026-08-29), discutido e desenhado em conversa; não vem de épico de `docs/sdd/03-tasks.md`.

Versão: 1.0 · Criado em: 20260829

---

## 1. Problema

Hoje o fluxo de comprovante é silencioso para a contraparte:

- No `ExpenseController@pay` o **credor** de uma despesa marca a ocorrência da competência como paga e pode anexar um comprovante (`comprovante`, opcional — `backend/app/Http/Controllers/ExpenseController.php:725`).
- No `ExpenseController@confirmSettlement` um **devedor** confirma que pagou via Pix o valor líquido que deve a um credor na competência, e o comprovante é obrigatório (`backend/app/Http/Controllers/ExpenseController.php:823`).

Em ambos os casos, quem precisa saber que o comprovante existe (o devedor que foi pago, ou o credor que recebeu) só descobre abrindo o app e navegando até a despesa ou a Tela de Pagamentos. Não há nenhuma notificação ativa.

O projeto já preparou o terreno para avisar por WhatsApp, mas nada usa isso ainda:

- `ex_users.whatsapp` (string, formato `(XX) 9XXXX-XXXX`) e `ex_users.notify_whatsapp` (boolean, default `false`) já existem (`backend/database/migrations/2026_08_22_193236_add_whatsapp_to_users_table.php`) e são editáveis no perfil (`UserController@updateProfile`, `frontend/src/pages/Profile.tsx`).
- Não existe nenhuma integração de mensageria no backend: sem provider, sem `app/Jobs`, `QUEUE_CONNECTION=sync`.

Esta feature liga a ponta que falta: transformar o upload de comprovante em um aviso de WhatsApp para quem tem o número cadastrado e o consentimento (`notify_whatsapp = true`) ligado.

## 2. Requisitos

### 2.1 Disparo no comprovante do credor (`pay`)

Quando `ExpenseController@pay` conclui com sucesso **e** um `comprovante` foi de fato anexado na requisição, o sistema notifica os **membros pagadores da despesa, exceto o próprio credor** (`$expense->payers` menos `user_payer_id`).

Se `pay` for chamado sem `comprovante` (fluxo antigo do `ExpenseManager`), nenhuma notificação é enviada.

### 2.2 Disparo no comprovante do devedor (`confirmSettlement`)

Quando `ExpenseController@confirmSettlement` conclui com sucesso, o sistema notifica **o credor** daquele settlement (`to_user_id`). Vale tanto para a primeira confirmação quanto para um reenvio que substitui o comprovante anterior.

### 2.3 Filtro de destinatário

Um usuário só é notificado se **ambos**: `whatsapp` preenchido (não nulo) **e** `notify_whatsapp = true`. O consentimento é exatamente esse boolean já existente — sem tela de termo, sem coluna nova, sem registro versionado.

Destinatário que não passa no filtro é simplesmente ignorado (sem erro, sem fila de espera). Se nenhum destinatário passa, o disparo não acontece e a ação principal segue normal.

### 2.4 Conteúdo da mensagem

Mensagem de **texto** (sem a imagem do comprovante), com um botão/link que abre o app.

- **Caso `pay` (credor → pagadores):** identifica o credor que registrou o pagamento, o **nome da despesa**, o **valor**, o **tipo da despesa** e a **competência** no formato `set/2026`.
- **Caso `confirmSettlement` (devedor → credor):** identifica o devedor que confirmou o pagamento, o **valor total** do settlement e a **competência** (`set/2026`). Não há despesa única aqui — o valor é o líquido netado do ciclo —, então a mensagem não cita despesa.

### 2.5 Link para o app

- Caso `pay`: link para a tela de detalhe da despesa já existente — `FRONTEND_URL` + `/app/groups/{groupId}/expenses/{expenseId}` (`ExpenseView`, rota `/groups/:id/expenses/:expenseId`), que já mostra o estado "pago" e "Ver comprovante".
- Caso `confirmSettlement`: link para a Tela de Pagamentos — `FRONTEND_URL` + `/app/groups/{groupId}/payments` (`Payments`, grade de settlements).

O comprovante em si continua acessível só dentro do app, sob o controle de acesso que já existe (rota `proofs.show` com URL assinada). O link do WhatsApp não carrega credencial nem URL assinada.

### 2.6 Entrega best-effort, não bloqueante

- O envio acontece **depois** de a resposta HTTP da ação principal (`pay` / `confirmSettlement`) ter sido entregue ao cliente — não adiciona latência perceptível à ação.
- Falha de qualquer natureza no envio (Meta fora do ar, número inválido, timeout, template rejeitado) **nunca** altera o resultado da ação principal nem o status HTTP: o pagamento/confirmação já está gravado. A falha é registrada em log (`Log::warning`) e descartada — **sem retry**.
- Uma falha para um destinatário não impede o envio aos demais.
- A feature inteira fica atrás de uma flag de configuração (desligada por padrão). Com a flag desligada, nenhum envio nem chamada externa acontece.

### 2.7 Segurança / segredo

O token de acesso da Meta é segredo. Adicioná-lo ao `.env` de produção e ao `deploy-backend.yml` é gate humano (`00-constitution.md` §5.2 — "Rotacionar, expor ou remover segredo/credencial"). O código lê o token só de `config`/`env`, nunca hardcoded.

## 3. Fora de escopo desta feature

- **Enviar a imagem do comprovante pelo WhatsApp** — v1 manda só texto + link; a imagem fica no app.
- **Webhooks de status** (entregue/lido), mensagens recebidas do usuário, conversas.
- **Fila com retry / worker dedicado** — o host compartilhado (plano M, sem SSH) não roda worker; entrega é best-effort síncrona pós-resposta. Migrar para fila durável é decisão futura própria.
- **Qualquer mudança de frontend** — o opt-in já existe no perfil; a tela de detalhe da despesa (`ExpenseView`) já mostra pagamento e comprovante. Polir a proeminência do estado "pago" nessa tela, se desejado, é task separada.
- **Outros canais** (e-mail, push do navegador, o sino de notificações do backlog item 020).
- **Notificar em outros eventos** (despesa criada, ciclo fechado, convite) — só o upload de comprovante.
- **Escolha/abstração de múltiplos providers** — a decisão é Meta WhatsApp Cloud API (registrar em ADR); não se constrói camada de troca de provider agora.
- **Aprovação dos templates na Meta** — é processo externo (horas a dias), pré-requisito operacional, não entrega de código. A feature sobe com a flag desligada até os templates existirem e os secrets estarem em produção.
- **Internacionalização** — mensagens só em `pt_BR`.
- **Formato de telefone além de `(XX) 9XXXX-XXXX`** — assume o formato que o `updateProfile` já valida; normalização para E.164 (`55` + dígitos) é detalhe de implementação.

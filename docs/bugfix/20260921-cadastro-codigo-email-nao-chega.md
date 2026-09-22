# Bugfix — Código de confirmação do cadastro não chega ao e-mail (produção)

Versão: 1.0 · Criado em: 20260921 · Branch: `fix/20260921-cadastro-codigo-email-nao-chega`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [ ] **Auth / autorização / dado sensível** — o sintoma aparece no fluxo de pré-cadastro, mas a correção não toca rota, controller nem middleware: o que está errado é configuração de envio (workflow / cPanel / DNS). Se a correção vier a mexer em `PreRegistrationService` ou `PreRegisterController`, marcar e escalar.
- [ ] **Migration ou contrato de API** — nenhum schema, rota, payload ou status code muda.
- [ ] **Causa raiz obscura / correção ampla** — a camada está isolada e provada (§1, itens 3-7); no repositório a correção é de no máximo 1 arquivo (`.github/workflows/deploy-backend.yml`).
- [ ] **Decisão de produto/arquitetura** — **não hoje**. Condição de escalação registrada: se o veredito do cPanel → Track Delivery for bloqueio/reputação do IP compartilhado e a correção escolhida for trocar o transporte para relay dedicado (Resend / Mailgun / SES / Postmark), isso é decisão de stack (`00-constitution.md` §3) → marcar esta caixa, abrir `/nova-feature` + ADR em `docs/sdd/decisions/`, e deixar aqui só um ponteiro.

Nenhuma marcada → segue no BFF.

## 1. Problema

- **Sintoma:** em produção, o formulário de `/cadastro` é submetido com sucesso e a tela avança normalmente para a etapa "digite o código", mas o e-mail com o código de 6 dígitos nunca chega à caixa da pessoa. Sem o código, a conta não é criada — o cadastro público está inutilizável em produção. Atinge igualmente os outros dois e-mails do sistema (`UserInvitedMail`, recuperação de senha), que usam o mesmo mailer default.
- **Reprodução:** 1) abrir `https://expense.novemax.com.br/app/cadastro`; 2) preencher o formulário com um e-mail Gmail válido; 3) submeter → `POST /api/pre-register` devolve 200 e a UI mostra a etapa do código; 4) conferir a caixa de entrada e o spam do destinatário → nada chega, nem depois de minutos.
- **Esperado vs. atual:** esperado — o e-mail com o código chega em segundos, dentro da validade de 15 min (`PreRegistrationService::CODE_TTL_MINUTES`). Atual — nenhuma mensagem chega e nenhum erro é sinalizado em lugar nenhum: nem na tela, nem no `laravel.log`, nem como bounce.
- **Causa raiz:** **parcialmente determinada — não fechar esta seção antes do veredito do Track Delivery.** Está provado que a aplicação e a configuração de e-mail do Laravel estão corretas (itens 3 e 6 da investigação abaixo): o Exim da conta **aceita e entrega** mensagens enviadas pelo app. A falha está na **entrega de saída** do Exim da hospedagem compartilhada (`br980.hostgator.com.br`) para destinos externos — fora do repositório. Falta o veredito por mensagem (cPanel → Email → Track Delivery e Mail Queue Manager) para saber se é deferimento por reputação do IP compartilhado, limite de envio da conta ou descarte silencioso.

### Investigação (2026-09-21)

| # | Verificação | Resultado | O que elimina |
|---|---|---|---|
| 1 | `gh secret list --env PROD -R isacaguiar/expense` | `ENV_MAIL_HOST`, `ENV_MAIL_USERNAME`, `ENV_MAIL_PASSWORD` presentes (desde 2025-06-13) | Secret de mail faltando |
| 2 | Log das runs `35654704512` e `35624683808` do `deploy-backend.yml` | `.env` aparece no rsync de todo deploy; `config:cache` e `route:cache` OK; `Nothing to migrate` | `.env` antigo/manual no servidor; migration pendente |
| 3 | `php artisan tinker --execute="echo config('mail.default') …"` no servidor | `smtp \| mail.novemax.com.br:587 \| enc=tls \| user=admin@novemax.com.br \| from=no-reply@expense-api.novemax.com.br` | Mailer efetivo ser `log`/`array`; host/porta/TLS errados |
| 4 | Teste A — `Mail::raw` com o `from` padrão, para dois Gmails distintos | `ok A` (sem exceção); nenhuma mensagem chegou | Credencial SMTP inválida, host inacessível, view quebrada |
| 5 | Teste B — `Mail::raw` com `->from('admin@novemax.com.br')` | `ok B`; também não chegou | Desalinhamento `From` × conta autenticada como causa **suficiente** |
| 6 | Teste C — `Mail::raw` para `admin@novemax.com.br` (destino local) | **Chegou** — `Date: Mon, 21 Sep 2026 23:17:08 +0000`, `Subject: Teste C - destino local` | Toda a pilha Laravel + SMTP + Exim de entrada |
| 7 | Maildir de `admin@novemax.com.br` (`~/mail/novemax.com.br/admin/{new,cur}`) após os testes | Nenhuma DSN / `Mailer-Daemon` / bounce; as mensagens vizinhas são de 21/09 05:20 e 16/09, sem relação | Rejeição dura do Gmail dentro da sessão SMTP (geraria bounce em segundos) |
| 8 | `nslookup -type=TXT/MX` nos dois domínios | `novemax.com.br`: SPF com `+include:websitewelcome.com`. `expense-api.novemax.com.br`: SPF **sem** o include e **sem MX**; DKIM publicado | — (achado, ver abaixo) |
| 9 | `exim -bp` no servidor | `permission denied; not admin` | Inspeção da fila pelo shell — só via cPanel |

**Achados de configuração já confirmados, independentes do veredito final** (entram na §2 conforme a correção escolhida):

- **(a) Remetente desalinhado com a conta autenticada** — `.github/workflows/deploy-backend.yml:51` fixa `MAIL_FROM_ADDRESS=no-reply@expense-api.novemax.com.br`, enquanto o `AUTH` do SMTP é `admin@novemax.com.br`. O endereço do `From` quase certamente não existe como caixa, e o subdomínio não tem MX — bounce não tem para onde voltar e callout de verificação de remetente cai no vazio.
- **(b) SPF do subdomínio incompleto** — `expense-api.novemax.com.br` não declara `include:websitewelcome.com` (faixa de saída da HostGator), que o domínio principal declara.
- **(c) `.env` gerado sem aspas** — `deploy-backend.yml:31-64` escreve `echo "CHAVE=${{ secrets.X }}" >> .env`; valor com `#`, espaço ou `$` é truncado/interpolado pelo dotenv. Não é a causa aqui (o SMTP autentica), mas é uma armadilha ativa para qualquer rotação de senha.
- **(d) Cegueira de observabilidade** — `app/Services/PreRegistrationService.php:277-296` só registra log **na falha**; um envio aceito não deixa rastro nenhum. É o que permitiu o `plan.md` §8 da feature `20260919-cadastro-de-usuarios` afirmar que o SMTP de produção estava validado quando a única prova existente era o Mailpit local (TASK-125, TASK-287).

**Achado adjacente, outro bug, não corrigir aqui:** `app/Mail/UserInvitedMail.php:36` e `app/Http/Controllers/InvitationController.php:83` montam os links com `url()`, ou seja `APP_URL` = `https://expense-api.novemax.com.br` (domínio da API) em vez de `FRONTEND_URL` (`config/services.php:40`, que o `GoogleAuthController` já usa). Convite e recuperação de senha chegam com link morto. Vira `/novo-bug` próprio.

## 2. Correção

- **O que muda e por quê:** <descrição da mudança>
- **Arquivos tocados:** <lista>
- **Teste de regressão:** <qual teste reproduz o bug e passa a verde com a correção — ou "sem teste: <motivo>", ex. bug puramente visual>
- **Riscos / efeitos colaterais:** <o que mais pode ser afetado; "nenhum identificado" se for o caso>

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| <AAAA-MM-DD> | <ex.: `cd frontend && npx tsc --noEmit`> | <ex.: sem erros> |

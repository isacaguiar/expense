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
- **Causa raiz:** **confirmada (Teste E).** `config/mail.php:46` repassa `MAIL_EHLO_DOMAIN` para `local_domain` do transporte SMTP, e essa variável **nunca era definida em nenhum lugar do projeto** — não estava em `.env.example`, não estava em `.env`/`.env.production` local, e principalmente não estava em `.github/workflows/deploy-backend.yml`, que gera o `.env` de produção (item 11). Sem `local_domain`, o Symfony Mailer declarava o HELO/EHLO da sessão SMTP usando o hostname da própria máquina — em hospedagem compartilhada isso normalmente não é um domínio reconhecível (`localhost`/hostname interno), e a HostGator filtra silenciosamente (aceita, nunca entrega, sem bounce) mensagens cujo HELO não bate com um domínio válido apontando para o IP do servidor. É comportamento documentado da própria HostGator para clientes com o mesmo sintoma (PHP/Laravel, e-mail de verificação de conta): suporte da HostGator respondeu a um relato idêntico dizendo textualmente que o script "está enviando de localhost como o nome HELO (ou 127.0.0.1)" e que os servidores deles "não permitem que e-mails sejam enviados do localhost por questões de segurança" — <https://www.reddit.com/r/laravel/comments/tj85xt/problem_with_hostgators_smtp_filtering/>. Isso explica de forma consistente toda a investigação: Testes A e B aceitos sem exceção e sem bounce (item 4-5, filtro de saída silencioso, não rejeição SMTP), Teste C entregue (item 6, entrega **local** dentro do próprio servidor não passa pelo mesmo filtro de HELO de saída), nenhuma DSN no Maildir (item 7, não é rejeição — é descarte), e o **Teste E chegou** (item 12) com `local_domain` setado explicitamente para `novemax.com.br`, nas mesmas condições dos testes A/B que sumiram sem ele.

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
| 10 | `uapi EmailTrack search` no servidor | `errors: Failed to load module "EmailTrack": Can't locate Cpanel/API/EmailTrack.pm in @INC` | API do Track Delivery via shell — módulo não instalado nesta conta HostGator; só resta a UI web (cPanel → Email → Track Delivery) |
| 11 | Auditoria de `MAIL_EHLO_DOMAIN` no repo (`.env.example`, `.env`, `.env.production`, `deploy-backend.yml`) | Variável **não definida em nenhum arquivo**; `config('mail.mailers.smtp.local_domain')` resolve `null` em produção | Hipótese descartada; confirma que o HELO/EHLO da sessão SMTP fica a cargo do fallback do Symfony Mailer, não de config explícita |
| 12 | Teste E — mesmo `Mail::raw` dos testes A/B, com `config(['mail.mailers.smtp.local_domain' => 'novemax.com.br'])` antes do envio | **Chegou** — `ok E`, mensagem recebida na caixa Gmail de destino | Confirmação final: com `local_domain` setado o e-mail chega; sem ele (testes A/B, mesmas condições) some. Causa raiz provada |

**Achados de configuração já confirmados, independentes do veredito final** (entram na §2 conforme a correção escolhida):

- **(a) Remetente desalinhado com a conta autenticada** — `.github/workflows/deploy-backend.yml:51` fixa `MAIL_FROM_ADDRESS=no-reply@expense-api.novemax.com.br`, enquanto o `AUTH` do SMTP é `admin@novemax.com.br`. O endereço do `From` quase certamente não existe como caixa, e o subdomínio não tem MX — bounce não tem para onde voltar e callout de verificação de remetente cai no vazio.
- **(b) SPF do subdomínio incompleto** — `expense-api.novemax.com.br` não declara `include:websitewelcome.com` (faixa de saída da HostGator), que o domínio principal declara.
- **(c) `.env` gerado sem aspas** — `deploy-backend.yml:31-64` escreve `echo "CHAVE=${{ secrets.X }}" >> .env`; valor com `#`, espaço ou `$` é truncado/interpolado pelo dotenv. Não é a causa aqui (o SMTP autentica), mas é uma armadilha ativa para qualquer rotação de senha.
- **(d) Cegueira de observabilidade** — `app/Services/PreRegistrationService.php:277-296` só registra log **na falha**; um envio aceito não deixa rastro nenhum. É o que permitiu o `plan.md` §8 da feature `20260919-cadastro-de-usuarios` afirmar que o SMTP de produção estava validado quando a única prova existente era o Mailpit local (TASK-125, TASK-287).

**Achado adjacente, outro bug, não corrigir aqui:** `app/Mail/UserInvitedMail.php:36` e `app/Http/Controllers/InvitationController.php:83` montam os links com `url()`, ou seja `APP_URL` = `https://expense-api.novemax.com.br` (domínio da API) em vez de `FRONTEND_URL` (`config/services.php:40`, que o `GoogleAuthController` já usa). Convite e recuperação de senha chegam com link morto. Vira `/novo-bug` próprio.

## 2. Correção

- **O que muda e por quê:** `deploy-backend.yml` passa a escrever `MAIL_EHLO_DOMAIN=novemax.com.br` no `.env` gerado (mesmo domínio da conta autenticada `admin@novemax.com.br`, que resolve para o IP do servidor — `nslookup novemax.com.br` inclui `162.241.203.30`). Isso preenche `local_domain` em `config/mail.php:46`, que ficava `null` e fazia o Symfony Mailer declarar HELO/EHLO com o hostname da máquina em vez de um domínio reconhecível — o gatilho do filtro de saída da HostGator, confirmado pelo Teste E (item 12).
- **Arquivos tocados:** `.github/workflows/deploy-backend.yml` (adiciona uma linha ao bloco que gera o `.env`, ~linha 51). Tentei documentar a variável em `backend/.env.example` também, mas esse arquivo está no `.gitignore` raiz e nunca foi versionado — a edição existe só localmente e não entra neste PR; registrado como achado à parte em `docs/backlog/backend-env-example-gitignored.md` (item 056).
- **Teste de regressão:** não há teste automatizado de infraestrutura de deploy no projeto (o workflow não roda em CI de PR). Validação é manual, igual aos Testes A-E já feitos: depois do deploy, `POST /api/pre-register` em produção com um e-mail Gmail real e confirmar chegada do código na caixa de entrada (não só "sem exceção").
- **Riscos / efeitos colaterais:** nenhum identificado — `MAIL_EHLO_DOMAIN` é aditivo, não sobrescreve nada existente, e não é segredo (pode ficar em texto plano no workflow, como `APP_URL` já fica). Efeito colateral **desejado**: como os três e-mails do sistema (`PreRegisterCodeMail`, `UserInvitedMail`, recuperação de senha) compartilham o mailer default, a correção destrava os três de uma vez.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-09-21 | `php artisan tinker --execute="config(['mail.mailers.smtp.local_domain' => 'novemax.com.br']); Mail::raw(...)"` no servidor de produção (Teste E) | `ok E` sem exceção **e** mensagem recebida na caixa Gmail de destino — confirma a causa raiz antes de tocar em código |
| 2026-09-21 | `git diff -- .github/workflows/deploy-backend.yml backend/.env.example` | Diff mínimo: 1 linha nova no workflow (`MAIL_EHLO_DOMAIN=novemax.com.br`), bloco de comentário + variável documentada no `.env.example` |
| 2026-09-21 | Checagem de sintaxe do YAML | Sem `actionlint`/`yaml` disponíveis neste ambiente; validação manual — indentação idêntica às linhas irmãs do mesmo bloco `echo "..." >> .env`, `gh workflow view deploy-backend.yml` reconhece o arquivo sem erro de parse |
| — (pendente) | Deploy real (`main`) + `POST /api/pre-register` em produção com e-mail Gmail real | A rodar após merge do PR — é a validação de ponta a ponta que fecha o bug (Teste E comprovou a causa, mas em sessão manual isolada, sem passar pelo `deploy-backend.yml`) |

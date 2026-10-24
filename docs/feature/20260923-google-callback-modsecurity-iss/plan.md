# Plan — Callback do login Google bloqueado pelo ModSecurity (parâmetro `iss`)

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260923

---

## 1. Não existe correção só ajustando o Socialite (specify §2.1, §2.5)

- **Decisão: descartar de vez qualquer abordagem de "mudar o escopo/parâmetro pedido ao Google"** — já testado empiricamente (specify §2.1) que o Google devolve `iss` e `scope=...openid` independente do que a aplicação pede, com conta nova e conta antiga. Não há parâmetro de request que controle o que o Google inclui na resposta. Isso fecha a porta de qualquer solução dentro de `GoogleAuthController`/`Socialite::driver('google')` que só mexa em como o redirect é gerado.

## 2. Mover o `redirect_uri` do Google para o frontend, backend troca o `code` via POST (specify §2.3)

- **Decisão técnica:** o `redirect_uri` cadastrado no Google Cloud Console passa a ser uma rota do **frontend** (`https://expense.novemax.com.br/app/auth/google/callback` ou equivalente), não mais `https://expense-api.novemax.com.br/api/auth/google/callback`. O Google continua devolvendo `iss`/`scope`/`code`/`state` na query string — isso não muda —, mas agora quem recebe é uma página estática do React, cujo servidor (mesmo host, mas outro vhost/docroot) não necessariamente aplica a mesma regra do ModSecurity que bloqueia hoje (**risco a verificar antes de codar — item 2.1 abaixo**). A página lê `code`/`state` de `window.location.search` (client-side, nunca chega a nenhum servidor como querystring de uma request nova) e manda os dois num `POST` comum para um novo endpoint do backend (ex.: `POST /api/auth/google/exchange-code`, nome final a definir em `tasks.md`), como corpo JSON normal — sem `iss`/`scope` embutido em URL, porque o backend não precisa desses dois pra nada (já ignora `iss` hoje; `scope` nunca foi lido pelo controller).
- **Por que essa abordagem e não outra:**
  - Resolve os dois intents de uma vez (`login` e `link` — specify §2.3), porque os dois passam pelo mesmo `callback()`/mesmo problema.
  - Não depende de cooperação do host (ao contrário do chamado HostGator, item 3) — fica sob controle total do repositório, mesmo padrão de raciocínio que já guiou `20260901-google-oauth-state-opaco` ("a correção é na app, não no WAF").
  - É um padrão estabelecido de OAuth pra SPA (callback pousa no client, client troca o `code` via chamada de API própria) — não é gambiarra nova.
- **Mudança de contrato:** o `redirect_uri` registrado no Google Cloud Console muda (ação manual no console, gate humano — não é código). O backend ganha uma rota nova pública (troca de `code` por sessão/JWT); a rota antiga `GET /api/auth/google/callback` deixa de ser o alvo do redirect do Google, mas o método que hoje faz `intent=link` (vínculo de conta já logada) pode precisar de tratamento equivalente — detalhar em `tasks.md`.
- **Escopo do Socialite ao trocar o `code`:** o `redirect_uri` usado na troca do `code` pelo token (chamada servidor-a-servidor pro Google) **precisa ser idêntico** ao usado na hora de gerar a URL de consentimento (exigência do OAuth2 RFC 6749 §4.1.3) — ou seja, o backend precisa configurar o Socialite pra usar o `redirect_uri` do frontend nessa troca, não o `GOOGLE_REDIRECT_URI` atual (`config/services.php:37`). Ajuste de config, não de arquitetura.

### 2.1 Risco a verificar antes de implementar

Não está confirmado que `expense.novemax.com.br` (frontend) está fora do alcance do mesmo ModSecurity que bloqueia `expense-api.novemax.com.br` — os dois domínios são a mesma conta HostGator compartilhada (`isacag00`), e regras de WAF em hospedagem compartilhada costumam ser aplicadas no nível da conta/servidor, não por vhost. **Antes de codar a task de frontend**, fazer um teste rápido e barato: montar manualmente uma URL de `expense.novemax.com.br/app/...` com uma query string parecida (`?iss=https%3A%2F%2Fexemplo.com&scope=...`) e confirmar que não recebe o mesmo "Not Acceptable" — mesmo método usado nesta investigação (`specify.md` §2.5). Se o mesmo bloqueio acontecer lá também, essa abordagem não resolve sozinha e o plano precisa voltar pra discussão (opção seria um subdomínio/serviço fora da HostGator só pra esse redirect, o que é mudança de arquitetura maior — fora do escopo atual até essa hipótese ser descartada).

## 3. Chamado HostGator é complementar, não bloqueia a correção de código (specify §2.2, §2.4)

- **Decisão:** o chamado (`docs/feature/20260923-google-callback-modsecurity-iss/chamado-hostgator.md`) é enviado em paralelo, mas **nenhuma task de código depende da resposta dele**. Mesma lição já registrada em `20260901-google-oauth-state-opaco`: o host não expõe gerência do ModSecurity no painel, então esperar suporte responder (ou aceitar o pedido) não é um plano confiável sozinho. Se o chamado for aceito e resolver o bloqueio na rota antiga da API, ótimo — daria a opção de manter `redirect_uri` na API sem a mudança do item 2. Mas a feature não fica bloqueada esperando isso.

## N. Ordem de execução

Dependência real: o item 2.1 (verificar se `expense.novemax.com.br` também é bloqueado) precisa rodar **antes** de qualquer código do item 2 — é um spike de ~5 minutos, mesmo método já usado no `specify.md`, e decide se a abordagem do item 2 é viável como está ou precisa de plano B. O item 3 (chamado HostGator) não tem dependência com nada — já foi enviado/está pronto, roda em paralelo ao resto. Ordem sugerida pra `tasks.md`: (1) spike de verificação do item 2.1, (2) mudança de config do Google Cloud Console (`redirect_uri`), (3) rota nova do frontend, (4) rota/endpoint novo do backend pra trocar o `code`, (5) testes de regressão dos dois intents (`login` e `link`).

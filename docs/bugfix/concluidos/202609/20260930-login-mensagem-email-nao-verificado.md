# Bugfix — Login exibe mensagem genérica em vez de orientar verificação de e-mail

Versão: 1.0 · Criado em: 20260930 · Branch: `fix/20260930-login-mensagem-email-nao-verificado`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [ ] **Auth / autorização / dado sensível** — a correção é só de exibição no frontend; não toca rota, controller nem middleware de auth. A regra de negócio (bloquear login sem e-mail verificado) já existe e está correta no backend (`docs/feature/20260922-email-verificado-obrigatorio`, já em produção).
- [ ] **Migration ou contrato de API** — nenhum schema, rota, payload ou status code muda. O backend já devolve `403` com `{"error": "..."}`; só o frontend passa a ler esse corpo.
- [ ] **Causa raiz obscura / correção ampla** — causa raiz confirmada em 1 arquivo (`frontend/src/pages/LoginPage.tsx:53-59`).
- [ ] **Decisão de produto/arquitetura** — a mensagem correta já foi decidida na feature `email-verificado-obrigatorio` (`plan.md` §2: orientar "Esqueci minha senha"); aqui só falta exibi-la.

Nenhuma marcada → segue no BFF.

## 1. Problema

- **Sintoma:** usuário com conta antiga (`email_verified_at` nulo) tenta logar com e-mail/senha corretos em produção e recebe a mensagem genérica "Não foi possível fazer login. Tente novamente em instantes." — sem nenhuma orientação de como resolver. Retentar não adianta (o bloqueio é intencional, não transitório), então o usuário fica sem caminho de saída percebido.
- **Reprodução:** 1) abrir `https://expense.novemax.com.br/app/` (tela de login); 2) logar com uma conta cujo `email_verified_at` é nulo (ex. conta criada antes da feature `email-verificado-obrigatorio`, PR #192); 3) `POST /api/login` responde `403` com `{"error": "E-mail não verificado. Use \"Esqueci minha senha\" para confirmar seu e-mail e definir uma nova senha."}` (`backend/app/Http/Controllers/AuthController.php:36-43`); 4) a tela mostra "Não foi possível fazer login. Tente novamente em instantes." em vez da mensagem do backend. Reproduzido em produção em 2026-09-30 (rede: `POST https://expense-api.novemax.com.br/api/login` → `403`).
- **Esperado vs. atual:** esperado — a tela exibe a mensagem específica do backend, orientando o uso de "Esqueci minha senha". Atual — `frontend/src/pages/LoginPage.tsx:53-59` só trata os status `401`/`422` com mensagem própria ("E-mail ou senha inválidos."); qualquer outro status (incluindo o `403` de e-mail não verificado) cai no `else` genérico e o corpo da resposta (`res.json()`) nunca é lido nesse branch.
- **Causa raiz:** [`frontend/src/pages/LoginPage.tsx:53-59`](../../../frontend/src/pages/LoginPage.tsx#L53-L59) — `handleSubmit` decide a mensagem de erro só pelo `res.status`, sem ler `await res.json()` para o caso de erro. O backend já manda a mensagem certa (`AuthController.php:41`, decidida em `docs/feature/20260922-email-verificado-obrigatorio/plan.md` §2), mas ela nunca chega à tela.

## 2. Correção

- **O que muda e por quê:** em `handleSubmit` (`LoginPage.tsx`), no branch `!res.ok`, ler o corpo da resposta (`await res.json()`) e usar `data.error` quando presente; manter "E-mail ou senha inválidos." para `401`/`422` (não expor a mensagem crua do backend nesses casos, que já é tratada com uma mensagem amigável própria) e usar a mensagem genérica só como fallback final se o corpo não vier no formato esperado (ex. erro de rede/servidor sem JSON).
- **Arquivos tocados:** `frontend/src/pages/LoginPage.tsx`.
- **Teste de regressão:** novo caso em `frontend/src/pages/LoginPage.test.tsx` — mock de `fetch` retornando `403` com `{"error": "E-mail não verificado. ..."}` e asserção de que a tela exibe essa mensagem (não a genérica).
- **Riscos / efeitos colaterais:** nenhum identificado — o `401`/`422` continuam com a mensagem amigável atual; só o caminho "outro status com corpo JSON com `error`" passa a exibir a mensagem do backend.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-09-30 | `cd frontend && npx vitest run src/pages/LoginPage.test.tsx` | 10 testes passaram (2 novos: mensagem do backend em 403 de e-mail não verificado; fallback genérico quando o corpo não é JSON) |
| 2026-09-30 | `cd frontend && npx tsc --noEmit` | sem erros |
| 2026-09-30 | `cd frontend && npx vitest run` (suíte completa) | 43 arquivos / 291 testes passaram, sem regressão |
| 2026-09-30 | Verificação manual no navegador (`npm run dev`, `fetch` da rota `/api/login` mockado para devolver `403` com o corpo real do backend) | Tela exibe "E-mail não verificado. Use \"Esqueci minha senha\" para confirmar seu e-mail e definir uma nova senha." em vez da mensagem genérica |

## Resolução
Concluído em: 2026-09-30
Branch: fix/20260930-login-mensagem-email-nao-verificado
PR: https://github.com/isacaguiar/expense/pull/201

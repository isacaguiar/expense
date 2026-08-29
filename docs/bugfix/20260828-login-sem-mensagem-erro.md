# Bugfix — Tela de login não exibe mensagem de erro com credenciais inválidas

Versão: 1.0 · Criado em: 20260828 · Branch: `fix/20260828-login-sem-mensagem-erro`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**

- [ ] **Auth / autorização / dado sensível** — rotas/controllers/middleware de auth, Pix, grupos, despesas, usuários.
- [ ] **Migration ou contrato de API** — altera schema do banco (mesmo aditivo) ou muda resposta/rota/status/payload que `frontend`/`app` consomem.
- [ ] **Causa raiz obscura / correção ampla** — causa não clara após timebox, ou > ~3 arquivos / vários módulos.
- [ ] **Decisão de produto/arquitetura** — depende de comportamento novo, ou contradiz a Constitution.

Nenhuma marcada → segue no BFF.

**Justificativa da Triagem:** a correção é frontend puro (`LoginPage.tsx` + `LoginFormCard.tsx`) — trata a resposta de erro que a rota `POST /api/login` já devolve hoje; não toca rota/controller/middleware de auth do backend, não muda o contrato da API, não faz migration. Causa raiz já identificada (abaixo), ~2 arquivos. Exibir mensagem de erro em login é comportamento esperado padrão, não decisão de produto nova.

## 1. Problema

- **Sintoma:** ao enviar o formulário de login com e-mail/senha inválidos — ou com os campos em branco — a tela não muda e nenhuma mensagem de erro aparece. O usuário não tem feedback de que o login falhou.
- **Reprodução:**
  1. Abrir a tela de login.
  2. Digitar um e-mail/senha que não existem (ou deixar ambos os campos vazios).
  3. Clicar em "Entrar".
  - Resultado: nada visível acontece; apenas um `console.error('Falha no login:', ...)` no DevTools.
- **Esperado vs. atual:**
  - Esperado: uma mensagem visível no card de login (ex.: "E-mail ou senha inválidos").
  - Atual: nenhum feedback na UI; o formulário fica igual.
- **Causa raiz:**
  - `frontend/src/pages/LoginPage.tsx:31-33` — o `catch` do `handleSubmit` só faz `console.error('Falha no login:', err)`. Não há `useState` para erro nem qualquer renderização de mensagem; quando `res.ok` é `false` (401/422 do backend), o `throw` cai nesse `catch` silencioso.
  - `frontend/src/pages/login/LoginFormCard.tsx` — o componente do formulário não recebe prop de erro nem renderiza nenhum `<Alert>`/helper text; mesmo que `LoginPage` capturasse o erro em estado, não haveria onde exibi-lo.
  - `frontend/src/pages/login/LoginFormCard.tsx:62` — o `<Box component="form">` tem `noValidate`, então o submit com campos `required` vazios não dispara nem a validação nativa do browser.

## 2. Correção

- **O que muda e por quê:**
  - `LoginPage.tsx` ganha um estado `error` (`useState<string | null>`). `handleSubmit` limpa o erro no início; quando `res.ok` é `false`, define a mensagem (`401`/`422` → "E-mail ou senha inválidos."; outros status → "Não foi possível fazer login. Tente novamente em instantes.") e retorna em vez de lançar exceção silenciosa; o `catch` mantém o `console.error` e ainda define uma mensagem de falha de conexão. O backend (`AuthController::login`) responde `401 {"error":"Não autorizado"}` para credenciais inválidas e para campos vazios (não há `validate()`), então o ramo `401` cobre os dois casos do sintoma.
  - `LoginFormCard.tsx` recebe uma prop opcional `error?: string | null` e renderiza um `<Alert severity="error">` no topo do formulário quando ela existe.
- **Arquivos tocados:** `frontend/src/pages/LoginPage.tsx`, `frontend/src/pages/login/LoginFormCard.tsx`, `frontend/src/pages/LoginPage.test.tsx`.
- **Teste de regressão:** `LoginPage.test.tsx` — 2 casos novos: (a) `fetch` resolvendo `{ ok: false, status: 401 }` faz aparecer "E-mail ou senha inválidos."; (b) `fetch` rejeitando faz aparecer a mensagem de falha de conexão. Ambos falham sem a correção (nenhuma mensagem era renderizada).
- **Riscos / efeitos colaterais:** baixo — mudança isolada na tela de login, sem alteração de contrato de API nem do fluxo de sucesso (token + `navigate('/meus-grupos')` intactos). `noValidate` no `<form>` foi mantido de propósito: a validação de campo vazio agora chega ao usuário via a mesma mensagem de erro do backend.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-08-28 | `cd frontend && npx tsc --noEmit` | sem erros |
| 2026-08-28 | `cd frontend && npx vitest run src/pages/LoginPage.test.tsx` | 6/6 verde (2 novos) |
| 2026-08-28 | `cd frontend && npx vitest run` | 26 arquivos, 174/174 verde |
| 2026-08-28 | `cd frontend && npx vite build` | build OK em 11.35s (warning de chunk >500 kB é pré-existente) |

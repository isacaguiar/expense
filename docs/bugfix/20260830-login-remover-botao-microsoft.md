# Bugfix — Botão "Microsoft" morto na tela de login

Versão: 1.0 · Criado em: 20260830 · Branch: `fix/20260830-login-remover-botao-microsoft`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**

- [ ] **Auth / autorização / dado sensível** — rotas/controllers/middleware de auth, Pix, grupos, despesas, usuários.
- [ ] **Migration ou contrato de API** — altera schema do banco (mesmo aditivo) ou muda resposta/rota/status/payload que `frontend`/`app` consomem.
- [ ] **Causa raiz obscura / correção ampla** — causa não clara após timebox, ou > ~3 arquivos / vários módulos.
- [ ] **Decisão de produto/arquitetura** — depende de comportamento novo, ou contradiz a Constitution.

Nenhuma marcada → segue no BFF.

**Justificativa da Triagem:**

- *Auth / dado sensível* — a mudança é 100% em `frontend/src/pages/login/`: remove um `<Button href="#">` sem `onClick` e o import do ícone. Não toca rota, controller ou middleware de autenticação; não altera o fluxo de login por e-mail/senha nem o OAuth do Google (backend/`GoogleAuthController`, usado hoje só pelo vínculo de conta em `Profile.tsx`). O gatilho do agent `security-reviewer` é `routes/api.php` / `Http/Controllers` / `Http/Middleware` — nada disso aqui.
- *Migration / contrato de API* — não. Nenhum schema, nenhuma resposta/rota/payload. O `app/` (Expo) tem UI de login própria e não consome `frontend/`.
- *Causa raiz obscura / correção ampla* — causa clara (botão placeholder, zero integração Microsoft no repo). Escopo: `LoginFormCard.tsx` + `LoginPage.test.tsx` + o asset `microsoft-logo.svg` órfão. 3 arquivos, um módulo.
- *Decisão de produto/arquitetura* — remover uma opção que nunca funcionou, a pedido explícito do dono. Não depende de escolher comportamento novo, não contradiz a Constitution.

Nenhuma marcada → segue no BFF.

## 1. Problema

- **Sintoma:** a tela de login (`https://expense.novemax.com.br/app`) exibe, abaixo de "ou continue com", dois botões: "Google" e "Microsoft". O botão "Microsoft" não faz nada ao ser clicado.
- **Reprodução:** abrir a tela de login → clicar em "Microsoft" → nada acontece (é `href="#"`, sem `onClick`).
- **Esperado vs. atual:**
  - Esperado: só opções de login que existem de fato aparecem na tela.
  - Atual: "Microsoft" aparece como se fosse clicável, mas não há **nenhuma** integração Microsoft/MSAL/Azure no projeto — nem no `frontend/` nem no `backend/` (`grep -rniE "microsoft|msal|azure" backend/app backend/routes backend/config` → 0 resultados; no `frontend/`, só o import do SVG e o markup). O feature `docs/feature/20260821-login-social-google/specify.md` §1/§3 deixou o botão Microsoft explicitamente fora de escopo, "como está".
- **Causa raiz:** `frontend/src/pages/login/LoginFormCard.tsx` — o `<Box sx={{ display: 'flex', gap: 2 }}>` do bloco social renderiza dois `<Button href="#">`; o segundo (`Microsoft`, com `startIcon={microsoftLogo}`) nunca teve integração. Import órfão de intenção em `LoginFormCard.tsx:19` (`import microsoftLogo from '../../assets/illustrations/microsoft-logo.svg'`). Teste `frontend/src/pages/LoginPage.test.tsx:127` firma o placeholder (`href` `#`).

## 2. Correção

- **O que muda e por quê:** remover o `<Button ...>Microsoft</Button>` do bloco social em `LoginFormCard.tsx`, o `import microsoftLogo`, e o asset `frontend/src/assets/illustrations/microsoft-logo.svg` (fica órfão). O botão "Google" permanece — sozinho no `<Box display:flex>`, ocupa a largura toda (`fullWidth` já está setado). Tira da tela uma opção de login inexistente; não adiciona nada.
- **Arquivos tocados:** `frontend/src/pages/login/LoginFormCard.tsx`, `frontend/src/pages/LoginPage.test.tsx`, `frontend/src/assets/illustrations/microsoft-logo.svg` (removido).
- **Teste de regressão:** `LoginPage.test.tsx` — no caso "renders the social login placeholders and the footer links", remover a asserção do link "Microsoft" e adicionar `expect(screen.queryByRole('link', { name: /Microsoft/ })).not.toBeInTheDocument()`. A asserção do "Google" continua. Roda verde com a correção, vermelho sem (o `getByRole('link', { name: /Microsoft/ })` passaria a lançar).
- **Riscos / efeitos colaterais:** baixo — elemento puramente visual e sem função. **Nota (não é regressão desta mudança):** o botão "Google" que fica também é `href="#"` hoje — a tela de login ainda não tem login social funcional; `docs/feature/20260821-login-social-google/` §2.6 é o trabalho pendente que troca esse `href="#"` por um redirect real. O backend já tem o OAuth do Google (`GoogleAuthController`), usado por enquanto só no vínculo de conta em `Profile.tsx`.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-08-30 | `cd frontend && npx tsc --noEmit` | sem erros |
| 2026-08-30 | `cd frontend && npx vitest run src/pages/LoginPage.test.tsx` | 6 passed (6) |
| 2026-08-30 | `cd frontend && npx vite build` | built ok — 1063 módulos (era 1064); warning de chunk >500kB pré-existente |

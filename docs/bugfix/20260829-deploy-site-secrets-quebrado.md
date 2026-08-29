# Bugfix — deploy-site.yml falha por nomes de secret inexistentes

Versão: 1.0 · Criado em: 20260829 · Branch: `fix/20260829-deploy-site-secrets-quebrado`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**

- [ ] **Auth / autorização / dado sensível** — rotas/controllers/middleware de auth, Pix, grupos, despesas, usuários.
- [ ] **Migration ou contrato de API** — altera schema do banco (mesmo aditivo) ou muda resposta/rota/status/payload que `frontend`/`app` consomem.
- [ ] **Causa raiz obscura / correção ampla** — causa não clara após timebox, ou > ~3 arquivos / vários módulos.
- [ ] **Decisão de produto/arquitetura** — depende de comportamento novo, ou contradiz a Constitution.

Nenhuma marcada → segue no BFF.

**Justificativa da Triagem:** correção em 1 arquivo (`.github/workflows/deploy-site.yml`), causa raiz conhecida. Não toca código de auth/autorização, não rotaciona/expõe/remove segredo (os secrets `SFTP_HOST/USER/PASS` já existem — só passa a referenciar os nomes certos). Não muda schema nem contrato de API. O escopo aqui é **parar a falha**; definir para onde o site institucional realmente publica (`server-dir`, o que subir) fica de fora (§2, "Fora de escopo").

## 1. Problema

- **Sintoma:** o workflow `Deploy para Hostgator` (`.github/workflows/deploy-site.yml`) falha em toda execução com:
  ```
  Error: Input required and not supplied: username
  ```
  E emite o aviso "Node 20 is being deprecated" (action antiga).
- **Reprodução:** qualquer `push` para `main` ou qualquer `pull_request` contra `main` dispara o workflow, que falha no passo "Deploy via FTP para Hostgator". Confirmado na run do merge #88.
- **Esperado vs. atual:**
  - Esperado: o workflow não falha por configuração; e não roda em PR (deploy não deve acontecer antes do merge).
  - Atual: falha 100% das vezes; roda em PR e em push para `main`.
- **Causa raiz:** `.github/workflows/deploy-site.yml`
  - linha 28-29: `username: ${{ secrets.SFTP_USERNAME }}` / `password: ${{ secrets.SFTP_PASSWORD }}` — esses secrets **não existem** no repositório. Os que existem (e que `deploy-backend.yml`/`deploy-frontend.yml` usam) são `SFTP_USER` e `SFTP_PASS`. Secret ausente resolve para string vazia → a `FTP-Deploy-Action` aborta com "Input required and not supplied: username".
  - linha 25: `SamKirkland/FTP-Deploy-Action@4.3.0` — versão antiga, runtime Node 20 (origem do aviso de deprecação). `deploy-backend.yml`/`deploy-frontend.yml` usam `@v4.3.5`.
  - linha 4-6: trigger `pull_request` para `main` — faz o job (um deploy) rodar em todo PR, não só no merge.

## 2. Correção

- **O que muda e por quê:**
  - `secrets.SFTP_USERNAME` → `secrets.SFTP_USER`; `secrets.SFTP_PASSWORD` → `secrets.SFTP_PASS` (nomes que existem de fato, alinhado com os outros dois workflows de deploy).
  - `SamKirkland/FTP-Deploy-Action@4.3.0` → `@v4.3.5` (mesma versão dos outros deploys; remove o aviso de Node 20 deprecado).
  - Remover o bloco `pull_request:` do `on:` e acrescentar `workflow_dispatch:` — o workflow passa a rodar só em `push` para `main` (+ re-disparo manual), igual `deploy-backend.yml`/`deploy-frontend.yml`.
- **Arquivos tocados:** `.github/workflows/deploy-site.yml`.
- **Teste de regressão:** sem teste automatizado (workflow de CI/CD; não há harness). Verificação: YAML válido (parse), `git diff` restrito às linhas acima, e a próxima execução do workflow em `main` não falha mais no passo de FTP por input ausente. O sucesso *funcional* do deploy (arquivos chegando ao destino certo) depende de `server-dir`/`local-dir`, que estão fora do escopo — ver abaixo.
- **Riscos / efeitos colaterais:** baixo — não altera credenciais nem o que já funciona (`deploy-backend`/`deploy-frontend` intactos). O `deploy-site.yml` continua com `local-dir: ./` e `server-dir` comentado: depois desta correção ele deixa de *falhar*, mas ainda **não publica o site institucional num destino definido** — isso é trabalho à parte (decisão do dono sobre pasta de destino do site), fora do escopo deste bugfix.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-08-29 | `git diff .github/workflows/deploy-site.yml` | só as linhas de `on:`, versão da action e nomes de secret alteradas |
| 2026-08-29 | parse YAML (node: sem tabs, 41 linhas, estrutura íntegra) | OK |

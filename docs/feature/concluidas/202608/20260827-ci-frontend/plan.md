# Plan — CI do frontend (type-check, testes, build)

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260827

---

## 1. Workflow `.github/workflows/ci-frontend.yml` (specify §2.1)

- Novo arquivo, mesmo diretório de `deploy-backend.yml` (único workflow existente hoje).
- `on: pull_request: branches: [dev, main]` — cobre PR de feature contra `dev` e o PR de promoção `dev` → `main` (`docs/sdd/04-implementation.md` §1, itens 6 e 8).
- Um job (`build-and-test`, `runs-on: ubuntu-latest`), passos:
  1. `actions/checkout@v4`.
  2. `actions/setup-node@v4` com `node-version: '20'` (LTS ativa; projeto não tem `.nvmrc`/`engines` hoje — 20 é uma escolha explícita, documentada aqui) e `cache: 'npm'` + `cache-dependency-path: frontend/package-lock.json` (cache nativo do `setup-node`, sem precisar de um passo `actions/cache` manual — mais simples e já cobre o objetivo de não reinstalar do zero sem adicionar complexidade extra).
  3. `npm ci` com `working-directory: frontend` (instalação determinística — mesma lógica de `composer install` em `deploy-backend.yml`).
  4. `npx tsc --noEmit` (`working-directory: frontend`).
  5. `npx vitest run` (`working-directory: frontend`).
  6. `npm run build` (`working-directory: frontend`).
- Por quê essa abordagem e não outra: um job único e sequencial (não uma matriz nem jobs paralelos) porque os 3 comandos de verificação são rápidos o suficiente hoje (suíte de testes na casa de 1-2 min) e já rodam nessa mesma ordem no checklist manual de `04-implementation.md` — replicar exatamente a mesma sequência evita qualquer divergência entre "passou local" e "passou no CI".

## N. Ordem de execução

Item único — todo o workflow cabe num arquivo YAML, uma task só.

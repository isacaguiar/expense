# Specify — Storage sessions/views versionados

> Feature: untrack os arquivos de sessão e view compilada do Laravel que ainda estão versionados no repositório (`git rm --cached`, sem tocar em `.gitignore`, que já está correto). Promovida do item 030 do backlog (`docs/backlog/storage-sessions-views-compilados-versionados-backend.md`), achado ao investigar a promoção dos itens 005/007 (já resolvidos fora do fluxo SDD).

Versão: 1.0 · Criado em: 20260821

---

## 1. Problema

`git ls-files backend/storage` mostra 11 arquivos gerados em runtime ainda rastreados pelo git:

- `backend/storage/framework/sessions/` — 6 arquivos de sessão (nomes tipo `WAkMcu6FxJn7GsPlUTQrriQg4P8FpcHNmf3bQdV3`).
- `backend/storage/framework/views/` — 5 arquivos `.php` de view Blade compilada (nomes com hash, ex.: `17c94453379f9c9cc825f79970b58c8f.php`).

As regras de `.gitignore` aninhadas dessas pastas (`backend/storage/framework/sessions/.gitignore`, `backend/storage/framework/views/.gitignore`) já têm o padrão correto do Laravel (`*` / `!.gitignore`) — confirmado por leitura direta dos dois arquivos. O problema não é falta de regra; é que esses 11 arquivos específicos foram commitados antes da regra existir (ou via `git add -f`), e nunca passaram por `git rm --cached`.

Mesma categoria de achado dos itens 005 (`node_modules`) e 007 (cache/logs do Laravel), ambos já corrigidos fora do fluxo SDD por commits avulsos (`477d27665`, `ec81e9cfd`, já em `dev`).

## 2. Requisitos

### 2.1 Untrack dos 11 arquivos

`git rm --cached` (sem `--force`, sem `-r` recursivo em outras pastas) exatamente nos 11 arquivos listados acima — os arquivos continuam existindo em disco (sessão ativa, cache de view compilada), só saem do índice do git. Depois do commit, `git status` deve ficar limpo em relação a essas pastas mesmo com o backend rodando (`.gitignore` aninhado já impede que voltem).

## 3. Fora de escopo desta feature

- Alterar qualquer `.gitignore` — já estão corretos, confirmado no problema acima.
- Varredura exaustiva por outros arquivos gerados versionados em locais não mencionados nos itens 005/007/030 (ex.: `backend/bootstrap/cache/*.php`) — se aparecer, vira um novo item de backlog.

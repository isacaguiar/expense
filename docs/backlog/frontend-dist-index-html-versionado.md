# `frontend/dist/index.html` versionado apesar de `dist/` no `.gitignore`

ID: 037
Origem: docs/bugfix/concluidos/202609/20260901-frontend-meta-viewport-mobile.md §2 (achado ao rodar `npm run build` durante a correção)
Criado em: 2026-09-01
Prioridade: BAIXA
Status: Aberto

## Descrição

`frontend/.gitignore` ignora `dist/`, mas há exatamente um arquivo desse diretório
rastreado no repositório: `frontend/dist/index.html` (últimos commits a tocá-lo:
`bf31d9a9b`, `feccd2e09` — mensagens genéricas, anteriores ao fluxo SDD). Qualquer
`vite build` local suja o `git status` com esse arquivo. A correção é
`git rm --cached frontend/dist/index.html` e um commit — mas convém antes confirmar que
nenhum passo de deploy/serve depende desse arquivo commitado (há as features
`20260829-deploy-frontend` e `20260829-deploy-topologia-unificada`).

## Por que importa

Artefato de build versionado gera ruído em diffs, risco de commit acidental de `dist/`
desatualizado e confusão sobre qual é a fonte de verdade. Mesmo padrão dos itens de backlog
já concluídos 005 (`node_modules` versionado) e 007/030 (cache/sessões/views do Laravel
versionados).

Tipo sugerido: infra

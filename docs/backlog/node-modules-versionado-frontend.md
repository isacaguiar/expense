# node_modules do frontend versionado no repositório

ID: 005
Origem: docs/feature/20260817-config-url-api-frontend/implementation.md §1
Criado em: 2026-08-17
Prioridade: BAIXA
Status: Aberto

## Descrição

`frontend/node_modules` está rastreado pelo git, apesar de `frontend/.gitignore` listar `node_modules/`. Um commit anterior (`5dab192c chore: remove frontend/node_modules do controle de versão`) já removeu esses arquivos do controle de versão no passado, mas um commit posterior (`updates`) voltou a adicioná-los. Rodar `npm install`/`npm run dev` localmente gera diffs grandes de ruído (`.vite/deps/*`, binários) que precisam ser revertidos manualmente antes de cada commit.

## Por que importa

Não bloqueia nenhuma task, mas infla o histórico do repositório, aumenta o tempo de clone e cria risco de alguém commitar acidentalmente uma alteração de build gerada localmente (como aconteceu durante a TASK-027, revertido antes do commit).

Tipo sugerido: infra

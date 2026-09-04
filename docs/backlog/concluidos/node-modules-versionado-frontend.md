# node_modules do frontend versionado no repositório

ID: 005
Origem: docs/feature/concluidas/202608/20260817-config-url-api-frontend/implementation.md §1
Criado em: 2026-08-17
Prioridade: BAIXA
Status: Resolvido (fora do fluxo SDD)

## Descrição

`frontend/node_modules` está rastreado pelo git, apesar de `frontend/.gitignore` listar `node_modules/`. Um commit anterior (`5dab192c chore: remove frontend/node_modules do controle de versão`) já removeu esses arquivos do controle de versão no passado, mas um commit posterior (`updates`) voltou a adicioná-los. Rodar `npm install`/`npm run dev` localmente gera diffs grandes de ruído (`.vite/deps/*`, binários) que precisam ser revertidos manualmente antes de cada commit.

## Por que importa

Não bloqueia nenhuma task, mas infla o histórico do repositório, aumenta o tempo de clone e cria risco de alguém commitar acidentalmente uma alteração de build gerada localmente (como aconteceu durante a TASK-027, revertido antes do commit).

Tipo sugerido: infra

## Resolução

Concluído em: 2026-08-21 (constatado ao verificar o estado atual antes de promover este item — a correção em si é anterior e não passou pelo fluxo SDD)
Commit: `477d27665 chore: remove node_modules e vendor do controle de versão` (já em `dev`)
Verificação: `git ls-files | grep node_modules` não retorna nenhum arquivo; `frontend/.gitignore` já lista `node_modules/`.

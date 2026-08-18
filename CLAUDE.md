# CLAUDE.md

Projeto: **Controle de Despesas Compartilhadas** (grupos, despesas, parcelas, cobrança via Pix). Backend Laravel (`backend/`) + frontend web React (`frontend/`) + futuro app Expo/React Native (`app/`, em migração).

Este projeto usa Spec-Driven Development (SDD) na pasta `docs/sdd/`.

## Regra obrigatória

Antes de executar QUALQUER solicitação de novo desenvolvimento de front ou backend, leia primeiro `docs/sdd/README.md` — mapa de leitura e ordem do SDD.

Ordem do SDD (cada camada não pode contradizer a anterior):

```
docs/sdd/00-constitution.md   → regras que nada pode contradizer
docs/sdd/01-specify.md        → o que o sistema faz hoje (fonte: código real, baseline do sistema todo)
docs/feature/<AAAAMMDD>-<slug>/specify.md, plan.md, tasks.md, implementation.md
                               → toda feature/épico técnico novo, a partir de 2026-08-17
docs/sdd/03-tasks.md          → épicos antigos ainda não migrados (não recebe tasks novas)
docs/sdd/05-context-frontend.md → contexto de execução para tarefas de frontend
docs/sdd/06-context-backend.md  → contexto de execução para tarefas de backend
```

`docs/sdd/02-plan.md` e `docs/sdd/04-implementation.md` também pararam de crescer nessa data — mesma lógica: engenharia e log de execução agora vivem dentro de cada `docs/feature/<AAAAMMDD>-<slug>/`.

Padrão de trabalho:

1. Identifique se a tarefa é sobre entender o negócio (`01-specify.md`), uma feature/épico que já tem pasta em `docs/feature/` (leia `specify.md`→`plan.md`→`tasks.md` de lá), ou algo ainda só listado em `03-tasks.md` (épico antigo não migrado).
2. Toda mudança de código deve ser rastreável até uma task concreta. Se não existe nem como task antiga nem como feature, siga "Quando não houver task aplicável" abaixo antes de codar — inclusive antes de criar a pasta em `docs/feature/`.
3. Ao começar a trabalhar num épico antigo de `03-tasks.md` que ainda não foi migrado, crie `docs/feature/<AAAAMMDD>-<slug>/` para ele primeiro (specify/plan/tasks puxando o conteúdo relevante) e deixe só um ponteiro em `03-tasks.md`, como já feito para o Épico B (`docs/feature/20260817-seguranca-api/`). O slash command `/nova-feature <slug-curto>` automatiza a criação da pasta a partir de `docs/sdd/templates/`.
4. Para codar, use a skill correspondente ao domínio — `expense-backend` ou `expense-frontend` — que carrega automaticamente o contexto certo (`06-context-backend.md` ou `05-context-frontend.md`: convenções, arquivos de referência e gates human-in-the-loop daquela frente). Não é preciso invocar isso manualmente fora das skills. Para revisão de segurança de mudanças em rotas/controllers/middleware de auth, ou para conferir o checklist pré-PR, use os agents `security-reviewer` e `pr-readiness-checker` (`.claude/agents/`, catálogo em `docs/sdd/agents-roadmap.md`).
5. Siga o fluxo de execução descrito em `docs/sdd/04-implementation.md` (branch principal por feature + sub-branch por task mergeada nela sem PR, checklist antes de integrar e antes do PR único da feature contra `dev`, com os comandos reais de validação — ver `ADR-003-fluxo-branch-por-feature.md`) e registre o log em `docs/feature/<AAAAMMDD>-<slug>/implementation.md`, citando comando executado + resultado.
6. Nunca contradiga `00-constitution.md`. Se a task exigir isso, pare e sinalize — editar a Constitution é sempre gate humano. Mudança de stack/arquitetura vira ADR em `docs/sdd/decisions/`.
7. Achado que não bloqueia nenhuma task da feature atual (débito técnico tangencial, ideia de melhoria) não vira task nem fica solto só na conversa — vira um arquivo em `docs/backlog/` (ver `docs/sdd/README.md` §"Backlog de ideias não bloqueantes"), pra virar `TASK-0xx` só quando alguém decidir executá-lo de fato. Quando essa decisão acontecer, use `/promover-backlog <ID>` — não crie a feature manualmente para um item de backlog.
8. Avance somente se o gate human-in-the-loop da etapa estiver aprovado (ver tabela abaixo).
9. Finalize com relatório curto: task/objetivo, arquivos consultados/criados/modificados, validações rodadas (pint/phpunit/tsc), gates pendentes de aprovação humana, próximas ações recomendadas.

## Gates human-in-the-loop (resumo — tabela completa em `00-constitution.md` §5.2)

| Autônomo (IA/dev em branch) | Exige aprovação humana explícita |
|---|---|
| Redigir/atualizar Specify, Plan, Tasks | Editar a Constitution |
| Codar, testar, migrar localmente/em branch | Migration em ambiente compartilhado ou produção |
| Migration aditiva local | Migration destrutiva (drop/rename/alterar tipo) em qualquer ambiente além do local |
| Abrir Pull Request | Merge em `main` |
| — | Deploy (`deploy-backend.yml`, EAS build do Expo) |
| — | Rotacionar, expor ou remover segredo/credencial |
| — | Apagar dado definitivamente (hard delete) |
| — | Corte de produção do app novo (`expense/app`) substituindo `expense/frontend` |

## Regras que nunca devem ser ignoradas

- Toda rota que expõe dado financeiro ou pessoal deve estar dentro do middleware `jwt.auth` e o controller deve checar que o usuário autenticado tem relação com o recurso (é membro do grupo, é o dono do dado) antes de retornar/alterar — ver achados já conhecidos em `00-constitution.md` §5.3 (`GET /pix/generate` sem autenticação; `GroupController@show/update/destroy` sem checagem de membership) antes de mexer em Pix ou em grupos.
- Nunca commitar segredo em texto puro (senha, client secret, API key, token). Há violação conhecida no `README.md` raiz e em `client_secret_*.json` — tratar como dívida prioritária, não repetir em código novo. Rotação/remoção de segredo é sempre gate humano.
- Exclusão de registros de negócio (grupo, despesa) é sempre soft delete (coluna `deleted`); nunca `DELETE` físico sem o gate humano de hard delete.
- Não registrar rota (`apiResource` ou manual) sem que todos os métodos referenciados existam e tenham teste mínimo — hoje há métodos ausentes registrados em rota (ver `00-constitution.md` §2.4), não repetir esse padrão.
- Controllers "stub" sem implementação ficam fora de `routes/api.php` até terem conteúdo.

## Quando não houver task aplicável

Se o pedido não se encaixa em nenhuma task existente em `docs/sdd/03-tasks.md` nem em nenhuma feature já criada em `docs/feature/`, pare e pergunte ao usuário antes de agir — não improvise fora da estrutura do SDD nem crie uma pasta nova em `docs/feature/` sem confirmar antes (nome/slug da feature, escopo).

# CLAUDE.md

Projeto: **Controle de Despesas Compartilhadas** (grupos, despesas, parcelas, cobrança via Pix). Backend Laravel (`backend/`) + frontend web React (`frontend/`) + futuro app Expo/React Native (`app/`, em migração).

Este projeto usa Spec-Driven Development (SDD) na pasta `docs/sdd/`.

## Regra obrigatória

Antes de executar QUALQUER solicitação de novo desenvolvimento de front ou backend, leia primeiro `docs/sdd/README.md` — mapa de leitura e ordem do SDD.

- Ordem de leitura, fluxo e fluxos paralelos (feature / backlog / bugfix): `docs/sdd/README.md`.
- Regra normativa das 5 fases: `docs/sdd/00-constitution.md` §5.1.
- Por que `02`/`03`/`04` deixaram de crescer e `01` continua sendo o baseline: `docs/sdd/decisions/ADR-002-sdd-por-feature.md`.
- O setup visto como agente (6 peças, loop, condição de parada, fronteira de autonomia): `docs/sdd/agent-architecture.md`.

Padrão de trabalho:

1. Identifique se a tarefa é sobre entender o negócio (`01-specify.md`), uma feature/épico que já tem pasta em `docs/feature/` (leia `specify.md`→`plan.md`→`tasks.md` de lá), ou algo ainda só listado em `03-tasks.md` (épico antigo não migrado).
1.1. **Se a tarefa é corrigir um defeito** em comportamento que já existe (não desenvolvimento novo), o caminho é o BFF: rode `/novo-bug <slug>` e faça a Triagem primeiro. Nenhum gatilho marcado → fluxo enxuto; qualquer gatilho marcado → `/nova-feature`. Fluxo, Triagem e regra de escalação: `docs/bugfix/README.md` (porquê: `docs/sdd/decisions/ADR-004-fluxo-bugfix.md`).
2. Toda mudança de código deve ser rastreável até uma task concreta. Se não existe nem como task antiga nem como feature, siga "Quando não houver task aplicável" abaixo antes de codar — inclusive antes de criar a pasta em `docs/feature/`.
3. Ao começar a trabalhar num épico antigo de `03-tasks.md` que ainda não foi migrado, crie `docs/feature/<AAAAMMDD>-<slug>/` para ele primeiro (specify/plan/tasks puxando o conteúdo relevante) e deixe só um ponteiro em `03-tasks.md`, como já feito para o Épico B (`docs/feature/concluidas/202608/20260817-seguranca-api/`). O slash command `/nova-feature <slug-curto>` automatiza a criação da pasta a partir de `docs/sdd/templates/`.
4. Para codar, use a skill correspondente ao domínio — `expense-backend` ou `expense-frontend` — que carrega automaticamente o contexto certo (`06-context-backend.md` ou `05-context-frontend.md`: convenções e arquivos de referência daquela frente). Não é preciso invocar isso manualmente fora das skills. Para revisão de segurança de mudanças em rotas/controllers/middleware de auth, ou para conferir o checklist pré-PR, use os agents `security-reviewer` e `pr-readiness-checker` (`.claude/agents/`, catálogo em `docs/sdd/agents-roadmap.md`).
5. Siga o fluxo de execução de `docs/sdd/04-implementation.md` §1 e registre o log em `docs/feature/<AAAAMMDD>-<slug>/implementation.md`, citando comando executado + resultado. Feature concluída (PR mergeado em `dev`) move para `docs/feature/concluidas/<AAAAMM>/` — `docs/sdd/decisions/ADR-009-arquivar-concluidos-por-anomes.md`.
6. Nunca contradiga `00-constitution.md`. Se a task exigir isso, pare e sinalize — editar a Constitution é sempre gate humano. Mudança de stack/arquitetura vira ADR em `docs/sdd/decisions/`.
7. Achado que não bloqueia nenhuma task da feature atual (débito técnico tangencial, ideia de melhoria) não vira task nem fica solto só na conversa — vira um arquivo em `docs/backlog/` (ver `docs/sdd/README.md` §"Backlog de ideias não bloqueantes"), pra virar `TASK-0xx` só quando alguém decidir executá-lo de fato. Quando essa decisão acontecer, use `/promover-backlog <ID>` — não crie a feature manualmente para um item de backlog.
8. Avance somente se o gate human-in-the-loop da etapa estiver aprovado (`docs/sdd/00-constitution.md` §5.2).
9. Finalize com relatório curto: task/objetivo, arquivos consultados/criados/modificados, validações rodadas (pint/phpunit/tsc), gates pendentes de aprovação humana, próximas ações recomendadas.

## Fronteira de autonomia (gates)

Tabela normativa (o que é autônomo vs. o que exige aprovação humana): `docs/sdd/00-constitution.md` §5.2.
Desenho: `docs/sdd/agent-architecture.md` §5. Nada de merge em `main`/`dev`, deploy, expor/rotacionar
segredo, hard delete ou corte de produção do app novo sem aprovação humana explícita.

## Regras que nunca devem ser ignoradas

Digest e detalhe regra a regra: `docs/sdd/00-constitution.md` §6.0.

## Quando não houver task aplicável

Se o pedido não se encaixa em nenhuma task existente em `docs/sdd/03-tasks.md` nem em nenhuma feature já criada em `docs/feature/`, pare e pergunte ao usuário antes de agir — não improvise fora da estrutura do SDD nem crie uma pasta nova em `docs/feature/` sem confirmar antes (nome/slug da feature, escopo).

Exceção: se o pedido é corrigir um bug em comportamento existente, o caminho não é parar nem criar feature — é `/novo-bug <slug>` + Triagem (ver "Padrão de trabalho" item 1.1). Só se a Triagem marcar um gatilho é que vira feature.

Este "pare e pergunte" é um dos pontos de parada do loop de execução — lista completa em `docs/sdd/agent-architecture.md` §3.

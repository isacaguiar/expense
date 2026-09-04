# Modelo arquitetural do SDD

> Visão geral de como o Spec-Driven Development deste projeto está montado: a hierarquia de
> documentos, as quatro trilhas de trabalho paralelas, e o fluxo de branch/PR com os gates
> human-in-the-loop. É uma leitura consolidada — as fontes canônicas são `00-constitution.md`,
> `04-implementation.md` §1, `README.md` desta pasta e os ADRs em `decisions/`.

Síntese · Última atualização: 2026-08-30

---

## 1. A ideia

Qualquer executor — pessoa ou IA — redige specs, planeja e coda em branch livremente. O que é
irreversível ou toca produção, banco compartilhado ou segredos passa por aprovação humana
explícita. Toda a mecânica vive em markdown versionado junto ao código, em `docs/sdd/`; nada
depende de ferramenta externa.

Regra de rastreabilidade que amarra tudo: toda mudança relevante no sistema tem que conseguir
apontar — **qual task → qual parte do plan → qual necessidade de negócio → dentro de quais
regras da Constitution**.

## 2. Hierarquia de documentos

```
00-constitution.md   → regras que nada pode contradizer (Arquitetura, Qualidade, Stack,
                        Compatibilidade, Governança, Segurança)
                        editar = gate humano, sempre
        ↓ constrange tudo abaixo
01-specify.md         → o que o sistema faz hoje, de negócio (fonte: código real)
                        baseline; não se move para docs/feature/
        ↓ traduzido, por feature, em
docs/feature/<AAAAMMDD>-<slug>/          (concluída → docs/feature/concluidas/<AAAAMM>/…)
        plan.md            → engenharia: front / back / banco / infra
        ↓
        tasks.md           → unidades atômicas + critério de aceite (cada uma aponta p/ um item do plan)
        ↓
        implementation.md  → como a task vira código + log do que já foi feito
        ↑
        05 / 06-context-*.md → contexto de execução portátil (as skills apontam pra cá)
```

Desde 2026-08-17 (`ADR-002`), `plan.md` / `tasks.md` / `implementation.md` deixaram de ser
arquivos únicos crescendo para todo o sistema: cada feature nova ganha a própria pasta em
`docs/feature/<AAAAMMDD>-<slug>/` com esses quatro documentos escopados a ela. `00-constitution.md`
e `01-specify.md` continuam no topo, para tudo, e não migram.

Ao concluir (PR único mergeado em `dev`), a pasta migra para `docs/feature/concluidas/<AAAAMM>/<AAAAMMDD>-<slug>/` — `<AAAAMM>` = mês de criação (`ADR-009`); `docs/feature/` na raiz fica só com features em andamento.

## 3. As quatro trilhas de trabalho

Mesmo topo normativo, quatro caminhos:

| Trilha | Entrada | Onde vive | Regra |
|---|---|---|---|
| **Feature — SDD completo** | `/nova-feature <slug>` ou `/promover-backlog <ID>` | `docs/feature/<AAAAMMDD>-<slug>/` (specify · plan · tasks · implementation); concluída → `docs/feature/concluidas/<AAAAMM>/` | Roda as 5 fases inteiras; termina em 1 PR da branch da feature → `dev`. |
| **Backlog — ideia não-bloqueante** | achado que não bloqueia nenhuma task | `docs/backlog/<arquivo>.md` (1 arquivo por ideia, índice em `README.md`, compartilhado por todo o projeto) | Só ganha `TASK-0xx` quando alguém decide executar — aí `/promover-backlog` leva até virar feature. |
| **Bugfix — BFF** | `/novo-bug <slug>` | `docs/bugfix/<AAAAMMDD>-<slug>.md` (arquivo único, branch `fix/…`, 1 PR → `dev`; concluído → `docs/bugfix/concluidos/<AAAAMM>/`) | Triagem de 4 caixas no topo: nenhuma marcada → BFF; qualquer uma (toca auth/dado sensível; exige migration ou muda contrato de API; causa raiz obscura ou correção ampla; exige decisão de produto/arquitetura) → vira feature SDD normal. `ADR-004`. |
| **ADR — decisão registrada** | muda stack, peça de arquitetura travada na Constitution §3, ou convenção do próprio SDD | `docs/sdd/decisions/ADR-0xx.md` (o porquê + alternativas descartadas) | A Constitution registra a regra vigente; o ADR guarda a história da decisão. |

## 4. Branch, PR e gates humanos

Fluxo vigente — `ADR-003` (2026-08-18), passo a passo completo em `04-implementation.md` §1.
`main` é produção (push dispara `deploy-backend.yml`); `dev` é integração, criada a partir de `main`.

```
main ──cria──▶ dev ──cria (quando a 1ª task da feature começa)──▶ feat/<AAAAMMDD>-<slug>
                                                                    │
   ┌────────────────────────────────────────────────────────────────┤
   │  1ª task .......... implementada direto na branch da feature    │
   │  2ª task em diante  branch <tipo>/<...>-TASK-0xx a partir da    │
   │                     branch da feature; volta por               │
   │                     `git checkout <feature> && git merge        │
   │                      --no-ff <task>`  → sem PR, sem gate humano │
   └────────────────────────────────────────────────────────────────┘
                                                                    │
   feature pronta → roda o checklist de integração na branch já completa
                                                                    │
feat/<...> ──── 1 PR (a feature inteira) ────▶ dev     [merge = GATE HUMANO, por feature]
dev ─────────── PR de promoção ─────────────▶ main     [merge = GATE HUMANO]
push em main ──────────────────────────────▶ deploy-backend.yml   [dispara no merge]
```

Um executor autônomo pode ir do branch até os PRs abertos sozinho; o que exige aprovação humana
está listado em §5. O merge em `dev` é um gate **por feature**, não por task.

### Modelos históricos (não misturar os três numa mesma feature em andamento)

| Modelo | Vigência | Fluxo |
|---|---|---|
| 1 | até `docs/feature/concluidas/202608/20260817-config-url-api-frontend/` | branch/PR por task direto pra `main` |
| 2 | 2026-08-17 até `docs/feature/concluidas/202608/20260818-fluxo-despesas-grupo/` (última a segui-lo por completo) | branch de task nasce de `dev`; PR de cada task direto contra `dev`; merge humano por task |
| 3 | a partir de 2026-08-18 (`ADR-003`) | branch da feature + tasks mergeadas nela; **um único PR** por feature → `dev` |

Exceção viva: `docs/feature/concluidas/202608/20260817-config-url-api-frontend/` termina no Modelo 1, para não
trocar o processo no meio da execução.

## 5. Gates human-in-the-loop

Resumo da tabela de Governança (`00-constitution.md` §5.2) — o que é autônomo (IA/dev em branch)
vs. o que trava até uma pessoa aprovar.

| Ação | Autônomo | Aprovação humana |
|---|:---:|:---:|
| Redigir / atualizar Specify, Plan, Tasks | ✅ | — |
| Editar a Constitution | — | ✅ sempre |
| Código, testes, migrations em branch de feature | ✅ | — |
| Migration em banco local / dev | ✅ | — |
| Migration em banco compartilhado / produção (ou destrutiva) | — | ✅ |
| Merge de branch de task na branch da feature (local, sem PR) | ✅ | — |
| Abrir PR (branch da feature → `dev`) ou PR de promoção (`dev` → `main`) | ✅ | — |
| Merge de PR em `dev` (feature inteira) | — | ✅ (revisão do PR) |
| Merge em `main` (produção) | — | ✅ (revisão do PR de promoção) |
| Deploy (`deploy-backend.yml`, EAS build do Expo) | — | ✅ — na prática, o merge em `main` já dispara |
| Rotacionar, expor ou remover segredo / credencial | — | ✅ (rotação é 100% humana) |
| Apagar dado definitivamente (hard delete) | — | ✅ |
| Corte de produção do frontend novo (`expense/app`) substituindo `expense/frontend` | — | ✅ |

## 6. Skills e agents

`05-context-frontend.md` e `06-context-backend.md` são markdown puro — o que carregar antes de
codar, convenções fixas e os gates de cada frente. Duas skills do Claude Code
(`expense-frontend`, `expense-backend`) são só adaptadores finos que apontam pra eles: trocar de
ferramenta de IA reescreve o adaptador, não o conteúdo.

Agents nativos em `.claude/agents/`: `security-reviewer` (auth / autorização no backend) e
`pr-readiness-checker` (checklist pré-PR). Cada um só foi construído quando um gatilho concreto
apareceu no repo; candidatos futuros (`migration-safety-checker`, `balance-calc-auditor`,
`frontend-parity-checker`) ficam registrados em `agents-roadmap.md` sem serem criados — a mesma
regra de "não criar estrutura vazia pra reservar espaço".

---

## Referências

- `docs/sdd/README.md` — ordem de leitura, trilhas paralelas, resumo dos gates.
- `docs/sdd/00-constitution.md` — regras vigentes; §5.1 (fluxo de branch/PR e histórico dos 3 modelos), §5.2 (tabela de Governança).
- `docs/sdd/04-implementation.md` §1 — passo a passo da execução de uma task.
- `docs/sdd/decisions/` — `ADR-002` (SDD por feature), `ADR-003` (fluxo de branch por feature), `ADR-004` (BFF).
- `docs/sdd/agents-roadmap.md` — agents construídos e candidatos.

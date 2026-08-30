# ADR-007: Fonte única por fato + documento de arquitetura do agente

Status: Aceita
Data: 2026-08-30

## Contexto

O SDD cresceu com o mesmo fato de governança **copiado** em vários arquivos, e as cópias já
divergem entre si. Levantamento (2026-08-30):

- **Tabela de gates / fronteira de autonomia**: canônica em `00-constitution.md` §5.2 (18 linhas);
  cópias abreviadas em `CLAUDE.md` (9 linhas), `README.md` (7 linhas), `05-context-frontend.md` e
  `06-context-backend.md` (prosa), `04-implementation.md` §2, e a própria Constituição em §6.6.
- **Histórico dos 3 fluxos de branch/PR**: `00-constitution.md` §5.1.1 **e** `04-implementation.md`
  §1 (blockquote quase idêntico).
- **Achados de segurança conhecidos**: espalhados por ~15 pontos, incluindo três menções dentro da
  própria Constituição (§5.3, §6.2, §6.5).
- **Triagem BFF (4 gatilhos)**: `bugfix/README.md`, `bugfix/templates/bugfix.template.md`,
  `00-constitution.md` §5.1.2, `README.md`, `.claude/skills/novo-bug/SKILL.md`.
- **Ordem de leitura / 5 fases**: `CLAUDE.md`, `README.md`, `03-tasks.md`.
- **Portabilidade** ("`05`/`06` são a fonte da verdade; as skills são adaptadores finos"):
  `README.md` + os blockquotes de `05`/`06` + os próprios `SKILL.md`.

Sintomas: não há um **ponto de referência único** — quem chega não sabe qual cópia é a boa; e
manter as cópias em sincronia é trabalho manual que já falhou.

Em paralelo, o setup (SDD + `.claude/`) nunca foi descrito como um **agente**: faltavam a peça
"Modelo" explícita, o loop de trabalho desenhado, uma **condição de parada** consolidada (a string
não existia em nenhum arquivo), o nome do padrão arquitetural e uma legenda de time sem jargão.

## Decisão

**1. Um lar autoritativo por fato.** Cada fato de governança tem exatamente um documento/seção
canônico. Toda outra menção vira um ponteiro curto (`ver <arquivo> §<seção>`) — **sem
re-enumerar** o conteúdo. Vale para todos os arquivos, inclusive os que rodam em contexto isolado
(`.claude/agents/*`, `05`/`06-context-*.md`): eles seguem o link em vez de carregar a regra inline.

Lares canônicos:

| Fato | Lar único |
|---|---|
| Gates / fronteira de autonomia (tabela) | `00-constitution.md` §5.2 |
| Condição de parada do loop (consolidada) | `agent-architecture.md` §3 |
| Ordem de leitura / fluxo | `README.md` |
| Regra normativa das 5 fases | `00-constitution.md` §5.1 |
| Porquê `02`/`03`/`04` congelaram e `01` é baseline | `ADR-002` |
| "Regras que nunca devem ser ignoradas" (digest nomeado) | `00-constitution.md` §6.0 |
| Invariantes de segurança, regra a regra | `00-constitution.md` §1, §2, §6 |
| Histórico dos 3 fluxos de branch | `00-constitution.md` §5.1.1 (+ `ADR-003` para o porquê) |
| Achados de segurança conhecidos | `00-constitution.md` §5.3 (invariante) + `feature/20260817-seguranca-api/` (remediação) |
| Triagem BFF + roteamento das 3 vias | `bugfix/README.md` (+ `ADR-004` para o porquê) |
| Portabilidade (`05`/`06` + adaptadores) | `README.md`, "Skills e portabilidade" |

**2. `agent-architecture.md`** (novo) é o lar único do enquadramento de agente: as 6 peças (cada
uma apontando para onde de fato vive), o loop desenhado, a condição de parada consolidada, os 3
padrões com a alternativa descartada de cada, o desenho da fronteira e a legenda de time.
É **descritivo** — não normativo; a Constituição continua no topo.

**3. `CLAUDE.md` é explicitamente um roteador fino** — aponta para os lares canônicos, não carrega
cópia de tabela nem de lista. As exceções são o conteúdo que só existe ali (a regra de entrada, o
"Padrão de trabalho" em passos, "Quando não houver task aplicável").

**4. Constituição** ganha a §6.0 (digest "regras que nunca devem ser ignoradas", movido de
`CLAUDE.md`), tem a auto-duplicação de §6.2/§6.5/§6.6 trocada por ponteiros para §5.2/§5.3, e sobe
para a versão 1.4.

## Consequências

- Trade-off aceito: um executor ou subagente trabalhando em contexto isolado agora **segue um
  link** para ver uma regra, em vez de lê-la inline. Mitigação: o roteador (`CLAUDE.md`) é sempre
  carregado no início; os ponteiros são curtos e nomeiam a seção exata.
- Editar a Constituição é gate humano (§5.2) — esta mudança passou pelo gate.
- Os templates de `docs/sdd/templates/` e os docs de `docs/feature/` já apontavam para a canon, não
  copiavam — não precisam mudar (exceção: `bugfix/templates/bugfix.template.md`, cujas descrições
  longas das caixas de Triagem viram rótulos + ponteiro; os checkboxes ficam, é template de
  preencher).
- Docs congelados (`02-plan.md`, `03-tasks.md`) recebem só uma linha de ponteiro no topo da seção
  afetada; o corpo histórico não é reescrito.
- Regra corrente para quem escrever doc novo: **não copie uma regra que já tem lar — aponte.**

## Alternativas consideradas

- **Manter um espelho deliberado da tabela de gates no `CLAUDE.md`** (por ser o arquivo sempre
  carregado): descartada — continua sendo uma cópia que diverge; o ponteiro curto resolve o mesmo
  problema sem o custo de sincronização.
- **De-duplicação mínima** (só as cópias verbatim mais óbvias): descartada — deixaria a Triagem
  BFF em ~5 lugares e a portabilidade em ~4, que é exatamente o padrão que motivou a decisão.
- **Colocar o enquadramento de agente dentro do `README.md`**: descartada — mistura o "mapa de
  leitura do SDD" com material conceitual; o `README.md` deixaria de ser escaneável.
- **Renomear/expandir `agents-roadmap.md` para cobrir registro + anatomia**: descartada — junta
  dois propósitos diferentes (catálogo operacional de subagentes vs. material de arquitetura).

## Referências

- `docs/sdd/agent-architecture.md` — o documento novo.
- `docs/sdd/00-constitution.md` §5 (gates, fases, histórico de branch) e §6 (segurança, novo §6.0).
- `docs/sdd/README.md` — ordem de leitura, "Skills e portabilidade".
- `docs/sdd/decisions/ADR-002-sdd-por-feature.md`, `ADR-003-fluxo-branch-por-feature.md`,
  `ADR-004-fluxo-bugfix.md` — o "porquê" de fluxos que agora são só apontados.

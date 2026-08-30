# Arquitetura do agente — Controle de Despesas Compartilhadas

> Este documento enquadra o SDD (`docs/sdd/`) + o diretório `.claude/` como um **agente de
> software**: as 6 peças da anatomia, o loop de trabalho, a condição de parada e a fronteira de
> autonomia. É **descritivo** — a fonte normativa continua sendo `00-constitution.md`. Para a
> ordem de leitura do SDD, ver `README.md`.

Versão: 1.0 · Criado em: 2026-08-30

---

## 1. Visão geral

Quando uma pessoa pede uma mudança neste projeto, quem executa (uma IA ou um dev) age como um
agente: recebe um pedido, junta o contexto certo, decide a próxima alteração, aplica, observa o
resultado real e repete — dentro de regras que não pode contradizer, e parando nos pontos onde a
decisão é de uma pessoa. As seções abaixo tornam isso explícito. Nada aqui é regra nova: cada
regra citada já vive em outro documento; aqui ela só é **desenhada** e **consolidada**.

## 2. As 6 peças

| Peça | O que é, neste projeto | Vive em |
|---|---|---|
| **Modelo** | O executor de IA (qualquer assistente; hoje, Claude Code) que interpreta o pedido, planeja as alterações, decide a próxima ação e avalia o resultado do checklist. Sem nada específico de ferramenta — a mesma função valeria para outro executor. | *(descrito aqui; o comportamento portátil está em `05-context-frontend.md` / `06-context-backend.md`)* |
| **Loop** | perceber → decidir → agir → observar, sobre **uma task**. | **desenhado na §3** |
| **Ferramentas** | Edição de arquivos; comandos reais de validação (`./vendor/bin/pint --test`, `php artisan test`, `npx tsc --noEmit`, `vite build`); os subagentes `security-reviewer` e `pr-readiness-checker` (read-only); as skills de scaffold; os servidores de `launch.json`. | `04-implementation.md` §1.3, `.claude/agents/`, `.claude/skills/`, `.claude/launch.json` |
| **Memória** | O rastro de execução: log por task (comando executado + resultado real), índices de backlog e de bugfix, e as decisões arquiteturais. Não é memória "de sessão" — é rastro versionado. | `docs/feature/<AAAAMMDD>-<slug>/implementation.md`, `../backlog/README.md`, `../bugfix/README.md`, `decisions/` |
| **Contexto** | O que carregar antes de codar, por domínio (convenções, arquivos de referência, mockups), e a ordem de leitura do SDD. | `05-context-frontend.md`, `06-context-backend.md`, `README.md` |
| **Políticas** | As regras que nada pode contradizer: gates human-in-the-loop, invariantes de segurança, regras de arquitetura/qualidade/stack. | `00-constitution.md` (§5.2 gates, §6 segurança, §1–§2 invariantes) |

## 3. O loop e a condição de parada

```
                 uma TASK (rastreável a um plan / a uma necessidade de negócio)
                          |
                          v
          +----------------------------------+
          |  PERCEBER   task + contexto do   |
          |             domínio + políticas  |
          +----------------+-----------------+
                           v
          +----------------------------------+
          |  DECIDIR     próxima alteração   |
          +----------------+-----------------+
                           v
          +----------------------------------+
          |  AGIR        editar arquivos     |
          |             rodar validações     |
          +----------------+-----------------+
                           v
          +----------------------------------+
          |  OBSERVAR    resultado real      |
          |             (comando + saída)    |
          +----------------+-----------------+
                           |
              +------------+------------+
              |   condição de parada?   |
              +------------+------------+
                não |            | sim
           (volta a |            v
            PERCEBER)     PARA e devolve
                          (relatório + gate)
```

**Condição de parada** — o loop para quando **qualquer** uma vale (regras já definidas em outros
documentos; aqui só consolidadas):

- o critério de aceite da task está cumprido e verificável **e** o checklist de
  `04-implementation.md` §1.3 está verde; **ou**
- a próxima ação necessária é um **gate humano** da `00-constitution.md` §5.2 (merge, deploy,
  migration compartilhada/destrutiva, segredo, hard delete, editar a Constituição, corte de
  produção); **ou**
- durante uma correção de bug, apareceu um gatilho da **Triagem** de `../bugfix/README.md` → o
  trabalho reroteia para feature, não continua no fluxo enxuto; **ou**
- o pedido **não mapeia** em nenhuma task/feature/bug conhecido → para e pergunta ao usuário
  (`../../CLAUDE.md`, "Quando não houver task aplicável"); **ou**
- a causa raiz não fechou dentro do timebox de investigação.

Ao parar, o executor entrega um relatório curto (task/objetivo, arquivos consultados/alterados,
validações rodadas com resultado real, gates pendentes, próximas ações) — nunca assume aprovação
implícita de pedido anterior.

## 4. O padrão arquitetural

O projeto roda **três formas**, uma por fluxo de trabalho. O padrão não é escolhido por
sofisticação — é o que torna o loop observável e o custo de coordenação justificável.

### 4.1 Correção de bug (`../bugfix/`) → fluxo único

```
   defeito em comportamento existente
            |
            v
   [ TRIAGEM: 4 caixas ] --alguma marcada--> vira feature (§4.2)
            | nenhuma
            v
   +------------------------+
   | 1 arquivo · 1 branch   |   loop perceber/decidir/agir/observar
   | 1 PR contra `dev`      |   sobre a correção
   +-----------+------------+
               v
        HUMANO: merge em `dev`
```

Atividade coesa, início/fim claros. **Alternativa descartada:** rodar todo defeito pelo
SDD-por-feature completo (4 documentos + sub-branches) — cerimônia desproporcional ao tamanho da
correção (`decisions/ADR-004-fluxo-bugfix.md`).

### 4.2 Feature nova / épico técnico (`../feature/`) → encadeamento

```
  Constitution --> Specify --> Plan --> Tasks --> Implementation
   (nada abaixo    (o que o    (como     (unidades  (código + log
    contradiz       sistema     vira      atômicas)   por task)
    a camada        faz)        engenharia)
    acima)
```

Cada camada é uma etapa cuja saída alimenta a próxima e não pode contradizer a anterior.
**Alternativa descartada:** manter `02/03/04` como arquivos únicos crescendo para todo o sistema
(`decisions/ADR-002-sdd-por-feature.md`); e PR por task em vez de um PR por feature
(`decisions/ADR-003-fluxo-branch-por-feature.md`).

### 4.3 O executor + subagentes → orquestrador + subagentes/adapters

```
        EXECUTOR (mantém o objetivo da feature, controla o loop)
           |
     +-----+---------------------------+-------------------+
     v                                 v                   v
  security-reviewer            pr-readiness-checker      skills
  (read-only; revisa           (read-only; roda o       (adaptadores finos:
   auth/autorização)            checklist pré-PR)         carregam contexto)
     |                                 |
     +--------- relatam; não corrigem, não mergeiam ------+
```

Um principal mantendo o objetivo e delegando a especialistas com contexto próprio e ferramentas
restritas. **Alternativa descartada:** criar todos os subagentes de antemão — cada um só é
construído quando um gatilho concreto justifica (`agents-roadmap.md`, "Candidatos futuros").

## 5. Fronteira de autonomia

```
   AUTÔNOMO (IA/dev em branch)                 EXIGE APROVAÇÃO HUMANA
   redigir Specify/Plan/Tasks                  editar a Constituição
   codar, testar, migration local             migration compartilhada/destrutiva
   merge de task na branch da feature         merge em `dev` / merge em `main`
   abrir Pull Request                         deploy · rotacionar/expor segredo
                                              hard delete · corte de produção do app novo
   +-------------------------------*-------------------------------+
                                   ^
                       tabela normativa linha-a-linha:
                          00-constitution.md §5.2
```

Arquitetar o agente não é empurrar autonomia para a direita — é escolher, de forma revisável,
onde a responsabilidade humana permanece.

## 6. Legenda para o time

- **O que ele faz sozinho:** lê o contexto do domínio e as regras, escreve as especificações e o
  plano, escreve e testa o código numa branch isolada, roda as validações e abre o Pull Request.
- **Onde ele para e chama uma pessoa:** quando o próximo passo muda algo difícil de reverter ou
  visível para fora — juntar o código na linha principal, publicar, mexer em senha/credencial,
  apagar dado de vez — ou quando o pedido não cabe em nada que já foi planejado.
- **O que ele nunca faz sem aprovação:** juntar na linha de produção, publicar, expor ou trocar
  segredo, apagar dado definitivamente, mudar as regras de base do projeto.
- **Como você revisa o que ele fez:** pelo diff dos arquivos, pelo log de cada task (o comando que
  ele rodou e o resultado que deu) e pelos gates que ele deixou marcados como pendentes.

---

Ver também: `README.md` (ordem de leitura), `00-constitution.md` (regras), `agents-roadmap.md`
(catálogo de subagentes), `decisions/` (ADRs).

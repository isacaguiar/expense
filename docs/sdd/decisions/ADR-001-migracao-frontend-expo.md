# ADR-001: Migração do frontend para Expo + React Native Paper

Status: Aceita
Data: 2026-08-17

## Contexto

O projeto tinha um único frontend web (`expense/frontend`: React + Vite + MUI + `react-router-dom`). Surgiu a necessidade de um app mobile. Em vez de manter dois códigos de UI completamente separados (um web, um mobile nativo), buscou-se uma stack que unificasse o máximo possível de código entre web e mobile, minimizando redesenho visual e duplicação de lógica de tela.

## Decisão

Novo projeto `expense/app`, usando **Expo (managed workflow)** + **`react-native-web`** + **Expo Router** (roteamento por arquivo) + **React Native Paper** (Material Design — mesma linguagem visual do MUI atual, minimizando redesenho).

`expense/frontend` (React web atual) **não é alterado nem desligado** durante a migração — continua em produção normalmente, consumindo a mesma API sem mudança de contrato. O corte de produção (apontar produção para `expense/app` no lugar de `expense/frontend`) é decisão futura, com gate humano explícito (ver `00-constitution.md` §5.2, e a task de corte `TASK-010` em `03-tasks.md`).

## Consequências

- Backend não muda de contrato só por causa desta migração (`00-constitution.md` §4.3): os dois frontends devem coexistir consumindo a mesma API.
- Autenticação client-side muda de armazenamento (`localStorage` no web → `expo-secure-store`/`AsyncStorage` no app), mas o fluxo JWT Bearer continua o mesmo.
- Todo o trabalho de telas precisa ser portado tela a tela (`LoginPage` → Dashboard → navegação → Grupo → Despesas), gerando um período de duplicação temporária de UI (duas árvores de tela ativas ao mesmo tempo) até o corte.
- Nenhuma mudança de schema de banco é necessária só por esta decisão.

## Alternativas consideradas

- **App nativo separado (Swift/Kotlin ou Flutter), sem reaproveitamento de UI com o web**: descartada — dobraria o esforço de manter duas linguagens/paradigmas de UI para o mesmo conjunto de telas, sem ganho claro dado o tamanho da equipe.
- **Continuar só com o frontend web, sem app mobile dedicado (PWA)**: não descartada tecnicamente, mas não atende à necessidade que motivou a decisão (experiência de app mobile "de verdade").

## Referências

- `00-constitution.md` §3 (Stack) — trava a stack: "Frontend mobile/web unificado (em migração): Expo + react-native-web + Expo Router + react-native-paper".
- `02-plan.md` §2 — detalhamento técnico completo da migração (mapeamento de peças, telas a portar).
- `03-tasks.md` — Épico A (TASK-001 a TASK-010).

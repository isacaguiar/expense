# Configurar ESLint no frontend

ID: 033
Origem: docs/feature/20260827-ci-frontend/specify.md §2.2 (achado ao promover o item de backlog 008 — CI do frontend)
Criado em: 2026-08-27
Prioridade: BAIXA
Status: Aberto

## Descrição

O frontend (`expense/frontend`) não tem ESLint configurado hoje — não existe `.eslintrc`/`eslint.config.*` nem script `lint` em `package.json`. Configurar do zero exige: escolher o preset de regras (ex.: `eslint-plugin-react`, `@typescript-eslint`), rodar contra a base inteira do projeto e corrigir as violações existentes antes de integrar a um workflow de CI — trabalho e escopo próprios, maior que só adicionar a dependência.

## Por que importa

Sem lint, problemas de estilo/qualidade de código (imports não usados, variáveis não usadas, regras de hooks do React, etc.) só são pegos em revisão manual. O workflow de CI criado pela feature `ci-frontend` (TASK correspondente ao item 008) roda `tsc`/testes/build, mas não lint — quando o ESLint existir, adicionar um passo a esse workflow é trabalho incremental barato.

Tipo sugerido: frontend

# Infraestrutura de testes no frontend

ID: 002
Origem: docs/feature/20260817-migracao-frontend-expo/specify.md §2.4
Criado em: 2026-08-17
Prioridade: MEDIA
Status: Promovido para TASK-030

## Descrição

Não existe nenhum teste automatizado no frontend hoje: `package.json` não tem `vitest`, `jest` nem `@testing-library/*` em nenhuma dependência, e não há arquivos de teste em `frontend/src`. Decisão em aberto: padronizar em Vitest + Testing Library no web e RN Testing Library no app (`expense/app`), ou buscar um runner único para a lógica compartilhada entre as duas plataformas.

## Por que importa

Não bloqueia nenhuma task específica da migração para React Native, mas a ausência de testes aumenta o risco de regressão conforme o mesmo conjunto de telas passa a existir em duas plataformas (web e app) consumindo a mesma API.

Tipo sugerido: frontend/infra

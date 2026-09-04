# Configuração de URL da API via variável de ambiente

ID: 001
Origem: docs/feature/20260817-migracao-frontend-expo/specify.md §2.1
Criado em: 2026-08-17
Prioridade: MEDIA
Status: Promovido para TASK-027

## Descrição

A URL base da API está hardcoded como string em cada arquivo do frontend (`http://localhost:8000/api/...`) em vez de vir de uma variável de ambiente (`VITE_API_BASE_URL` no web, equivalente via `expo-constants`/`app.config.ts` no app Expo).

## Por que importa

Não bloqueia nenhuma task da migração para React Native, mas é necessário antes do corte de produção (`TASK-010` em `docs/feature/20260817-migracao-frontend-expo/tasks.md`) — sem isso, apontar `expense/app` para um backend de produção exigiria editar código-fonte em vez de configuração.

Tipo sugerido: frontend

## Resolução
Concluído em: 2026-08-17
Feature: docs/feature/concluidas/202608/20260817-config-url-api-frontend/
Tasks: TASK-027
PRs: #4

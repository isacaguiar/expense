# `LoginPage` grava `refreshToken` com a string `"undefined"`

ID: 046
Origem: docs/feature/concluidas/202609/20260919-cadastro-de-usuarios/ (achado ao mapear o fluxo de sessão)
Criado em: 2026-09-19
Prioridade: BAIXA
Status: Aberto

## Descrição

`frontend/src/pages/LoginPage.tsx:37` faz `localStorage.setItem('refreshToken', data.refresh_token)`,
mas `AuthController::respondWithToken` (`backend/app/Http/Controllers/AuthController.php:63`)
devolve só `access_token`, `token_type` e `expires_in` — **não existe `refresh_token` no payload**.
O resultado é a string literal `"undefined"` gravada no `localStorage` de todo mundo que faz login.

A feature de cadastro introduziu `frontend/src/auth/session.ts` (`setSession`), que grava só o
`accessToken` e é o lugar natural para o `LoginPage` migrar — mas fazer isso não era escopo dela.

## Por que importa

Hoje é inofensivo (nada lê essa chave), mas é uma pegadinha: qualquer código futuro que cheque
`localStorage.getItem('refreshToken')` vai receber uma string truthy e concluir que há refresh
token. Corrigir junto com a migração do `LoginPage` para `setSession` resolve os dois de uma vez.

Tipo sugerido: frontend

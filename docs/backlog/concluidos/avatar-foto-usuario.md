# Suportar foto de perfil do usuário

ID: 021
Origem: docs/feature/concluidas/202608/20260819-novo-layout-tela-entrada/specify.md §2.5/R3 (avatar do cabeçalho usa iniciais, mockup mostra foto)
Criado em: 2026-08-19
Prioridade: BAIXA
Status: Promovido para TASK-268 (docs/feature/concluidas/202609/20260903-notificacoes-in-app/)

## Descrição

O mockup da tela de Resumo mostra uma foto de perfil real no avatar do cabeçalho; o model `User` (`backend/app/Models/User.php`) não tem campo de foto/avatar hoje, e não há upload de imagem em nenhum fluxo do app. O avatar implementado nesta feature usa as iniciais do nome (`GET /api/me`).

## Por que importa

Puramente cosmético — não bloqueia nenhum fluxo. Exigiria campo novo no `User`, endpoint de upload e armazenamento de arquivo (local ou S3-like), fora do escopo de um redesenho visual.

Tipo sugerido: backend

## Resolução

Concluído em: 2026-09-03
Feature: docs/feature/concluidas/202609/20260903-notificacoes-in-app/ (agrupado com o item 020)
Tasks: TASK-268, TASK-269, TASK-270
PRs: https://github.com/isacaguiar/expense/pull/141

Coluna `ex_users.photo_path` (separada da `avatar_url` do Google), helper
`App\Support\AvatarStorage` (disco privado), `POST`/`DELETE /api/user/photo` e
`UserPhotoController` servindo por rota assinada (`user.photo`, ADR-005). O
accessor `User::getAvatarUrlAttribute` resolve a precedência foto enviada >
foto do Google > null. No frontend: upload/remoção em `Profile.tsx` e o
`Avatar` do cabeçalho passa a usar `src` com fallback de iniciais.

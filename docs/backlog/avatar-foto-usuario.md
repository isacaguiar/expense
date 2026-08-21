# Suportar foto de perfil do usuário

ID: 021
Origem: docs/feature/20260819-novo-layout-tela-entrada/specify.md §2.5/R3 (avatar do cabeçalho usa iniciais, mockup mostra foto)
Criado em: 2026-08-19
Prioridade: BAIXA
Status: Aberto

## Descrição

O mockup da tela de Resumo mostra uma foto de perfil real no avatar do cabeçalho; o model `User` (`backend/app/Models/User.php`) não tem campo de foto/avatar hoje, e não há upload de imagem em nenhum fluxo do app. O avatar implementado nesta feature usa as iniciais do nome (`GET /api/me`).

## Por que importa

Puramente cosmético — não bloqueia nenhum fluxo. Exigiria campo novo no `User`, endpoint de upload e armazenamento de arquivo (local ou S3-like), fora do escopo de um redesenho visual.

Tipo sugerido: backend

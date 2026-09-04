# Specify — Melhoria da Tela de Grupos

> Feature: adiciona limite de criação de grupos por usuário, confirma que a participação via convite continua sem limite, passa a exibir o responsável (criador) de cada grupo, e move "Grupos" para submenu de "Configurações" no menu lateral. Pedido novo, sem épico correspondente em `docs/sdd/03-tasks.md`.

Versão: 1.0 · Criado em: 20260820

---

## 1. Problema

Hoje a criação de grupo não tem nenhum limite: um usuário pode criar quantos grupos quiser (`GroupController@store`, `backend/app/Http/Controllers/GroupController.php:35-56` — nenhuma validação de quantidade). Além disso, ao criar um grupo o criador é apenas anexado à tabela pivot de membros, exatamente como qualquer outro membro convidado depois (`GroupController.php:52`, `$group->members()->attach(auth()->id())`): não existe coluna de papel/criador nem na tabela `ex_groups_members` (`backend/database/migrations/2025_06_12_024544_create_ex_group_members_table.php:14-22`, só a chave composta `group_id`+`user_id`) nem na tabela `ex_groups` (`backend/database/migrations/2025_06_07_033033_create_ex_groups_table.php:14-21`, confirmado pelo fillable de `Group.php:11-17`). `docs/sdd/01-specify.md:86` já documenta essa lacuna: o pivot é "simples (sem metadados extras hoje)".

Consequência prática: hoje é impossível, tanto no backend quanto na tela (`GroupForm.tsx`, `Dashboard.tsx` — nenhum dos dois tem campo/exibição de criador), saber quem criou um grupo, porque essa informação nunca é persistida.

Esta feature introduz uma regra de negócio nova (limite de criação) e resolve essa lacuna de dado (responsável pelo grupo), ambas motivadas por pedido direto do usuário.

## 2. Requisitos

### 2.1 Limite de criação de grupos por usuário

Um usuário pode ser **criador** de no máximo 3 grupos simultaneamente (considerando apenas grupos não excluídos, `deleted = false`). Ao tentar criar um 4º grupo, a criação deve ser bloqueada com mensagem clara para o usuário, com validação tanto no backend (`GroupController@store`) quanto refletida no frontend (`GroupForm.tsx` / `Dashboard.tsx`, ex.: desabilitar/ocultar o botão "Novo grupo" ou mostrar erro do backend).

Pré-requisito técnico: a persistência de quem é o criador do grupo (ver 2.3) precisa existir para que esse limite possa ser contado — hoje esse dado não existe (ver Problema).

### 2.2 Participação sem limite via convite

Não há limite para quantos grupos um usuário pode ser **membro** (convidado por outro criador) — apenas a criação de grupos (2.1) é limitada. Hoje já não existe nenhum limite de nenhum tipo; este requisito formaliza que o comportamento atual de adição de membro (`POST /api/groups/:id/members`, sem limite) deve continuar exatamente como está e não deve ser afetado pela nova regra de 2.1 — um usuário pode, por exemplo, já ser criador de 3 grupos (no limite) e ainda assim aceitar ser convidado para um 4º, 5º, N-ésimo grupo por outra pessoa.

### 2.3 Exibição do responsável pelo grupo

A UI deve exibir quem é o criador/responsável de cada grupo (nome ou e-mail), pelo menos em `Dashboard.tsx` (listagem de grupos) e na tela de membros do grupo (`GroupMembersForm.tsx`). Depende do mesmo dado persistido para viabilizar 2.1.

### 2.4 "Grupos" como submenu de "Configurações" no menu lateral

Hoje o menu lateral sem grupo selecionado (`frontend/src/layouts/simpleNavItems.ts:9-16`) lista "Meus Grupos" (→ `/dashboard`) como item de primeiro nível, e "Configurações" como item de primeiro nível separado, hoje sem link (placeholder, sem `to`). O menu lateral com grupo selecionado (`frontend/src/layouts/group/GroupSidebar.tsx:20-29`, `groupNavItems`) também tem "Configurações" como item de primeiro nível, mas apontando para a edição do próprio grupo (`/groups/:id/edit`) — não lista os grupos do usuário.

Esta feature move o item "Meus Grupos" (tela `Dashboard.tsx`, listagem/criação de grupos) para dentro de "Configurações", como submenu, em vez de aparecer como item de primeiro nível no menu sem grupo selecionado. Como o componente `Sidebar.tsx` (`frontend/src/layouts/Sidebar.tsx:38-73`) hoje só renderiza uma lista plana de itens — sem suporte a item expansível/submenu — este requisito também exige adicionar esse suporte ao componente genérico `Sidebar`.

## 3. Fora de escopo desta feature

- Sistema geral de papéis/permissões (owner vs. member): esta feature identifica o criador apenas para contagem do limite (2.1) e exibição (2.3), não para diferenciar o que cada papel pode fazer no grupo (editar, excluir, remover membros, etc.).
- Alteração do fluxo de convite/adição de membro em si além do necessário para confirmar que não é afetado pelo limite de criação (2.2 é uma garantia de não regressão, não uma mudança de fluxo).
- Decisão sobre o que fazer com grupos já existentes sem criador registrado (backfill de dado histórico) — decidir e rodar esse backfill em ambiente compartilhado ou produção é gate humano explícito (`CLAUDE.md` — migration em ambiente compartilhado/produção), fora do escopo autônomo desta feature.
- Transferência de responsável/criador entre usuários.
- Alteração do menu lateral com grupo selecionado (`groupNavItems`, `GroupSidebar.tsx`) — o item "Configurações" desse menu continua apontando para a edição do próprio grupo (`/groups/:id/edit`); o requisito 2.4 afeta apenas o menu sem grupo selecionado (`simpleNavItems.ts`).

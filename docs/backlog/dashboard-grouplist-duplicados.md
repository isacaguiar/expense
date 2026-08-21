# Consolidar Dashboard.tsx e GroupList.tsx (páginas quase duplicadas)

ID: 027
Origem: docs/feature/20260820-atualizacao-layout-paginas/specify.md (achado ao mapear páginas a atualizar)
Criado em: 2026-08-20
Prioridade: BAIXA
Status: Aberto

## Descrição

`Dashboard.tsx` (rota `/dashboard`) e `GroupList.tsx` (rota `/groups`) fazem quase a mesma coisa — listam os grupos do usuário (`GET /api/groups`) em cards, com pequenas diferenças (`GroupList.tsx` tem busca e botão "Novo Grupo"; `Dashboard.tsx` tem um ícone de ação a mais — "Resumo"). `GroupList.tsx:65` também tem um título com resíduo de debug: `"Meus Grupos XXX"`.

## Por que importa

Manter duas telas quase idênticas duplica manutenção (qualquer ajuste visual/funcional precisa ser feito duas vezes) e é confuso para o usuário ter duas rotas para "a mesma coisa". Vale decidir qual delas é a canônica (ou fundir as duas) antes/durante a modernização visual dessas páginas.

Tipo sugerido: frontend

# Criar tela de Pagamentos do grupo

ID: 017
Origem: docs/feature/20260819-novo-layout-tela-entrada/specify.md §2.3/R2 (item "Pagamentos" da sidebar como placeholder `href="#"`)
Criado em: 2026-08-19
Prioridade: BAIXA
Status: Aberto

## Descrição

O novo layout da tela de Resumo (`novo-layout-tela-entrada`) inclui uma sidebar com o item "Pagamentos", que hoje não tem nenhuma tela/rota correspondente no `frontend/` nem endpoint dedicado no backend — fica como link visual desabilitado. Implementar de fato exigiria definir o que "Pagamentos" significa no domínio (marcar quota como paga? histórico de cobranças Pix?) antes de criar rota, tela e endpoint.

## Por que importa

Sem isso, o item "Pagamentos" da sidebar fica como promessa visual não cumprida. Depende de decisão de produto sobre o escopo exato (relaciona-se com o achado 2.6 do `specify.md` de `docs/feature/20260818-resumo-grupo-dashboard/`, sobre não existir endpoint para marcar `Quota.paid`).

Tipo sugerido: frontend

## Resolução

Concluído em: 2026-08-22 (tela base) / em andamento 2026-08-25 (grid + Pix)
Feature: docs/feature/20260822-criacao-tela-pagamentos/ (tela dedicada `/groups/:id/payments` + `PaymentsEntry`, lista de despesas por competência, confirmação de pagamento com foto de comprovante) e docs/feature/20260825-pagamentos-grid-pix/ (layout em grid, Pix QR Code + copia-e-cola, confirmação de settlement pelo devedor)
Tasks: feature `20260822-criacao-tela-pagamentos` (todas concluídas, PR mergeado em `dev`); feature `20260825-pagamentos-grid-pix` TASK-001..003 concluídas, TASK-004..007 em execução
Nota: item resolvido por pedido direto do usuário (feature criada sob demanda), não pelo fluxo `/promover-backlog`. A decisão de produto sobre "o que Pagamentos significa" foi tomada nas duas features: listar despesas do ciclo + confirmar pagamento (credor) + liquidação par-a-par com Pix + comprovante do devedor. O item relacionado sobre marcar `Quota.paid` (endpoint de escrita) já existia (`POST /expenses/{id}/pay`).

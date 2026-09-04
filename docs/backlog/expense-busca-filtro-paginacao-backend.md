# Busca, filtro por tipo e paginação server-side no endpoint de despesas do grupo

ID: 026
Origem: docs/feature/concluidas/202608/20260820-atualizacao-layout-paginas/specify.md (achado ao avaliar o mockup de Despesas)
Criado em: 2026-08-20
Prioridade: BAIXA
Status: Aberto

## Descrição

O mockup de Despesas mostra busca por texto, filtro por tipo (Todas/Fixas/Variáveis) e paginação (ex.: "Mostrando 1 a 5 de 12 despesas"). `ExpenseController::indexByGroup` (`backend/app/Http/Controllers/ExpenseController.php:16-76`) só aceita `year`/`month`, devolve tudo do mês sem paginar. A feature `atualizacao-layout-paginas` implementou busca/filtro/paginação **no cliente**, sobre os dados do mês já carregados — funciona para volumes pequenos, mas não escala se um grupo tiver muitas despesas num mês.

## Por que importa

Baixo risco hoje (grupos domésticos tendem a ter poucas despesas por mês), mas se o uso crescer, carregar/filtrar tudo no cliente fica lento e a paginação deixa de fazer sentido (o cliente já tem os dados todos, só está fatiando visualmente).

Tipo sugerido: backend

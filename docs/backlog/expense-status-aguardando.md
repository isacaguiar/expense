# Definir e implementar status "Aguardando" para despesas

ID: 025
Origem: docs/feature/20260820-atualizacao-layout-paginas/specify.md (achado ao avaliar o mockup de Despesas)
Criado em: 2026-08-20
Prioridade: BAIXA
Status: Aberto

## Descrição

O mockup de Despesas mostra 3 status possíveis por despesa: "Paga", "Pendente" e "Aguardando" (ex.: linha "Água" no mockup). Hoje `Quota.paid` (`backend/database/migrations/2025_06_12_022708_create_ex_quotas_table.php`) só tem 2 estados (boolean `paid`). Não há definição de negócio do que distingue "Pendente" de "Aguardando" (hipótese: quota já vencida e não paga = Pendente; quota futura ainda não vencida = Aguardando) — precisa de decisão de produto antes de implementar.

## Por que importa

Sem essa distinção, a coluna "Status" das telas novas de Despesas só pode mostrar Paga/Pendente (2 estados), divergindo do mockup. Baixo impacto — é uma nuance de exibição, não uma regra financeira nova.

Tipo sugerido: backend

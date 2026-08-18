# Extrair tipos duplicados para módulo compartilhado

Origem: docs/feature/20260817-migracao-frontend-expo/specify.md §2.5
Criado em: 2026-08-17
Prioridade: BAIXA
Status: Aberto

## Descrição

O tipo `Group` (e outros como `Expense`/`User`) é redefinido de forma independente em pelo menos três arquivos do frontend web (`Dashboard.tsx`, `GroupList.tsx`, `GroupForm.tsx`), sem um módulo de tipos compartilhado.

## Por que importa

Não bloqueia nenhuma tela específica da migração — pode ser feito de forma incremental durante o port de cada tela. Um módulo `types.ts` compartilhado melhora a manutenção e é candidato a ser reaproveitado entre `expense/frontend` e `expense/app`, se a estrutura de pastas permitir importação cruzada (ou via um pacote `shared` num monorepo).

Tipo sugerido: frontend

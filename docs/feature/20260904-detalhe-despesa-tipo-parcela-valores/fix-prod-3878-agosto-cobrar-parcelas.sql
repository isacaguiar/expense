-- ============================================================================
-- Grupo 3878 (Piatã House) — competência de AGOSTO/2026 (01/08 a 31/08).
--
-- Recolocar no acerto de agosto as parcelas do Adestrador (8658) e da Construção
-- parede escritório/demolição stiep (8659), que hoje aparecem como "Paga" mas
-- não cobram nada de ninguém.
--
-- Reverte deliberadamente a decisão de 2026-09-05 registrada em
-- fix-prod-8658-8659-antecipa-mes.sql ("AGOSTO ENTRA COMO QUITADO"). O usuário
-- identificou em 2026-09-06 que a decisão foi errada: o valor entrou antes do
-- fechamento e tem de ser cobrado dos devedores.
--
-- CAUSA: as duas quotas de 04/08 estão com `born_paid = 1`.
-- computeCycleSummary() pula toda entry `bornPaid` ao montar balances/
-- settlements (ExpenseController.php:1199) — é isso, e não o `paid`, que apaga
-- a cobrança. Prova: a Placa Solar do mesmo mês também está "Paga" e continua
-- gerando acerto normalmente.
--
-- ESCOPO: só o acerto de agosto. NÃO fecha a competência (isso é o script
-- fix-prod-3878-fechar-agosto.sql, que só roda depois destes valores serem
-- conferidos na tela) e NÃO toca junho nem julho.
--
-- Banco: expense-api.novemax.com.br (MySQL, ex-db). Execute manualmente, passo a
-- passo — não é script de CI/deploy.
-- ============================================================================


-- ============================================================================
-- 1. CONFERE ANTES — esperado: exatamente 2 linhas.
--
--    8658 Adestrador                     number 4, value_quota  292.40
--    8659 Construção parede escritório…   number 4, value_quota  543.00
--
--    As duas com paid = 1, paid_by = 5573 (naumel67) e born_paid = 1.
--    Se vier qualquer coisa diferente disso, PARE.
-- ============================================================================
SELECT q.id, q.expense_id, e.description, q.number, q.date_expected,
       q.value_quota, q.paid, q.paid_by, q.born_paid
FROM ex_quotas q
JOIN ex_expenses e ON e.id = q.expense_id
WHERE q.expense_id IN (8658, 8659)
  AND q.date_expected = '2026-08-04'
ORDER BY q.expense_id;


-- ============================================================================
-- 2. BACKUP das quotas das duas despesas.
-- ============================================================================
CREATE TABLE IF NOT EXISTS _bkp_ex_quotas_20260906 AS
SELECT * FROM ex_quotas WHERE expense_id IN (8658, 8659);


-- ============================================================================
-- 3. TIRAR O born_paid DAS DUAS PARCELAS DE AGOSTO.
--
--    `paid = 1` e `paid_by = 5573` NÃO mudam: naumel67 pagou mesmo o adestrador
--    e a obra, e `paid` não filtra acerto nenhum. Sai só o born_paid, que é o
--    que apaga a dívida entre os moradores.
--
--    O filtro `born_paid = 1` torna a reexecução um no-op.
-- ============================================================================
START TRANSACTION;

UPDATE ex_quotas
SET born_paid  = 0,
    updated_at = NOW()
WHERE expense_id IN (8658, 8659)
  AND date_expected = '2026-08-04'
  AND born_paid = 1;
-- Esperado: 2 linhas afetadas.

-- Confere depois: as mesmas 2 linhas, agora paid = 1 e born_paid = 0.
SELECT q.id, q.expense_id, e.description, q.number, q.date_expected,
       q.value_quota, q.paid, q.paid_by, q.born_paid
FROM ex_quotas q
JOIN ex_expenses e ON e.id = q.expense_id
WHERE q.expense_id IN (8658, 8659)
  AND q.date_expected = '2026-08-04'
ORDER BY q.expense_id;

COMMIT;


-- ============================================================================
-- 4. CONFERÊNCIA NO APP — abrir o grupo 3878 na competência 01/08–31/08.
--
--    Os cards do topo NÃO mudam (Total R$ 14.004,87 · Pago R$ 4.546,67 ·
--    A pagar R$ 9.458,20): eles medem despesa paga ao fornecedor, não acerto
--    interno. O que muda é a aba "À pagar" e os saldos.
--
--    Cada um dos 5 devedores passa a dever mais R$ 139,23 a naumel67
--    (R$ 48,73 do Adestrador + R$ 90,50 da Construção) — R$ 696,15 no total:
--
--      gabriel        → naumel67   R$ 2.658,66  ->  R$ 2.797,89
--      Isac           → naumel67   R$ 1.976,83  ->  R$ 2.116,06
--      mateus.davi.10 → naumel67   R$ 1.016,12  ->  R$ 1.155,35
--      Natália        → naumel67   R$    16,12  ->  R$   155,35
--      ngaguiar       → naumel67   (sem linha)  ->  R$   139,23   <- linha nova
--
--    Saldos:
--      naumel67        5.592,71 -> 6.288,86 a receber
--      Isac              249,80 ->   110,57 a receber
--      ngaguiar          400,00 ->   539,23 a pagar
--      mateus.davi.10  1.818,93 -> 1.958,16 a pagar
--      Natália           537,10 ->   676,33 a pagar
--      gabriel         3.461,47 -> 3.600,70 a pagar
--      ian.gaguiar / juliagaguiar: inalterados
--
--    Se qualquer valor divergir, PARE e use o rollback do passo 5.
--
--    Observação esperada, não é defeito novo: o painel de dívidas brutas do
--    Dashboard (grossDebts) filtra por `paid` (ExpenseController.php:814), então
--    as duas continuam fora dele enquanto aparecem no acerto. Divergência
--    grossDebts × settlements já registrada como pré-existente em
--    docs/feature/20260904-parcela-retroativa-contabilizacao/specify.md §3.
-- ============================================================================


-- ============================================================================
-- 5. ROLLBACK, se necessário.
--
--   UPDATE ex_quotas q
--     JOIN _bkp_ex_quotas_20260906 b ON b.id = q.id
--     SET q.born_paid = b.born_paid;
--
--   DROP TABLE _bkp_ex_quotas_20260906;   -- só depois de tudo conferido
-- ============================================================================

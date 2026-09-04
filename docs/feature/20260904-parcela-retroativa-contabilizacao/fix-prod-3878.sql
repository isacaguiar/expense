-- Ajuste de dados em produção — grupo 3878 (Piatã House), despesas 8658
-- (Adestrador) e 8659 (Construção parede escritório/demolição stiep).
--
-- Ver docs/feature/20260904-parcela-retroativa-contabilizacao/{specify,plan}.md
-- §2.5 / §3 pelo raciocínio completo.
--
-- PRÉ-REQUISITO: TASK-001 desta feature já em produção (migration
-- `2026_09_04_000000_add_born_paid_to_ex_quotas_table` aplicada e o backend
-- deployado com o filtro por `born_paid` em computeCycleSummary()). Rodar isto
-- antes disso não resolve o Bug 2 e pode selar/notificar julho incorretamente
-- (o guard novo de sealCycleIfSettled() ainda não existiria no ar).
--
-- Banco: expense-api.novemax.com.br (MySQL, ex-db). Execute manualmente,
-- passo a passo — não é script de CI/deploy.

-- ============================================================================
-- 0. Diagnóstico — outras parceladas retroativas na mesma janela (PR #144 →
--    este fix), em QUALQUER grupo. Rode ANTES de decidir o escopo do passo 1.
--    Se aparecer despesa além de 8658/8659, decida no `implementation.md` desta
--    feature se o backfill amplo (WHERE abaixo) ou pontual é o caso.
-- ============================================================================
SELECT q.expense_id, e.group_id, e.description, COUNT(*) AS quotas_pagas
FROM ex_quotas q
JOIN ex_expenses e ON e.id = q.expense_id
WHERE e.expense_type = 'IN_INSTALLMENTS'
  AND q.paid = 1
  AND q.paid_by = e.user_payer_id
  AND q.payment_proof_path IS NULL
  AND ABS(TIMESTAMPDIFF(SECOND, q.created_at, q.paid_at)) <= 120
GROUP BY q.expense_id, e.group_id, e.description;

-- ============================================================================
-- 1. Backup das linhas que este script vai tocar.
-- ============================================================================
CREATE TABLE IF NOT EXISTS _bkp_ex_quotas_20260904 AS
SELECT * FROM ex_quotas WHERE expense_id IN (8658, 8659);

CREATE TABLE IF NOT EXISTS _bkp_ex_group_cycle_snapshots_20260904 AS
SELECT * FROM ex_group_cycle_snapshots
WHERE group_id = 3878 AND cycle_start IN ('2026-07-01', '2026-08-01');

-- ============================================================================
-- 2. Marcar as parcelas de jun/jul de 8658/8659 como retroativas — mata o
--    settlement fantasma de junho (Bug 2). NÃO toca em paid/paid_at/paid_by
--    (já corretos) nem nas parcelas de agosto em diante (dívida real —
--    decisão do usuário: agosto continua sendo cobrado dos devedores).
-- ============================================================================
START TRANSACTION;

-- Confere antes de gravar: espera 4 linhas — 8658 #1,#2 e 8659 #1,#2, todas
-- paid=1, paid_by=5573 (naumel67), born_paid ainda 0.
SELECT id, expense_id, number, date_expected, paid, paid_by, born_paid
FROM ex_quotas
WHERE expense_id IN (8658, 8659) AND paid = 1
ORDER BY expense_id, number;

UPDATE ex_quotas
SET born_paid = 1
WHERE expense_id IN (8658, 8659) AND paid = 1;

-- Confere depois: as mesmas 4 linhas, agora born_paid=1.
SELECT id, expense_id, number, date_expected, paid, paid_by, born_paid
FROM ex_quotas
WHERE expense_id IN (8658, 8659) AND paid = 1
ORDER BY expense_id, number;

COMMIT;

-- ============================================================================
-- 3. Desselar julho — a parcela de julho (já born_paid=1) passa a aparecer
--    como linha "Paga" no resumo daquele mês em vez de ficar invisível na
--    foto congelada.
-- ============================================================================
START TRANSACTION;

SELECT id, group_id, cycle_start, cycle_end, settled_at, closed_manually_at
FROM ex_group_cycle_snapshots
WHERE group_id = 3878 AND cycle_start = '2026-07-01';

UPDATE ex_group_cycle_snapshots
SET settled_at = NULL
WHERE group_id = 3878 AND cycle_start = '2026-07-01';

SELECT id, group_id, cycle_start, cycle_end, settled_at, closed_manually_at
FROM ex_group_cycle_snapshots
WHERE group_id = 3878 AND cycle_start = '2026-07-01';

COMMIT;

-- ============================================================================
-- 4. Desselar agosto — a parcela de agosto (paid=0, born_paid=0 — dívida
--    real) passa a recalcular ao vivo e voltar a ser cobrável; os demais
--    itens de agosto também recalculam ao vivo (a maioria já estava sem
--    SettlementConfirmation; os 2 acertos com comprovante seguem confirmados
--    via ex_settlement_confirmations, que não é tocada aqui).
-- ============================================================================
START TRANSACTION;

SELECT id, group_id, cycle_start, cycle_end, settled_at, closed_manually_at
FROM ex_group_cycle_snapshots
WHERE group_id = 3878 AND cycle_start = '2026-08-01';

UPDATE ex_group_cycle_snapshots
SET settled_at = NULL
WHERE group_id = 3878 AND cycle_start = '2026-08-01';

SELECT id, group_id, cycle_start, cycle_end, settled_at, closed_manually_at
FROM ex_group_cycle_snapshots
WHERE group_id = 3878 AND cycle_start = '2026-08-01';

COMMIT;

-- ============================================================================
-- 5. Verificação pós-script — via API (GET, com token de um membro do grupo
--    3878), não SQL:
--
--   GET /api/groups/3878/expenses/summary?cycles_ago=3   (jun)
--     -> settlements: [], totals.pending: 0, 2 linhas paid:true
--   GET /api/groups/3878/expenses/summary?cycles_ago=2   (jul)
--     -> 2 linhas paid:true, settlements: [], cycle.settled pode voltar a
--        false (não re-selado, sem despesa "real") -- ok
--   GET /api/groups/3878/expenses/summary?cycles_ago=1   (ago)
--     -> as 2 parcelas aparecem paid:false; settlements inclui 139.23 de cada
--        devedor (1, 5574, 5575, 5576, 5577) -> 5573, além dos itens já lá
--   GET /api/groups/3878/expenses/focus-cycle
--     -> aponta pra agosto (cycles_ago=1) -- mês com dívida real mais antigo
--   GET /api/groups/3878/expenses/gross-debts?cycles_ago=3
--     -> creditors: [] -- bate com settlements de jun (ambos sem as parcelas
--        retroativas)
-- ============================================================================

-- ============================================================================
-- 6. Rollback manual, se necessário (usa os backups do passo 1):
--
--   UPDATE ex_quotas q
--     JOIN _bkp_ex_quotas_20260904 b ON b.id = q.id
--     SET q.born_paid = b.born_paid;
--
--   UPDATE ex_group_cycle_snapshots s
--     JOIN _bkp_ex_group_cycle_snapshots_20260904 b ON b.id = s.id
--     SET s.settled_at = b.settled_at;
--
--   DROP TABLE _bkp_ex_quotas_20260904;
--   DROP TABLE _bkp_ex_group_cycle_snapshots_20260904;
-- ============================================================================

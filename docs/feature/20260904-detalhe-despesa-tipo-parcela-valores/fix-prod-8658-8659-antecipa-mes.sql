-- Ajuste de dados em produção — grupo 3878 (Piatã House), despesas 8658
-- (Adestrador, 6x R$ 292,40) e 8659 (Construção parede escritório, 5x R$ 543,00).
--
-- Objetivo: a 1ª parcela das duas despesas foi paga em MAIO, mas elas estão
-- cadastradas começando em junho. Antecipar tudo em um mês — "tirar o último
-- mês e acrescentar um mês antes do primeiro" — mantendo a quantidade de
-- parcelas, o valor de cada uma e o total.
--
--   8658: jun..nov  ->  mai..out   (mai, jun, jul, ago pagas; set, out a pagar)
--   8659: jun..out  ->  mai..set   (mai, jun, jul, ago pagas; set a pagar)
--
-- AGOSTO ENTRA COMO QUITADO (born_paid), a pedido do usuário em 2026-09-05.
-- Isso REVERTE a decisão registrada em
-- docs/feature/20260904-parcela-retroativa-contabilizacao/specify.md §2.5, onde
-- agosto era dívida real: os 5 devedores deixam de dever R$ 139,23 cada
-- (R$ 48,73 do Adestrador + R$ 90,50 da Construção), ou seja R$ 696,15 saem do
-- acerto de agosto. É consequência intencional, não efeito colateral.
--
-- Ver docs/feature/20260904-detalhe-despesa-tipo-parcela-valores/{specify,plan}.md
-- §3.5 / §3 pelo raciocínio completo.
--
-- Banco: expense-api.novemax.com.br (MySQL, ex-db). Execute manualmente, passo a
-- passo — não é script de CI/deploy. Não depende do deploy de TASK-001/002: os
-- campos novos do summary são só de exibição e não mudam o significado de
-- date_expected / paid / born_paid.

-- ============================================================================
-- 0. DIAGNÓSTICO — rode isto ANTES de gravar qualquer coisa.
--
--    Espera-se (conforme o log da feature anterior):
--      - 8658: 6 quotas, number 1..6, date_expected jun..nov/2026;
--              #1 e #2 com paid=1, born_paid=1, paid_by=5573; #3..#6 paid=0
--      - 8659: 5 quotas, number 1..5, date_expected jun..out/2026;
--              #1 e #2 com paid=1, born_paid=1, paid_by=5573; #3..#5 paid=0
--
--    Se o que aparecer for diferente disso, PARE e reavalie — os UPDATEs abaixo
--    assumem exatamente esse estado.
-- ============================================================================
SELECT q.expense_id, q.id, q.number, q.date_expected, q.paid, q.born_paid,
       q.paid_at, q.paid_by, q.value_quota, q.payment_proof_path
FROM ex_quotas q
WHERE q.expense_id IN (8658, 8659)
ORDER BY q.expense_id, q.number;

SELECT id, description, expense_type, installments, total_value,
       date_payment, user_payer_id, group_id, deleted
FROM ex_expenses
WHERE id IN (8658, 8659);

-- Quais competências de mai..set/2026 estão seladas (settled_at preenchido)?
-- Ciclo selado é servido de snapshot congelado — sem desselar, a parcela de maio
-- simplesmente não aparece no app.
SELECT id, group_id, cycle_start, cycle_end, settled_at, closed_manually_at, reopened_at
FROM ex_group_cycle_snapshots
WHERE group_id = 3878
  AND cycle_start BETWEEN '2026-05-01' AND '2026-09-01'
ORDER BY cycle_start;

-- ============================================================================
-- 1. BACKUP das linhas que este script vai tocar.
-- ============================================================================
CREATE TABLE IF NOT EXISTS _bkp_ex_quotas_20260904b AS
SELECT * FROM ex_quotas WHERE expense_id IN (8658, 8659);

CREATE TABLE IF NOT EXISTS _bkp_ex_expenses_20260904b AS
SELECT * FROM ex_expenses WHERE id IN (8658, 8659);

CREATE TABLE IF NOT EXISTS _bkp_ex_group_cycle_snapshots_20260904b AS
SELECT * FROM ex_group_cycle_snapshots
WHERE group_id = 3878 AND cycle_start BETWEEN '2026-05-01' AND '2026-09-01';

-- ============================================================================
-- 2. ANTECIPAR AS PARCELAS EM UM MÊS.
--
--    Deslocar date_expected em -1 mês preserva a numeração 1..N, a quantidade de
--    parcelas e o total — e é equivalente a "tirar o último mês e acrescentar um
--    mês antes do primeiro". `number` NÃO muda: a parcela 1 continua sendo a
--    primeira, agora vencendo em maio.
--
--    date_payment da despesa acompanha, senão a data exibida (e o indexByGroup,
--    que usa mês calendário de date_payment) fica descolada da 1ª parcela.
-- ============================================================================
START TRANSACTION;

-- Confere antes: 11 linhas (6 de 8658 + 5 de 8659), jun..nov e jun..out.
SELECT expense_id, number, date_expected, paid, born_paid
FROM ex_quotas
WHERE expense_id IN (8658, 8659)
ORDER BY expense_id, number;

UPDATE ex_quotas
SET date_expected = DATE_SUB(date_expected, INTERVAL 1 MONTH)
WHERE expense_id IN (8658, 8659);

UPDATE ex_expenses
SET date_payment = DATE_SUB(date_payment, INTERVAL 1 MONTH)
WHERE id IN (8658, 8659);

-- Confere depois: as mesmas 11 linhas, agora mai..out e mai..set.
SELECT expense_id, number, date_expected, paid, born_paid
FROM ex_quotas
WHERE expense_id IN (8658, 8659)
ORDER BY expense_id, number;

SELECT id, description, date_payment FROM ex_expenses WHERE id IN (8658, 8659);

COMMIT;

-- ============================================================================
-- 3. MARCAR AS PARCELAS DE JULHO E AGOSTO COMO QUITADAS RETROATIVAS.
--
--    Depois do passo 2, a parcela que era de agosto virou julho e a que era de
--    setembro virou agosto — as duas em meses já pagos. Precisa de paid=1 E
--    born_paid=1: é o born_paid que tira a parcela de balances/settlements e
--    evita o "settlement fantasma" (regra da feature
--    20260904-parcela-retroativa-contabilizacao). Marcar só paid=1 traria de
--    volta exatamente o bug corrigido lá — e, em agosto, manteria os devedores
--    sendo cobrados, que é justamente o que o usuário não quer.
--
--    O filtro exige paid = 0, então rodar de novo por engano não faz nada.
-- ============================================================================
START TRANSACTION;

-- Confere antes: espera 4 linhas (8658 #3 e #4, 8659 #3 e #4), jul e ago/2026,
-- todas paid=0.
SELECT id, expense_id, number, date_expected, paid, born_paid, paid_by
FROM ex_quotas
WHERE expense_id IN (8658, 8659)
  AND date_expected >= '2026-07-01' AND date_expected < '2026-09-01'
ORDER BY expense_id, number;

UPDATE ex_quotas
SET paid = 1,
    born_paid = 1,
    paid_by = 5573,          -- naumel67, o credor das duas despesas
    paid_at = NOW()
WHERE expense_id IN (8658, 8659)
  AND date_expected >= '2026-07-01' AND date_expected < '2026-09-01'
  AND paid = 0;

-- Confere depois: as 4 linhas com paid=1, born_paid=1, paid_by=5573.
SELECT id, expense_id, number, date_expected, paid, born_paid, paid_by, paid_at
FROM ex_quotas
WHERE expense_id IN (8658, 8659)
  AND date_expected >= '2026-07-01' AND date_expected < '2026-09-01'
ORDER BY expense_id, number;

-- Estado final esperado das 11 parcelas:
--   8658: mai(#1) jun(#2) jul(#3) ago(#4) -> paid=1, born_paid=1
--         set(#5) out(#6)                 -> paid=0, born_paid=0  (a pagar)
--   8659: mai(#1) jun(#2) jul(#3) ago(#4) -> paid=1, born_paid=1
--         set(#5)                         -> paid=0, born_paid=0  (a pagar)
SELECT expense_id, number, date_expected, paid, born_paid, value_quota
FROM ex_quotas
WHERE expense_id IN (8658, 8659)
ORDER BY expense_id, number;

COMMIT;

-- ============================================================================
-- 4. DESSELAR AS COMPETÊNCIAS DE MAI..AGO/2026 QUE O PASSO 0 MOSTROU SELADAS.
--
--    Ciclo selado é servido de snapshot congelado: sem desselar, a parcela de
--    maio não aparece e agosto continua mostrando a cobrança antiga (a foto foi
--    tirada quando a parcela de agosto ainda era dívida real).
--
--    O UPDATE já filtra por `settled_at IS NOT NULL`, então rodar para um mês
--    que não está selado é no-op.
-- ============================================================================
START TRANSACTION;

SELECT id, group_id, cycle_start, settled_at
FROM ex_group_cycle_snapshots
WHERE group_id = 3878
  AND cycle_start IN ('2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01');

UPDATE ex_group_cycle_snapshots
SET settled_at = NULL
WHERE group_id = 3878
  AND cycle_start IN ('2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01')
  AND settled_at IS NOT NULL;

SELECT id, group_id, cycle_start, settled_at
FROM ex_group_cycle_snapshots
WHERE group_id = 3878
  AND cycle_start IN ('2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01');

COMMIT;

-- ============================================================================
-- 5. VERIFICAÇÃO PÓS-SCRIPT — via API (GET, com token de um membro do grupo
--    3878), não SQL. É o comportamento observável que interessa.
--
--    Contando a partir de setembro/2026 como ciclo 0:
--
--    GET /api/groups/3878/expenses/summary?cycles_ago=4   (mai)
--      -> Adestrador e Construção como linha paid:true; settlements sem par
--         referente a elas
--    GET /api/groups/3878/expenses/summary?cycles_ago=3   (jun)
--      -> as duas paid:true
--    GET /api/groups/3878/expenses/summary?cycles_ago=2   (jul)
--      -> as duas paid:true  (antes deste script, julho tinha a #2; agora tem a #3)
--    GET /api/groups/3878/expenses/summary?cycles_ago=1   (ago)
--      -> as duas paid:true e SEM settlement referente a elas. É a mudança de
--         decisão de 2026-09-05: os 5 devedores não devem mais os R$ 139,23 de
--         agosto. Outros itens de agosto seguem no acerto normalmente.
--    GET /api/groups/3878/expenses/summary?cycles_ago=0   (set)
--      -> as duas paid:false — primeira pendência real: 8658 (R$ 292,40) e
--         8659 (R$ 543,00), R$ 139,23 por devedor
--    GET /api/groups/3878/expenses/focus-cycle
--      -> deve apontar para setembro (cycles_ago=0), não mais para agosto
--
--    No app, conferir também que NÃO existe mais parcela em novembro (8658) nem
--    em outubro (8659) — o último mês de cada uma passou a ser out e set.
-- ============================================================================

-- ============================================================================
-- 6. ROLLBACK MANUAL, se necessário (usa os backups do passo 1):
--
--   UPDATE ex_quotas q
--     JOIN _bkp_ex_quotas_20260904b b ON b.id = q.id
--     SET q.date_expected = b.date_expected,
--         q.paid = b.paid, q.born_paid = b.born_paid,
--         q.paid_at = b.paid_at, q.paid_by = b.paid_by;
--
--   UPDATE ex_expenses e
--     JOIN _bkp_ex_expenses_20260904b b ON b.id = e.id
--     SET e.date_payment = b.date_payment;
--
--   UPDATE ex_group_cycle_snapshots s
--     JOIN _bkp_ex_group_cycle_snapshots_20260904b b ON b.id = s.id
--     SET s.settled_at = b.settled_at;
--
--   DROP TABLE _bkp_ex_quotas_20260904b;
--   DROP TABLE _bkp_ex_expenses_20260904b;
--   DROP TABLE _bkp_ex_group_cycle_snapshots_20260904b;
-- ============================================================================

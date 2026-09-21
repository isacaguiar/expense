-- ============================================================================
-- Grupo 3878 (Piatã House) — competência de AGOSTO/2026.
--
-- Duas coisas neste arquivo:
--   PARTE 1 (passos 0..3)  — SOMENTE LEITURA. Diagnóstico: qual é de fato a
--                            janela de agosto, o que cai nela, e quanto cada
--                            devedor deve a cada credor (o mesmo cálculo que
--                            ExpenseController::computeCycleSummary faz em PHP,
--                            reescrito em SQL). É isto que responde
--                            "recalcule os valores a serem pagos".
--   PARTE 2 (passos 4..10) — ESCRITA. Fecha agosto por completo: marca toda
--                            quota da competência como paga (lado do CREDOR) e
--                            grava a confirmação de acerto de cada par
--                            devedor→credor (lado do PAGADOR). Com os dois
--                            lados fechados, o backend sela a competência
--                            sozinho na próxima leitura.
--
-- Banco: expense-api.novemax.com.br (MySQL, ex-db). Execute manualmente, passo
-- a passo, conferindo o resultado de cada SELECT — não é script de CI/deploy.
--
-- ---------------------------------------------------------------------------
-- PRÉ-REQUISITO (2026-09-06): rode ANTES o `fix-prod-3878-agosto-cobrar-parcelas.sql`
-- e confira os valores na tela. Ele devolve ao acerto de agosto os R$ 696,15 do
-- Adestrador e da Construção, que a TASK-003 tinha marcado `born_paid`. Fechar
-- agosto antes disso congela a competência sem esses valores — e ciclo selado passa
-- a ser servido de foto imutável, o que torna a correção bem mais cara.
--
-- ---------------------------------------------------------------------------
-- ANTES DE RODAR A PARTE 2, LEIA ISTO
--
-- A janela de agosto depende de `ex_groups.closing_day` (passo 0):
--
--   closing_day = NULL  -> competência = mês calendário: 2026-08-01 a 2026-08-31.
--                          O aviso "este ciclo fecha em 05/09" que o app mostra
--                          NÃO é a fronteira do ciclo: é a carência de 5 dias
--                          (BillingCycle::GRACE_DAYS) em que agosto continua
--                          editável depois de 31/08. Uma parcela de 04/09 cai
--                          em SETEMBRO, por definição.
--   closing_day = 5     -> competência = 2026-08-06 a 2026-09-05 (estilo fatura
--                          de cartão). Aí sim uma parcela de 04/09 é "agosto".
--
-- Ajuste @cycle_start / @cycle_end abaixo conforme o passo 0 mostrar. TODO o
-- resto do script é dirigido por dado — não há valor nem id de usuário chumbado.
-- ============================================================================

-- ATENÇÃO ao rodar em pedaços no phpMyAdmin: variáveis @ vivem na CONEXÃO, e o
-- phpMyAdmin costuma abrir conexão nova a cada envio. REPITA estes três SET no
-- topo de CADA bloco que você enviar — passos 1, 2, 3, 5, 6 e 8 usam as três.
-- Se elas vierem NULL, nada dá erro: os filtros só não casam e o resultado vem
-- vazio (o passo 1 monta uma _fech_entries em branco, e o resto segue em falso).
-- Conferência barata a qualquer momento:
--   SELECT @group_id, @cycle_start, @cycle_end;   -- 3878, 2026-08-01, 2026-08-31
SET @group_id    = 3878;
SET @cycle_start = '2026-08-01';   -- ver passo 0 antes de aceitar este valor
SET @cycle_end   = '2026-08-31';   -- idem


-- ============================================================================
-- 0. CONFIGURAÇÃO DO GRUPO — decide a janela. RODE PRIMEIRO.
-- ============================================================================
SELECT id, name, closing_day, deleted
FROM ex_groups
WHERE id = @group_id;

-- Membros do grupo (quem pode aparecer em balances/settlements).
SELECT u.id, u.name
FROM ex_groups_members m
JOIN ex_users u ON u.id = m.user_id
WHERE m.group_id = @group_id
ORDER BY u.id;

-- Snapshot da competência: se `settled_at` já estiver preenchido, agosto já
-- está selado e o app serve a foto congelada — não há o que fechar.
SELECT id, group_id, cycle_start, cycle_end, settled_at, closed_manually_at, reopened_at
FROM ex_group_cycle_snapshots
WHERE group_id = @group_id
  AND cycle_start BETWEEN '2026-07-01' AND '2026-09-30'
ORDER BY cycle_start;


-- ============================================================================
-- 1. MONTA AS ENTRADAS DA COMPETÊNCIA (_fech_entries).
--
--    Réplica fiel de ExpenseController::collectCycleEntries(): três origens
--    distintas, cada uma com a sua regra de data.
--      'direct'      -> despesa não-parcelada com date_payment na janela
--      'installment' -> parcela (ex_quotas.date_expected) na janela
--      'fixed_proj'  -> despesa FIXA anterior à janela, projetada mês a mês
--
--    Tabela real (não TEMPORARY) de propósito: o phpMyAdmin abre conexão nova a
--    cada execução e uma temporária sumiria entre os passos. Dropada no passo 9.
-- ============================================================================
-- Repetidos de propósito: se este bloco for enviado sozinho no phpMyAdmin, a
-- conexão é nova e as variáveis do topo do arquivo não existem mais.
SET @group_id    = 3878;
SET @cycle_start = '2026-08-01';
SET @cycle_end   = '2026-08-31';

DROP TABLE IF EXISTS _fech_entries;
CREATE TABLE _fech_entries (
  expense_id    BIGINT UNSIGNED NOT NULL,
  quota_id      BIGINT UNSIGNED NULL,
  description   VARCHAR(255)    NULL,
  expense_type  VARCHAR(50)     NULL,
  entry_date    DATE            NOT NULL,
  value         DECIMAL(38,2)   NOT NULL,
  paid          TINYINT(1)      NOT NULL,
  born_paid     TINYINT(1)      NOT NULL,
  user_payer_id BIGINT UNSIGNED NULL,
  origin        VARCHAR(16)     NOT NULL
);

-- (a) diretas — IN_CASH e FIXED criadas dentro da janela
INSERT INTO _fech_entries
SELECT e.id, q.id, e.description, e.expense_type, e.date_payment,
       COALESCE(q.value_quota, e.total_value),
       COALESCE(q.paid, 0), COALESCE(q.born_paid, 0), e.user_payer_id, 'direct'
FROM ex_expenses e
LEFT JOIN ex_quotas q
       ON q.expense_id = e.id
      AND q.date_expected BETWEEN @cycle_start AND @cycle_end
WHERE e.group_id = @group_id
  AND e.deleted = 0
  AND e.expense_type <> 'IN_INSTALLMENTS'
  AND e.date_payment BETWEEN @cycle_start AND @cycle_end
  AND (e.expense_type <> 'FIXED'
       OR e.fixed_recurrence_ends_at IS NULL
       OR e.fixed_recurrence_ends_at > DATE_FORMAT(@cycle_start, '%Y-%m-01'));

-- (b) parcelas de parceladas cujo vencimento cai na janela
INSERT INTO _fech_entries
SELECT e.id, q.id, e.description, e.expense_type, q.date_expected,
       q.value_quota, q.paid, q.born_paid, e.user_payer_id, 'installment'
FROM ex_quotas q
JOIN ex_expenses e ON e.id = q.expense_id
WHERE e.group_id = @group_id
  AND e.deleted = 0
  AND e.expense_type = 'IN_INSTALLMENTS'
  AND q.date_expected BETWEEN @cycle_start AND @cycle_end;

-- (c) fixas anteriores à janela, projetadas — dia = min(dia original, último
--     dia do mês). Dois meses-âncora cobrem tanto a janela calendário (um mês
--     só; o UNION deduplica) quanto a janela que atravessa a virada do mês.
INSERT INTO _fech_entries
SELECT x.id, q.id, x.description, x.expense_type, x.entry_date,
       COALESCE(q.value_quota, x.total_value),
       COALESCE(q.paid, 0), COALESCE(q.born_paid, 0), x.user_payer_id, 'fixed_proj'
FROM (
  SELECT e.id, e.description, e.expense_type, e.total_value, e.user_payer_id,
         e.fixed_recurrence_ends_at,
         DATE_ADD(m.anchor,
                  INTERVAL LEAST(DAY(e.date_payment), DAY(LAST_DAY(m.anchor))) - 1 DAY) AS entry_date
  FROM ex_expenses e
  CROSS JOIN (
      SELECT DATE_FORMAT(@cycle_start, '%Y-%m-01') AS anchor
      UNION
      SELECT DATE_FORMAT(@cycle_end,   '%Y-%m-01')
  ) m
  WHERE e.group_id = @group_id
    AND e.deleted = 0
    AND e.expense_type = 'FIXED'
    AND e.date_payment < @cycle_start
    AND (e.fixed_recurrence_ends_at IS NULL OR e.fixed_recurrence_ends_at > @cycle_start)
) x
LEFT JOIN ex_quotas q ON q.expense_id = x.id AND q.date_expected = x.entry_date
WHERE x.entry_date BETWEEN @cycle_start AND @cycle_end
  AND (x.fixed_recurrence_ends_at IS NULL OR x.fixed_recurrence_ends_at > x.entry_date);


-- PARE E CONFIRA ANTES DE SEGUIR: tem de vir 10. Se vier 0, este bloco rodou sem
-- as variáveis e a tabela ficou vazia — reenvie o passo 1 inteiro de uma vez.
SELECT COUNT(*) AS entradas FROM _fech_entries;


-- ============================================================================
-- 2. O QUE CAI EM AGOSTO — e os totais que o app mostra.
-- ============================================================================
SELECT d.origin, d.expense_id, d.quota_id, d.description, d.expense_type,
       d.entry_date, d.value,
       ROUND(d.value / GREATEST(c.n, 1), 2) AS valor_por_pessoa,
       c.n AS participantes,
       d.paid, d.born_paid,
       u.name AS credor
FROM _fech_entries d
LEFT JOIN ex_users u ON u.id = d.user_payer_id
LEFT JOIN (SELECT expense_id, COUNT(*) AS n FROM ex_expenses_payers GROUP BY expense_id) c
       ON c.expense_id = d.expense_id
ORDER BY d.paid, d.entry_date, d.expense_id;

-- totals.total / totals.paid / totals.pending, como o summary devolve.
SELECT ROUND(SUM(value), 2)                                    AS total,
       ROUND(SUM(CASE WHEN paid = 1 THEN value ELSE 0 END), 2) AS pago,
       ROUND(SUM(CASE WHEN paid = 0 THEN value ELSE 0 END), 2) AS pendente
FROM _fech_entries;

-- Entradas sem quota (bloqueiam o fechamento: sem linha em ex_quotas não há
-- onde gravar `paid`). Ocorrência FIXA ainda não materializada é o caso normal
-- e o passo 5 resolve. Qualquer outra coisa aqui = PARE e investigue.
SELECT * FROM _fech_entries WHERE quota_id IS NULL;


-- ============================================================================
-- 3. QUEM DEVE QUANTO A QUEM (os "valores a serem pagos").
--
--    Igual ao PHP: cada participante que não é o credor deve value/nº de
--    participantes; parcela `born_paid` (retroativa nascida quitada) NÃO entra;
--    `paid` NÃO filtra nada aqui — despesa paga pelo credor continua no acerto
--    até o devedor confirmar. Depois cada par é netado nos dois sentidos, e o
--    arredondamento só acontece no fim (mesma ordem do PHP).
-- ============================================================================
-- Repetidos de propósito: se este bloco for enviado sozinho no phpMyAdmin, a
-- conexão é nova e as variáveis do topo do arquivo não existem mais.
SET @group_id    = 3878;
SET @cycle_start = '2026-08-01';
SET @cycle_end   = '2026-08-31';

DROP TABLE IF EXISTS _fech_owed;
CREATE TABLE _fech_owed AS
SELECT e.user_payer_id AS creditor_id,
       p.user_id       AS debtor_id,
       SUM(d.value / c.n) AS amount
FROM _fech_entries d
JOIN ex_expenses e        ON e.id = d.expense_id
JOIN ex_expenses_payers p ON p.expense_id = d.expense_id
JOIN (SELECT expense_id, COUNT(*) AS n FROM ex_expenses_payers GROUP BY expense_id) c
     ON c.expense_id = d.expense_id
WHERE d.born_paid = 0
  AND p.user_id <> e.user_payer_id
GROUP BY e.user_payer_id, p.user_id;

DROP TABLE IF EXISTS _fech_settlements;
CREATE TABLE _fech_settlements AS
SELECT CASE WHEN net > 0 THEN u2 ELSE u1 END AS from_user_id,
       CASE WHEN net > 0 THEN u1 ELSE u2 END AS to_user_id,
       ABS(net)                              AS amount
FROM (
  SELECT pares.u1, pares.u2,
         ROUND(COALESCE(a.amount, 0) - COALESCE(b.amount, 0), 2) AS net
  FROM (
      SELECT DISTINCT LEAST(creditor_id, debtor_id)    AS u1,
                      GREATEST(creditor_id, debtor_id) AS u2
      FROM _fech_owed
  ) pares
  LEFT JOIN _fech_owed a ON a.creditor_id = pares.u1 AND a.debtor_id = pares.u2
  LEFT JOIN _fech_owed b ON b.creditor_id = pares.u2 AND b.debtor_id = pares.u1
) netado
WHERE net <> 0;

-- >>> ESTE É O RESULTADO DO "RECALCULE OS VALORES A SEREM PAGOS". <<<
-- Confira contra a tela de Pagamentos do app na competência de agosto: os pares
-- e os valores têm de bater linha a linha.
SELECT s.from_user_id, ud.name AS devedor,
       s.to_user_id,   uc.name AS credor,
       s.amount,
       CASE WHEN sc.id IS NULL THEN 'em aberto' ELSE 'já confirmado' END AS situacao
FROM _fech_settlements s
JOIN ex_users ud ON ud.id = s.from_user_id
JOIN ex_users uc ON uc.id = s.to_user_id
LEFT JOIN ex_settlement_confirmations sc
       ON sc.group_id     = @group_id
      AND sc.cycle_start  = @cycle_start
      AND sc.from_user_id = s.from_user_id
      AND sc.to_user_id   = s.to_user_id
ORDER BY s.amount DESC;

-- Confirmações de acerto já existentes nesta competência (com comprovante de
-- verdade) — o passo 8 não sobrescreve nenhuma delas.
SELECT id, from_user_id, to_user_id, amount, proof_path, confirmed_at
FROM ex_settlement_confirmations
WHERE group_id = @group_id AND cycle_start = @cycle_start;


-- ############################################################################
-- ###   FIM DA PARTE SOMENTE LEITURA. Daqui para baixo o script GRAVA.     ###
-- ############################################################################


-- ============================================================================
-- 4. BACKUP do que a Parte 2 vai tocar.
--
--    Nome propositalmente diferente do backup do script anterior
--    (`_bkp_ex_quotas_20260906`, só com as quotas de 8658/8659): com
--    `IF NOT EXISTS`, reaproveitar aquele nome faria este CREATE virar um no-op
--    silencioso e as quotas que o passo 6 altera ficariam sem cópia.
-- ============================================================================
CREATE TABLE IF NOT EXISTS _bkp_ex_quotas_fechamento_20260906 AS
SELECT q.* FROM ex_quotas q
JOIN ex_expenses e ON e.id = q.expense_id
WHERE e.group_id = @group_id;

CREATE TABLE IF NOT EXISTS _bkp_ex_settlement_confirmations_20260906 AS
SELECT * FROM ex_settlement_confirmations
WHERE group_id = @group_id AND cycle_start = @cycle_start;

CREATE TABLE IF NOT EXISTS _bkp_ex_group_cycle_snapshots_20260906 AS
SELECT * FROM ex_group_cycle_snapshots
WHERE group_id = @group_id AND cycle_start = @cycle_start;


-- ============================================================================
-- 5. MATERIALIZAR AS OCORRÊNCIAS FIXAS SEM QUOTA.
--
--    Uma despesa FIXA projetada só vira linha em ex_quotas quando alguém fecha
--    a competência ou paga (materializeFixedOccurrenceQuota). Enquanto não tem
--    quota ela conta como NÃO PAGA e trava a selagem — por isso vem antes do
--    passo 6. Mesmos campos que o PHP grava: number = 1, paid = 0,
--    value_quota = total_value vigente.
--
--    Se o passo 2 mostrou a lista de "sem quota" vazia, este passo é no-op.
-- ============================================================================
-- Repetidos de propósito: se este bloco for enviado sozinho no phpMyAdmin, a
-- conexão é nova e as variáveis do topo do arquivo não existem mais.
SET @group_id    = 3878;
SET @cycle_start = '2026-08-01';
SET @cycle_end   = '2026-08-31';

START TRANSACTION;

INSERT INTO ex_quotas (date_expected, number, paid, born_paid, value_quota, expense_id, created_at, updated_at)
SELECT d.entry_date, 1, 0, 0, d.value, d.expense_id, NOW(), NOW()
FROM _fech_entries d
WHERE d.origin = 'fixed_proj'
  AND d.quota_id IS NULL;

COMMIT;

-- Confere: toda entrada da competência tem agora uma quota de verdade. Lê o banco
-- direto, NÃO a `_fech_entries` — aquela tabela guarda o `quota_id` de antes deste
-- INSERT e continuaria mostrando NULL. Deve vir vazio.
SELECT d.expense_id, d.description, d.entry_date, d.origin
FROM _fech_entries d
LEFT JOIN ex_quotas q
       ON q.expense_id = d.expense_id
      AND q.date_expected = d.entry_date
WHERE q.id IS NULL;


-- ============================================================================
-- 6. LADO DO CREDOR — marcar como paga toda quota da competência.
--
--    paid = 1 com paid_by = credor da despesa é exatamente o que a rota
--    `pay()` grava quando o credor confirma que recebeu.
--
--    NÃO grava born_paid: born_paid tiraria a despesa de balances/settlements e
--    apagaria o registro de quem devia a quem. Aqui o objetivo é o oposto —
--    manter o acerto visível e marcá-lo como quitado no passo 8.
--
--    O filtro `paid = 0` faz reexecução acidental virar no-op.
-- ============================================================================
-- Repetidos de propósito: se este bloco for enviado sozinho no phpMyAdmin, a
-- conexão é nova e as variáveis do topo do arquivo não existem mais.
SET @group_id    = 3878;
SET @cycle_start = '2026-08-01';
SET @cycle_end   = '2026-08-31';

START TRANSACTION;

-- Antes: quantas ainda estão em aberto e somando quanto.
SELECT COUNT(*) AS quotas_em_aberto, ROUND(SUM(value), 2) AS valor_em_aberto
FROM _fech_entries WHERE paid = 0;

-- O filtro é por (despesa da competência) + (vencimento dentro da janela), e NÃO
-- por `_fech_entries.quota_id`: as quotas que o passo 5 acabou de criar não têm id
-- em `_fech_entries`, e um join por quota_id deixaria justamente as despesas FIXAS
-- sem marcar — a competência nunca fecharia.
UPDATE ex_quotas q
JOIN ex_expenses e ON e.id = q.expense_id
SET q.paid       = 1,
    q.paid_by    = e.user_payer_id,
    q.paid_at    = NOW(),
    q.updated_at = NOW()
WHERE q.expense_id IN (SELECT expense_id FROM _fech_entries)
  AND q.date_expected BETWEEN @cycle_start AND @cycle_end
  AND q.paid = 0;

-- Depois: tem de vir 0 linhas.
SELECT q.id, q.expense_id, q.date_expected, q.paid
FROM ex_quotas q
WHERE q.expense_id IN (SELECT expense_id FROM _fech_entries)
  AND q.date_expected BETWEEN @cycle_start AND @cycle_end
  AND q.paid = 0;

COMMIT;


-- ============================================================================
-- 7. AS PARCELAS DE 04/09 — NÃO EXECUTAR NADA AQUI. Decisão de 2026-09-06.
--
--    As parcelas #5 de 8658 (R$ 292,40) e 8659 (R$ 543,00) vencem em 2026-09-04.
--    Com closing_day = NULL elas pertencem a SETEMBRO, e a decisão do usuário é
--    que **continuam devidas em setembro**: R$ 835,40 no total, R$ 139,23 por
--    devedor, para serem acertadas no fechamento do próximo mês.
--
--    Este passo existe só para registrar que a alternativa foi considerada e
--    recusada. Não há SQL a rodar. Se um dia a decisão mudar, o que se faz é
--    marcar essas duas quotas como pagas na competência de SETEMBRO — nunca
--    mudar `date_expected` para agosto, o que criaria duas parcelas da mesma
--    despesa no mesmo ciclo.
--
--    Pelo mesmo motivo, `closing_day` do grupo NÃO muda: o usuário confirmou que
--    o descompasso foi pontual de agosto e que os demais meses estão corretos.
-- ============================================================================


-- ============================================================================
-- 8. LADO DO PAGADOR — confirmar todo acerto em aberto da competência.
--
--    ex_settlement_confirmations é o registro de "o devedor pagou o credor".
--    Sem uma linha por par, cycleIsFullySettled() devolve false e a competência
--    nunca sela, mesmo com todas as despesas marcadas como pagas.
--
--    proof_path = '' porque a coluna é NOT NULL e aqui não há comprovante: o
--    accessor proof_url trata string vazia como "sem comprovante" e o app
--    simplesmente não mostra o chip de comprovante. INSERT IGNORE preserva
--    intactas as confirmações reais que já existirem (chave única
--    group_id + cycle_start + from_user_id + to_user_id).
-- ============================================================================
-- Repetidos de propósito: se este bloco for enviado sozinho no phpMyAdmin, a
-- conexão é nova e as variáveis do topo do arquivo não existem mais.
SET @group_id    = 3878;
SET @cycle_start = '2026-08-01';
SET @cycle_end   = '2026-08-31';

START TRANSACTION;

INSERT IGNORE INTO ex_settlement_confirmations
  (group_id, cycle_start, cycle_end, from_user_id, to_user_id, amount,
   proof_path, confirmed_at, created_at, updated_at)
SELECT @group_id, @cycle_start, @cycle_end, s.from_user_id, s.to_user_id, s.amount,
       '', NOW(), NOW(), NOW()
FROM _fech_settlements s;

-- Depois: uma linha por par do passo 3, nenhuma faltando.
SELECT sc.from_user_id, ud.name AS devedor, sc.to_user_id, uc.name AS credor,
       sc.amount, sc.proof_path, sc.confirmed_at
FROM ex_settlement_confirmations sc
JOIN ex_users ud ON ud.id = sc.from_user_id
JOIN ex_users uc ON uc.id = sc.to_user_id
WHERE sc.group_id = @group_id AND sc.cycle_start = @cycle_start
ORDER BY sc.amount DESC;

COMMIT;


-- ============================================================================
-- 9. SELAGEM E VERIFICAÇÃO — pela aplicação, não por SQL.
--
--    NÃO preencha ex_group_cycle_snapshots.settled_at na mão: o snapshot guarda
--    as fotos JSON de totals/expenses/balances/settlements, e um settled_at sem
--    essas fotos serve uma competência vazia para todo mundo.
--
--    Basta abrir o grupo no app na competência de agosto (ou chamar
--    GET /api/groups/3878/expenses/summary?cycles_ago=1). Agosto está `closed`
--    (fronteira 31/08 + carência de 5 dias = 05/09, já passou), então summary()
--    recalcula ao vivo, vê pendente = 0 e todo par confirmado, e chama
--    sealCycleIfSettled() — que grava as fotos e o settled_at.
--
--    Isso dispara Notifier::cycleSettled: todos os membros recebem a notificação
--    de competência encerrada. É esperado, não é efeito colateral.
--
--    Confira no app:
--      GET .../expenses/summary?cycles_ago=1
--        -> cycle.settled = true, totals.pending = 0, toda despesa paid:true
--      GET .../expenses/focus-cycle
--        -> não aponta mais para agosto
--
--    Limpeza das tabelas de trabalho (os _bkp_* do passo 4 FICAM):
--      DROP TABLE IF EXISTS _fech_entries;
--      DROP TABLE IF EXISTS _fech_owed;
--      DROP TABLE IF EXISTS _fech_settlements;
-- ============================================================================


-- ============================================================================
-- 10. ROLLBACK MANUAL (usa os backups do passo 4).
--
--   UPDATE ex_quotas q
--     JOIN _bkp_ex_quotas_fechamento_20260906 b ON b.id = q.id
--     SET q.paid = b.paid, q.paid_at = b.paid_at, q.paid_by = b.paid_by,
--         q.born_paid = b.born_paid;
--
--   -- quotas de FIXA criadas pelo passo 5 (não existem no backup):
--   DELETE q FROM ex_quotas q
--     LEFT JOIN _bkp_ex_quotas_fechamento_20260906 b ON b.id = q.id
--     JOIN ex_expenses e ON e.id = q.expense_id
--    WHERE e.group_id = 3878 AND b.id IS NULL;
--
--   -- confirmações criadas pelo passo 8 (as reais têm proof_path preenchido):
--   DELETE FROM ex_settlement_confirmations
--    WHERE group_id = 3878 AND cycle_start = '2026-08-01' AND proof_path = '';
--
--   -- desfaz a selagem, se o app já tiver selado:
--   UPDATE ex_group_cycle_snapshots
--      SET settled_at = NULL
--    WHERE group_id = 3878 AND cycle_start = '2026-08-01';
--
--   DROP TABLE _bkp_ex_quotas_fechamento_20260906;
--   DROP TABLE _bkp_ex_settlement_confirmations_20260906;
--   DROP TABLE _bkp_ex_group_cycle_snapshots_20260906;
-- ============================================================================

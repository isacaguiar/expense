-- ============================================================================
-- Limpeza de tabelas auxiliares e legadas — banco de produção (ex-db /
-- isacag00_expense).
--
-- Duas coisas diferentes aqui:
--   a) as tabelas de trabalho e de backup criadas pelos scripts das TASK-003 a
--      TASK-005 desta feature (`_fech_*`, `_bkp_*`);
--   b) quatro tabelas LEGADAS no singular (`ex_expense`, `ex_expense_payers`,
--      `ex_group`, `ex_user`) que vieram no import original de 2026-08-29 e não
--      existem em nenhuma migration nem em nenhum ponto do código.
--
-- `DROP TABLE` é irreversível e não tem rollback. Rode os passos 1 a 3 (leitura)
-- e só execute o passo 4 se todos os três vierem como esperado.
--
-- Execute manualmente, passo a passo — não é script de CI/deploy.
-- ============================================================================


-- ============================================================================
-- 1. CONTAGEM EXATA das quatro candidatas legadas.
--
--    NÃO decida por `information_schema.TABLES.TABLE_ROWS`: em InnoDB aquele
--    número é uma ESTIMATIVA amostrada, que pode vir 0 para tabela com linhas
--    (e vice-versa). É a coluna que aparece na tela de estrutura do phpMyAdmin.
--    Só `COUNT(*)` decide.
--
--    Esperado: 0 nas quatro. Se qualquer uma vier > 0, PARE — a tabela tem
--    conteúdo e precisa ser olhada antes de qualquer coisa.
-- ============================================================================
SELECT 'ex_expense'        AS tabela, COUNT(*) AS linhas FROM ex_expense
UNION ALL
SELECT 'ex_expense_payers',          COUNT(*) FROM ex_expense_payers
UNION ALL
SELECT 'ex_group',                   COUNT(*) FROM ex_group
UNION ALL
SELECT 'ex_user',                    COUNT(*) FROM ex_user;


-- ============================================================================
-- 2. FOREIGN KEYS — classificadas.
--
--    `DROP TABLE` já apaga as constraints da PRÓPRIA tabela; não é preciso
--    removê-las antes. O que atrapalha é só isto:
--
--    'interna'        -> FK entre duas das quatro legadas. Some junto; é só
--                        questão de ORDEM (a filha cai primeiro), resolvida no
--                        passo 4.
--    'legada -> viva' -> a legada é filha de uma tabela do sistema. Some junto
--                        com ela, sem tocar na tabela viva. Inofensivo.
--    'VIVA -> LEGADA' -> uma tabela do sistema depende da legada. Aqui o DROP
--                        falha (errno 3730) — e o certo NÃO é apagar a FK, é
--                        PARAR: a tabela não é órfã e alguém precisa entender
--                        por que o sistema aponta para ela.
-- ============================================================================
SELECT kcu.CONSTRAINT_NAME,
       kcu.TABLE_NAME            AS tabela_filha,
       kcu.COLUMN_NAME,
       kcu.REFERENCED_TABLE_NAME AS tabela_pai,
       CASE
         WHEN kcu.TABLE_NAME IN ('ex_expense', 'ex_expense_payers', 'ex_group', 'ex_user')
          AND kcu.REFERENCED_TABLE_NAME IN ('ex_expense', 'ex_expense_payers', 'ex_group', 'ex_user')
              THEN 'interna'
         WHEN kcu.TABLE_NAME IN ('ex_expense', 'ex_expense_payers', 'ex_group', 'ex_user')
              THEN 'legada -> viva'
         ELSE 'VIVA -> LEGADA (PARE)'
       END AS situacao
FROM information_schema.KEY_COLUMN_USAGE kcu
WHERE kcu.TABLE_SCHEMA = DATABASE()
  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
  AND (kcu.REFERENCED_TABLE_NAME IN ('ex_expense', 'ex_expense_payers', 'ex_group', 'ex_user')
       OR kcu.TABLE_NAME         IN ('ex_expense', 'ex_expense_payers', 'ex_group', 'ex_user'))
ORDER BY situacao DESC;


-- ============================================================================
-- 3. GUARDE A ESTRUTURA antes de apagar.
--
--    Rode os quatro e salve a saída junto com esta feature. Custa nada e é a
--    única coisa que sobra se um dia alguém perguntar o que havia ali.
--    (Alternativa equivalente: Exportar as 4 tabelas pelo phpMyAdmin.)
-- ============================================================================
SHOW CREATE TABLE ex_expense;
SHOW CREATE TABLE ex_expense_payers;
SHOW CREATE TABLE ex_group;
SHOW CREATE TABLE ex_user;


-- ============================================================================
-- 4. DROP das quatro legadas — só depois de 1, 2 e 3 conferidos, e só se o
--    passo 2 NÃO tiver devolvido nenhuma linha 'VIVA -> LEGADA (PARE)'.
--
--    Não é preciso apagar FK nenhuma antes: o DROP leva junto as constraints de
--    cada tabela. O único problema seria a ordem entre as quatro (uma referencia
--    a outra), e `FOREIGN_KEY_CHECKS = 0` resolve isso sem depender de adivinhar
--    a ordem certa. O escopo é a SESSÃO — nenhuma outra conexão é afetada, e o
--    passo seguinte religa.
--
--    Só desligue a checagem para este bloco. Deixá-la desligada em produção é
--    como permitir dado órfão silenciosamente.
-- ============================================================================
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS ex_expense_payers;   -- filha primeiro, por clareza
DROP TABLE IF EXISTS ex_expense;
DROP TABLE IF EXISTS ex_group;
DROP TABLE IF EXISTS ex_user;

SET FOREIGN_KEY_CHECKS = 1;

-- Confere depois: nenhuma constraint pode ter sobrado apontando para as quatro
-- (uma FK órfã dessas quebraria escrita na tabela viva que a mantém).
-- Esperado: nenhuma linha.
SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IN ('ex_expense', 'ex_expense_payers', 'ex_group', 'ex_user');

-- E que as quatro sumiram mesmo. Esperado: nenhuma linha.
SELECT TABLE_NAME FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('ex_expense', 'ex_expense_payers', 'ex_group', 'ex_user');


-- ============================================================================
-- 5. BACKUPS DAS TASKS JÁ CONCLUÍDAS (2026-09-04 / 05).
--
--    São o estado das quotas de 8658/8659 ANTES do deslocamento de um mês da
--    TASK-003. Agosto já está selado, então reverter aquilo hoje seria caro de
--    qualquer forma — mas exporte antes de apagar (phpMyAdmin > Exportar, dois
--    cliques). São 16 KB cada; se preferir, é perfeitamente razoável só deixar.
-- ============================================================================
-- DROP TABLE IF EXISTS _bkp_ex_quotas_20260904;
-- DROP TABLE IF EXISTS _bkp_ex_group_cycle_snapshots_20260904;
-- DROP TABLE IF EXISTS _bkp_ex_quotas_20260904b;
-- DROP TABLE IF EXISTS _bkp_ex_expenses_20260904b;


-- ============================================================================
-- 6. NÃO APAGAR — os `_bkp_*_20260906` são o rollback do fechamento de agosto,
--    que rodou hoje. Só depois de o grupo confirmar que os valores do mês estão
--    certos:
--      _bkp_ex_quotas_fechamento_20260906
--      _bkp_ex_settlement_confirmations_20260906
--      _bkp_ex_group_cycle_snapshots_20260906
--
--    E NUNCA apagar estas, mesmo vazias — vazio não é sinal de inútil:
--
--    | Tabela                     | Por quê                                    |
--    |----------------------------|--------------------------------------------|
--    | ex_participations          | GroupController::destroy() faz              |
--    |                            | $group->participations()->delete() ao        |
--    |                            | excluir grupo — o DROP quebraria a exclusão |
--    | ex_failed_jobs             | infra do Laravel (fila); vazia é o normal   |
--    | ex_password_reset_tokens   | idem (recuperação de senha) — hoje tem 9    |
--    | ex_personal_access_tokens  | idem (Sanctum)                              |
--    | ex_notifications           | Notifier grava aqui                          |
--    | migrations                 | controle de schema do Laravel                |
--
--    As 13 tabelas que o sistema realmente usa são exatamente as criadas por
--    migration: ex_expenses, ex_expenses_payers, ex_failed_jobs,
--    ex_group_cycle_snapshots, ex_groups, ex_groups_members, ex_notifications,
--    ex_participations, ex_password_reset_tokens, ex_personal_access_tokens,
--    ex_quotas, ex_settlement_confirmations, ex_users — mais `migrations`.
-- ============================================================================


-- ============================================================================
-- RESULTADO DA EXECUÇÃO — 2026-09-06, pelo usuário.
--
-- Executado: as três tabelas de trabalho `_fech_*` (passo 9 do script de
-- fechamento) e, depois, o passo 4 deste arquivo — `ex_expense`,
-- `ex_expense_payers`, `ex_group` e `ex_user` apagadas. O banco saiu de 28 para
-- 21 tabelas: as 13 do sistema, `migrations`, os quatro `_bkp_*` de 04–05/09 e
-- os três `_bkp_*_20260906` do fechamento de agosto.
--
-- CONFERIDO PELA IA depois do drop:
--   - o app continua íntegro na leitura: a Home do grupo 3878 carrega a
--     competência de setembro com despesas, saldos e notificações;
--   - nenhuma tabela viva podia depender das apagadas — todas as foreign keys
--     declaradas nas migrations miram só ex_users, ex_groups, ex_expenses e
--     ex_quotas, nenhuma no singular. Era o risco real de rodar o DROP com
--     FOREIGN_KEY_CHECKS = 0: constraint órfã quebra INSERT/UPDATE com errno
--     1824 e não aparece em leitura nenhuma.
--
-- NÃO CONFERIDO pela IA (saída não reportada): os passos 1, 2 e 3, e as duas
-- consultas de verificação do fim do passo 4. A checagem das migrations acima
-- cobre as FKs que o Laravel cria, mas não uma constraint manual que tivesse
-- vindo no import legado de 2026-08-29. Se a consulta de constraint órfã do
-- passo 4 não tiver sido rodada, ela continua valendo a qualquer momento — é
-- leitura pura e responde isso em definitivo.
-- ============================================================================

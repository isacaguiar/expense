# Aceitar e recusar um comprovante podem reescrever `confirmed_at` se a produção tiver `explicit_defaults_for_timestamp` desligado

ID: 147
Origem: docs/feature/20261110-quem-paga-se-declara-e-credor-recusa/ (TASK-605, revisão do `security-reviewer`, achado 4, **hipótese**); `backend/database/migrations/2026_08_25_220000_create_ex_settlement_confirmations_table.php:31`, `backend/app/Services/SettlementConfirmationService.php` (`accept` e `refuse`)
Criado em: 2026-11-11
Prioridade: BAIXA
Status: Aberto

## Descrição
`confirmed_at` é a primeira coluna `timestamp NOT NULL` da tabela. Em MySQL ou MariaDB com `explicit_defaults_for_timestamp` **desligado**, a primeira coluna `timestamp NOT NULL` ganha `ON UPDATE CURRENT_TIMESTAMP`. O repositório já registrou essa armadilha em `create_ex_group_invitations_table.php:36-38`. Até a TASK-598, toda gravação nesta tabela definia `confirmed_at` junto; `accept` e `refuse` são os primeiros `UPDATE` que não o definem (só `accepted_at` e `refused_at`). Nenhum teste afirma que `confirmed_at` fica intacto depois de aceitar ou recusar.

**Verificado em 2026-11-11, só no MySQL de teste** (8.0.17): `explicit_defaults_for_timestamp = 1` e a coluna não tem `ON UPDATE`, então o teste local não vê o problema. **Não verificado em produção** (host compartilhado, banco do cPanel).

## Por que importa
Se a produção tiver a opção desligada, a data de **envio** do comprovante, que é dado de auditoria, viraria a data da decisão do credor. Não afeta autorização nem o selo.

Para verificar: `SHOW CREATE TABLE ex_settlement_confirmations` e `SELECT @@explicit_defaults_for_timestamp` em produção (gate humano). Correção barata, que vale mesmo sem a confirmação: incluir `'confirmed_at' => $confirmation->confirmed_at` no `update` de `accept` e `refuse` (ou uma migration que troque a coluna para `dateTime`), e um teste que afirme `confirmed_at` inalterado depois de aceitar e de recusar.

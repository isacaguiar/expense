# Tasks — Unidades atômicas executáveis

> Toda task nasce de uma seção do `02-plan.md`. Formato fixo abaixo; siga-o para toda task nova.

Versão: 1.0 · Última atualização: 2026-08-17

## Formato de uma task

```
ID:            TASK-0xx
Título:        <verbo no imperativo, escopo único>
Tipo:          backend | frontend | infra | doc
Plan ref:      02-plan.md §<seção>
Descrição:     <o que fazer, em 1-3 frases>
Arquivos:      <caminhos afetados, se conhecidos>
Critério de aceite: <como verificar que está pronto — testável>
Gate humano:   nenhum | antes do merge | antes do deploy/migration em produção | antes de rotacionar segredo
```

Regra de atomicidade: uma task deve ser completável, revisável e testável **isoladamente** — se a descrição tem "e" ligando duas entregas independentes, é duas tasks.

---

## Épico A — Migração do frontend para React Native (Expo)

**Migrado para `docs/feature/20260817-migracao-frontend-expo/` em 2026-08-17.** TASK-001 a TASK-010 (+ TASK-022 a TASK-026, criadas ao migrar) têm specify/plan/tasks/implementation próprios lá — ver `docs/feature/20260817-migracao-frontend-expo/tasks.md` para status atualizado. Este épico não recebe mais tasks novas aqui.

## Épico B — Segurança

**Migrado para `docs/feature/20260817-seguranca-api/` em 2026-08-17.** TASK-011 a TASK-015 (Pix sem autenticação, IDOR em grupos, segredos versionados, path do deploy, log de senha em texto puro) têm specify/plan/tasks/implementation próprios lá — ver `docs/feature/20260817-seguranca-api/tasks.md` para status atualizado. Este épico não recebe mais tasks novas aqui.

## Épico C — Consistência da API (endpoints quebrados hoje)

| ID | Título | Tipo | Gate humano |
|---|---|---|---|
| TASK-016 | Implementar `ExpenseController@index/show/update/destroy` (hoje registrados via `apiResource` mas inexistentes) ou remover do `apiResource` os verbos não suportados | backend | antes do merge |
| TASK-017 | Implementar `GroupMemberController@destroy` (rota `DELETE /groups/{groupId}/members/{userId}` já existe e está quebrada) | backend | antes do merge |

## Épico D — Backlog de produto (ver `01-specify.md` §5 e §6)

| ID | Título | Tipo | Gate humano |
|---|---|---|---|
| TASK-018 | Decidir e, se aprovado, implementar persistência real de `Participation` (status pago/pendente) | backend | decisão de produto antes de iniciar |
| TASK-019 | ~~Unificar os dois fluxos de convite (`InvitationController::invite` e `GroupMemberController::store`)~~ — migrada para `docs/feature/20260822-atualizacao-participantes/` em 2026-08-22, ver `tasks.md` lá (TASK-189 a TASK-193) para status atualizado | backend | antes do merge |
| TASK-020 | Extrair Service de cálculo de saldo compartilhado entre `reportByGroupAndYear` e `reportByGroupAndYearMonthlySettlement` (hoje duplicado) | backend | nenhum |
| TASK-021 | Decidir destino das credenciais Google OAuth órfãs (implementar login social ou remover referências) | doc/produto | decisão de produto |

---

## Convenção a partir de 2026-08-17

Este arquivo deixou de crescer: trabalho novo (feature ou épico técnico) ganha uma pasta própria em `docs/feature/<AAAAMMDD>-<slug>/`. Decisão, motivo e alternativas: `decisions/ADR-002-sdd-por-feature.md`. Os épicos abaixo que ainda não têm pasta em `docs/feature/` continuam valendo como estão até alguém começar a trabalhar neles — nesse momento, migram para lá (como feito com o Épico B → `docs/feature/20260817-seguranca-api/`).

Novas tasks dentro de um épico ainda não migrado seguem o mesmo formato e entram no épico correspondente (ou em um novo épico, se abrirem uma frente nova do Plan) — até serem migradas.

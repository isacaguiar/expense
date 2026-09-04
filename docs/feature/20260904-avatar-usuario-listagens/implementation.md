# Implementation — Avatar de Usuário nas Listagens

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260904

---

## 1. Desvios do fluxo padrão (se houver)

<Deixe vazio/apague esta seção se a feature segue `04-implementation.md` sem exceção.>

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-001 | Concluída | 2026-09-04 | Claude (IA) | `./vendor/bin/pint --test app/Http/Controllers/ExpenseController.php tests/Feature/ExpenseControllerSummaryTest.php` — PASS (2 files); `php artisan test --filter=ExpenseControllerSummaryTest` — 23 passed (149 assertions); `php artisan test` (suíte completa) — 328 passed (1037 assertions) | Mudança aditiva só em `computeCycleSummary` (Constitution §4.1); `GroupMemberController::index`/model `User` não mudaram (já expunham `avatar_url`). |
| TASK-002 | Concluída | 2026-09-04 | Claude (IA) | `npx vitest run src/components/UserAvatar.test.tsx` — 4 passed; `npx tsc --noEmit` — sem erro | `GroupMember`/`GroupMemberPix` mantidos com o campo `avatar_url` (snake_case, igual à API de `/members`) em vez de renomear pra `avatarUrl` — o resto do arquivo já atribui `res.data` direto ao state sem camada de mapeamento, então renomear exigiria introduzir uma transformação nova só pra isso; `SummaryExpense`/`SummaryBalance` (que já são hand-authored, não passthrough) usam `avatarUrl`/`payerAvatarUrl` camelCase, consistente com `payerName`/`paymentProofUrl` que essa API já devolve nesse formato. Campos novos marcados opcionais (`?:`) pra não quebrar snapshot selado antes desta feature nem fixtures de teste existentes. |
| TASK-003 | Concluída | 2026-09-04 | Claude (IA) | `npx tsc --noEmit` — sem erro; `npx vitest run` (suíte completa do frontend) — 236 passed (37 test files) | `ExpenseManager.tsx`: removidos os imports `Avatar`/`getInitials` (ficaram sem uso, únicas 3 ocorrências substituídas por `UserAvatar`). `Payments.test.tsx` ajustado (`Credor: Isac` virou `Credor:` + avatar + `Isac` em elementos separados). `CycleDetailPanel.tsx`: texto "Pago por {nome}" do `ListItemText` mantido (evita `disableTypography`/nesting inválido `<div>` dentro de `<p>`); `UserAvatar` adicionado como elemento visual ao lado, redundante com o texto mas cobrindo o pedido de imagem+tooltip. Verificação visual no browser adiada para depois de TASK-004/005 (mesmas telas, uma passada só). |
| TASK-004 | Concluída | 2026-09-04 | Claude (IA) | `npx tsc --noEmit` — sem erro; `npx vitest run` (suíte completa do frontend) — 236 passed (37 test files) | `BalanceCards.tsx`/`SettlementList.tsx`/`PayableSettlementList.tsx`: removidos os imports `Avatar`/`getInitials`/`brandColors` (ficaram sem uso). `SettlementList`/`PayableSettlementList` ganharam `avatarById`/`avatarFor` ao lado do `nameById`/`nameFor` já existente, resolvendo de `balances` (mesmo `summary`, sem chamada de API nova). |

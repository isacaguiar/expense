# Plan — Comprovante e descrição ao marcar como paga

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261021

---

## 1. Banco: duas colunas nullable (specify R3, R6)

- Migration `backend/database/migrations/2026_10_21_000000_add_payment_note_to_ex_quotas_table.php`: `string('payment_note', 500)->nullable()->after('payment_proof_path')`. Mesmo molde de `2026_08_22_200000_add_payment_proof_path_to_ex_quotas_table.php`; `down()` faz `dropColumn`.
- Migration `2026_10_21_000001_add_note_to_ex_settlement_confirmations_table.php`: `string('note', 500)->nullable()->after('proof_path')`.
- `Quota` (`backend/app/Models/Quota.php:15-25`) e `SettlementConfirmation` (`backend/app/Models/SettlementConfirmation.php:19-28`): acrescentar a coluna ao `$fillable`.
- Por que `string(500)` e não `text`: o limite é de negócio e já é validado no request (item 2); `varchar(500)` em `utf8mb4` cabe no row size do MySQL e evita `TEXT` fora da linha.
- Aditivo (Constitution §4.2): coluna nullable, sem backfill. O deploy do backend já roda migration automaticamente (`20260903-deploy-backend-migracao-automatica`); o deploy continua sendo gate humano.
- `Quota` serializa todos os atributos, então `payment_note` já aparece no JSON de `GET /expenses/{id}` (`quotas[]`) sem código extra, como `payment_proof_url` hoje.

## 2. `pay()` e `unpay()` aceitam e limpam a descrição (specify R3, R4, D1)

`backend/app/Http/Controllers/ExpenseController.php`:

- `pay()` (`:1037`): acrescentar `'descricao' => 'nullable|string|max:500'` ao `validate()` (`:1045-1048`). Se `$data['descricao']` vier preenchida, `$update['payment_note'] = $data['descricao']` (junto de `paid`, `paid_at`, `paid_by`, `:1059-1067`).
  - **Trim e vazio**: o `Kernel` global já tem `TrimStrings` e `ConvertEmptyStringsToNull` (`backend/app/Http/Kernel.php:23-24`, Laravel 10). Texto só com espaços chega como `null`; não é preciso `trim()` manual. Um teste fixa isso para não depender de memória.
  - **Re-`pay()` sem `descricao`** não apaga a nota existente, igual ao comprovante (`payment_proof_path` só entra no `$update` se há arquivo). Só `unpay()` limpa.
  - **Contrato permissivo (D1)**: `pay()` continua aceitando chamada sem `comprovante` e sem `descricao`. A regra "foto OU descrição" é do cliente web. Reescrever o docblock `:1032-1035`, que hoje diz que o `ExpenseManager` chama sem corpo, para dizer que a API é permissiva por compatibilidade (Constitution §4.1) e que a interface web exige o popup.
  - Não mexer no gatilho do WhatsApp (`:1084`): continua disparando só quando há foto (`array_key_exists('payment_proof_path', $update)`). Descrição não notifica (specify §4).
- `unpay()` (`:1114`): incluir `'payment_note' => null` no `update()` (`:1139-1144`), ao lado de `payment_proof_path`. O docblock `:1111-1112` passa a citar também a descrição.

## 3. `confirmSettlement()` aceita a descrição do devedor (specify R2, R5)

- `ExpenseController::confirmSettlement()` (`:1168`): acrescentar `'descricao' => 'nullable|string|max:500'` ao `validate()` (`:1172-1176`). `comprovante` continua `required|image|max:5120` (R2).
- No `updateOrCreate` (`:1217-1222`), gravar `'note' => $data['descricao'] ?? null`. Reenviar sem descrição **limpa** a nota anterior, porque o reenvio substitui o comprovante inteiro (R5); o fluxo de `$previousProofPath` não muda.
- `WhatsAppNotifier::settlementProofConfirmed` e `Notifier::settlementConfirmed` ficam como estão.

## 4. Summary expõe `paymentNote` e `confirmedNote` (specify R6, R7, R8)

- `collectCycleEntries()` (`:1573`): nos 3 pontos onde `paymentProofUrl` é montado (`:1600`, `:1626`, `:1669`), acrescentar `'paymentNote' => $quota->payment_note ?? null` (no ramo de parcelas, `$quota->payment_note`, que nunca é nulo de objeto). Atualizar o `@return` do docblock (`:1571`).
- `computeCycleSummary()` (`:1264`): no mapeamento de `$expenses` (`:1272-1281`), acrescentar `'paymentNote' => $entry['paymentNote']` logo depois de `paymentProofUrl`.
- `attachSettlementConfirmations()` (`:1430`): acrescentar `$settlement['confirmedNote'] = $confirmation?->note;` ao lado de `confirmedProofUrl` (`:1441`). Esse decorador roda nos 3 caminhos que devolvem `settlements` (`summary()`, `close()`, `reopen()`), então `confirmedNote` vem sempre ao vivo, inclusive em ciclo selado.
- **Snapshot (R8)**: ciclo selado serve o JSON congelado de `GroupCycleSnapshot`. Snapshots antigos não têm `paymentNote`; ciclos selados depois do deploy já levam o campo, porque o snapshot é gerado pelo mesmo `computeCycleSummary()`. O front trata a ausência como `null`, então não há migração de dados.
- Aditivo (Constitution §4.1): campos novos, nenhum renomeado nem removido.

## 5. Frontend: tipos e `usePaymentActions` (specify §2.1 item 1, D1)

- `frontend/src/hooks/useGroupCycle.ts`: `SummaryExpense.paymentNote?: string | null` (`:38`) e `SummarySettlement.confirmedNote?: string | null` (`:73`). Opcionais porque snapshots antigos e fixtures de teste não trazem o campo.
- `frontend/src/hooks/usePaymentActions.ts`:
  - Novo tipo exportado `PaymentDetails = { proof: File; note?: string } | { proof?: File; note: string }`, que exige **pelo menos um** dos dois **na tipagem**. `handlePay(expenseId: number, details: PaymentDetails)` deixa de aceitar o parâmetro opcional atual (`:17`, `:64`). Assim, reintroduzir `handlePay(exp.id)` (o clique sem popup que esta feature elimina) vira erro de `tsc`, não regressão silenciosa.
  - Corpo (`:68-76`): com `proof`, `FormData` com `comprovante`, `cycles_ago` e, se houver, `descricao`; só com texto, JSON `{ cycles_ago, descricao }`. `note` é normalizado com `trim()` e só vai no corpo quando não vazio.
  - Reescrever o JSDoc `:21-34`: o "um clique, sem comprovante" deixa de ser verdade; a regra "foto OU descrição" fica no `PaymentProofDialog`, e o hook só garante o mínimo por tipo.
  - `canPay`, `canUnpay`, `handleUnpay` não mudam.

## 6. Componente `PaymentProofDialog` (specify §3.1, R1, R2, R3)

Novo `frontend/src/components/PaymentProofDialog.tsx`, MUI, no estilo de `PixPaymentDialog.tsx`.

- Props: `open`, `title`, `description` (texto de ajuda), `photoLabel`, `requirePhoto`, `busy?`, `error?`, `onClose()`, `onConfirm({ file: File | null, note: string })`.
- Conteúdo: o seletor de foto atual (`Button component="label"` + `<input type="file" accept="image/*" capture="environment" hidden>`, hoje duplicado em `Payments.tsx:336-346` e `:373-382`) e um `TextField multiline` (`minRows={2}`, `maxRows={5}`, `inputProps={{ maxLength: 500 }}`) com contador `n/500` no `helperText`. Rótulo: "Descrição" quando a foto é opcional (`requirePhoto=false`), "Descrição (opcional)" quando a foto é obrigatória. Um `Alert` mostra `error` quando houver (padrão do popup do devedor, `Payments.tsx:383-387`).
- Regra de habilitação do `Confirmar`: `!busy && (requirePhoto ? !!file : (!!file || note.trim() !== ''))`. Encapsulada num helper puro exportado (`canConfirmPayment`), para o teste cobrir a regra sem montar o diálogo.
- Estado interno (`file`, `note`) zerado sempre que `open` passa a `true` (`useEffect`), no lugar dos `setSelectedFile(null)` espalhados pelas telas.
- `onConfirm` devolve `note.trim()`. Quem chama decide fechar: o credor fecha na hora (como hoje, `Payments.tsx:107-111`) e o erro sai no Snackbar do hook; o devedor mantém aberto até o sucesso, com `busy` e `error` (como hoje, `:154-183`).
- Texto exibido só via nós de texto do React; nenhum `dangerouslySetInnerHTML` existe no `frontend/src` hoje (R3), e a verificação entra nas tasks.
- Limite conhecido, fora de escopo: se o `pay` do credor falhar (403/422), o popup já fechou e o texto digitado se perde. É o mesmo comportamento que a foto tem hoje; corrigir exigiria o hook devolver o resultado por chamada.

## 7. Despesas: o ✓ passa a abrir o popup (specify §2.1 item 1)

`frontend/src/pages/ExpenseManager.tsx`:

- Novo estado `payTargetId: number | null`. O botão ✓ (`:465-476`) troca `onClick={() => handlePay(exp.id)}` por `setPayTargetId(exp.id)`. Mantém `aria-label="Marcar como paga"` e o `disabled={payingExpenseId === exp.id}`. Como `renderExpenseActions` alimenta a tabela (`:742`) e os cartões (`:531`), os dois leiaute são cobertos por uma mudança só.
- Um `<PaymentProofDialog>` renderizado uma vez, junto dos outros diálogos: `requirePhoto={false}`, título "Confirmar pagamento", texto de ajuda "Anexe uma foto do comprovante ou descreva o pagamento." No `onConfirm`: `handlePay(payTargetId, { proof: file ?? undefined, note })` e fecha. A tipagem do item 5 obriga o chamador a passar um dos dois, e o popup só habilita o botão quando há um.
- O Snackbar "Despesa marcada como paga." (`:939`) fica como está.

## 8. Pagamentos: os dois popups usam o componente (specify §2.1 itens 2 e 3, D2)

`frontend/src/pages/Payments.tsx`:

- **Credor**: remover os estados `selectedFile`, `fileInputRef`, `openConfirmDialog`/`closeConfirmDialog`/`confirmPayment` (`:91-111`) e o `<Dialog>` `:330-354`; usar `<PaymentProofDialog requirePhoto={false}>` (D2). Botão "Confirmar pagamento" (`:278-288`) só passa a abrir o novo estado.
- **Devedor**: remover `selectedSettlementFile` e o `<Dialog>` `:367-401`; usar `<PaymentProofDialog requirePhoto>` com `busy={confirmingSettlement}` e `error={settlementConfirmError}`. `confirmSettlementPayment` (`:154-183`) passa a receber `{ file, note }` e acrescenta `form.append('descricao', note)` só quando houver texto. `cycles_ago` (`:167`) permanece.
- Cartão da despesa (`:269-275`): exibir `exp.paymentNote` abaixo de "Ver comprovante", quando houver. O link só aparece com foto; a nota aparece com ou sem foto.

## 9. Onde a descrição aparece (specify R7)

- `ExpenseManager.tsx`, modal de detalhes (`:827-829`): bloco "Descrição do pagamento: …" quando `detailExpense.paid && detailExpense.paymentNote`. O ícone "Ver comprovante" da lista (`:425-438`) continua exigindo `paymentProofUrl`; uma quota paga só com descrição não mostra o ícone, e a nota se vê no modal.
- `components/PayableSettlementList.tsx` (`:86-99`): `confirmedNote` em `Typography variant="caption"` abaixo do chip "Comprovante enviado". `confirmed` continua derivado de `confirmedProofUrl` (a foto segue obrigatória para o devedor).
- `pages/ExpenseView.tsx`: `ExpenseQuota.payment_note: string | null` (`:35`) e um helper `latestPaidNote(quotas)`, irmão de `latestPaidProof` (`:60-66`), que escolhe a quota paga **mais recente** (mesmo critério) com nota. O bloco `{proofUrl && …}` (`:542-548`) ganha a linha da nota, que pode aparecer sem o link.

## 10. Documentação e comentários desatualizados

- `MANUAL.md`: seção **Despesas** (`:49-54`), onde "marcar como paga" passa a abrir o popup, e **Pagamentos** (`:63-68`), com a menção ao campo de descrição. `MANUAL.pdf` não tem rotina de geração no repositório (só é linkado no `README.md:11`); fica registrado em `implementation.md` como pendência manual.
- Comentários que ficam falsos e são corrigidos junto: JSDoc de `usePaymentActions.ts:21-34` (item 5) e docblock de `ExpenseController::pay()` `:1032-1035` (item 2).
- `docs/sdd/01-specify.md`, `05-context-frontend.md` e `06-context-backend.md` não mencionam `/pay` nem comprovante (verificado por grep em 2026-10-21): nada a atualizar.
- `docs/backlog/`: novo item para "anexar ou editar comprovante/descrição depois de paga" (specify §4). Criado por uma task, no formato de `docs/backlog/README.md`.

## 11. Testes

Backend (`backend/tests/Feature/`):

- `ExpenseControllerPayTest.php`, novos: pagar só com `descricao` (marca paga, grava `payment_note`, sem foto); foto + `descricao`; `descricao` de 501 caracteres → 422; `descricao` só com espaços → `payment_note` nulo; `unpay` limpa nota e foto; re-`pay` sem `descricao` preserva a nota; summary expõe `paymentNote` em despesa à vista e em parcela; **`pay` sem corpo continua 200** (nome e comentário citando D1, para ninguém "consertar" depois sem passar pela decisão). Os testes atuais `test_pay_without_file_leaves_payment_proof_path_null` e `test_creditor_can_pay_in_cash_expense` seguem verdes sem edição.
- `SettlementConfirmationControllerTest.php`, novos: grava `note`; reenvio sem descrição limpa; 501 caracteres → 422; `confirmedNote` no summary; foto continua obrigatória.

Frontend (Vitest, ao lado de cada arquivo):

- `PaymentProofDialog.test.tsx`: `Confirmar` desabilitado sem nada; só texto habilita com `requirePhoto=false`; texto só com espaços não habilita; só texto **não** habilita com `requirePhoto=true`; arquivo habilita nos dois modos; `onConfirm` recebe `{ file, note }` com `trim`; contador e `maxLength`; estado zerado ao reabrir.
- `usePaymentActions.test.tsx`: `FormData` com `comprovante` + `descricao` + `cycles_ago`; JSON `{ cycles_ago, descricao }` quando só há texto; sem a chave `descricao` quando vazia. Os testes que chamam `handlePay(id)` sem detalhes (que existem hoje) são reescritos para o contrato novo.
- `ExpenseManager.test.tsx`: **clicar no ✓ não dispara POST** (o guarda de regressão do achado principal) e abre o popup; confirmar com texto → corpo com `descricao`; com foto → `FormData`; cancelar → nenhum POST; modal de detalhes mostra a nota. Os cliques em "Marcar como paga" de `:1125`, `:1212` e `:1287` passam a atravessar o popup.
- `Payments.test.tsx`: popup do credor aceita só texto (D2); popup do devedor continua exigindo foto e envia `descricao` + `cycles_ago`; cartão mostra `paymentNote`.
- `PayableSettlementList.test.tsx` e `ExpenseView.test.tsx`: renderizam a nota (e a ausência dela).
- Verificação estática: `tsc --noEmit` (pega `handlePay(id)` sem detalhes) e `grep dangerouslySetInnerHTML frontend/src` vazio.

## 12. Segurança (checklist do `security-reviewer`)

- Autorização inalterada: só o credor chama `pay`/`unpay` (`:1041`, `:1118`) e só o devedor do par confirma o acerto (`:1199`).
- Entrada: texto livre limitado a 500 caracteres no servidor (`max:500`) e na tela (`maxLength`); o servidor é a barreira real.
- Saída: renderizada como texto (R3). A descrição não entra em e-mail, WhatsApp nem notificação in-app (specify §4), então não há novo canal de injeção.
- Dado pessoal: a descrição pode conter informação livre digitada pelo credor, como o comprovante. Fica dentro do grupo (rotas já protegidas por `findExpenseForMember`/`authorizeGroupMembership`); nenhum log novo a registra.
- Rodar o agent `security-reviewer` depois das tasks de backend (`CLAUDE.md`, "Padrão de trabalho" item 4).

## 13. Ordem de execução

Backend e frontend se encontram no contrato do summary e dos parâmetros; a ordem abaixo evita testar o front contra uma API que ainda não responde:

1. **Backend** (itens 1 a 4): migrations → models → `pay`/`unpay` → `confirmSettlement` → summary → testes PHPUnit → `security-reviewer`. O item 1 antecede todos; os itens 2 e 3 são independentes entre si.
2. **Frontend base** (itens 5 e 6): tipos e hook, e o `PaymentProofDialog` com seus testes. Independentes entre si e do backend; podem rodar em paralelo com o passo 1.
3. **Telas** (itens 7, 8 e 9): `ExpenseManager`, `Payments`, `PayableSettlementList`, `ExpenseView`. Dependem do passo 2; o 8 depende do passo 1 para a conferência manual.
4. **Documentação** (item 10) e conferência manual em stack isolada (porta 3307/8001/3001, receita em `validacao-local-stack-isolada`).

Branch única `feature/20261021-comprovante-ao-marcar-paga`, um PR contra `dev`; push e PR só depois do aval do usuário.

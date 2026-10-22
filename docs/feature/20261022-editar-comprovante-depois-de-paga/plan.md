# Plan — Editar comprovante e descrição depois de paga

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261022

Decisões do specify já fechadas pelo usuário em 2026-10-22: **D1** rota nova, **D2** pode remover foto e limpar descrição, **D3** sem WhatsApp na troca, **D4** ciclo selado bloqueia, **D5** o servidor exige "foto OU descrição" no estado final, **D6** ícone em Despesas e botão em Pagamentos.

---

## 1. Backend: rota nova `POST /api/expenses/{expenseId}/payment-proof` (specify §3.1, D1, R1, R2, R4 a R7)

**Rota** (`backend/routes/api.php`, junto de `pay` e `unpay`, `:60-61`, dentro do mesmo grupo `jwt.auth`):

```php
Route::post('/expenses/{expenseId}/payment-proof', [ExpenseController::class, 'updatePaymentProof']);
```

`POST` e não `PUT` porque o PHP só preenche arquivos enviados em `multipart` nas requisições `POST` (D1).

**Método** `ExpenseController::updatePaymentProof($expenseId, Request $request)`, logo depois de `unpay()` (`:1129`). Fica no controller, como `pay`/`unpay`: são validação mais orquestração, sem cálculo de negócio denso. Reaproveita `findExpenseForMember` (404 para quem não é membro) e a busca de quota de `unpay`, que **não materializa** `FIXED` (`$expense->quotas()->whereBetween('date_expected', ...)->first()`). Não usa `resolveQuotaForCycle`, que materializaria uma ocorrência `FIXED` não paga (R5).

**Validação** (`$request->validate`), mesmos limites de `pay()` (R4):

```php
'comprovante'         => 'nullable|image|max:5120',
'remover_comprovante' => 'nullable|boolean',   // multipart envia '1'; JSON envia true
'descricao'           => 'nullable|string|max:500',
'cycles_ago'          => 'nullable|integer|min:0',
```

`TrimStrings` e `ConvertEmptyStringsToNull` (`Http/Kernel.php:23-24`) já aparam e transformam `""` em `null`.

**Semântica da edição** (parcial, não "substitui tudo"):

| Campo | Ausente | Presente |
|---|---|---|
| `comprovante` (arquivo) | foto mantida | foto substituída |
| `remover_comprovante` | foto mantida | com valor verdadeiro, foto removida |
| `descricao` | descrição mantida | `null` ou `""` limpa; texto substitui (inclusive `"0"`) |

"Presente" é `$request->has('descricao')`, que continua verdadeiro com valor `null` (usa `Arr::has`, isto é, `array_key_exists`). O front sempre envia `descricao`, então a tela sempre grava o estado final do campo; a rota continua útil para quem só quer trocar a foto (campo ausente).

**Ordem das verificações** (cada uma falha antes de gravar qualquer arquivo, para nunca deixar órfão):

1. Membro do grupo, senão 404 (`findExpenseForMember`).
2. Credor, senão `abort(403, 'Só o credor pode editar o comprovante desta despesa.')` (R1).
3. `validate()`.
4. Conflito: `comprovante` junto de `remover_comprovante` verdadeiro responde 422 `{error: 'Não é possível enviar uma foto nova e remover a foto na mesma edição.'}`. Verificação explícita no código, sem depender de nuances da regra `prohibits`.
5. Quota da competência (`cycles_ago`, mesma conta de `unpay`): sem quota ou `! $quota->paid` responde 422 `{error: 'Esta despesa não está paga nessa competência.'}`, a mesma mensagem de `unpay` (R5, R6).
6. **Ciclo selado** (D4): `GroupCycleSnapshot::where(group_id, cycle_start)->first()?->isSealed()` responde 422 `{error: 'Esta competência já foi encerrada. Desfaça o pagamento para corrigir o comprovante.'}`, o mesmo padrão de `confirmSettlement` (`:1211`).
7. **Estado final** (D5): `temFoto = hasFile || ($quota->payment_proof_path && ! remover)` e `novaNota = has('descricao') ? ($data['descricao'] ?? null) : $quota->payment_note`. Se `! temFoto && ! filled($novaNota)`, responde 422 `{error: 'Informe a foto do comprovante ou uma descrição do pagamento.'}`. `filled()`, nunca `empty()`, para não repetir o defeito do `"0"` (TASK-448 da feature anterior).

**Gravação** (R3): guardar o caminho anterior, gravar o arquivo novo (se veio), atualizar a quota e **só depois** apagar o arquivo anterior: Desde a TASK-462, a busca da quota, as verificações de estado e o `update` acontecem dentro de `DB::transaction` com a quota lida por `lockForUpdate`, e o arquivo anterior é apagado **depois do commit**. O disco `local` tem `throw => false`: se a gravação falhar, `storeAs` devolve `false`; desde a TASK-463 `ProofStorage::store` aborta com 500 ("Não foi possível gravar o comprovante.") antes de qualquer escrita no banco, o que vale para `pay()`, para esta rota e para `confirmSettlement`.

```php
$anterior = $quota->payment_proof_path;
$novo = $request->hasFile('comprovante')
    ? ProofStorage::store($request->file('comprovante'), $expense->group_id) : null;

$update = ['payment_note' => $novaNota];
if ($novo) { $update['payment_proof_path'] = $novo; }
elseif ($remover) { $update['payment_proof_path'] = null; }

try { $quota->update($update); }
catch (\Throwable $e) { ProofStorage::delete($novo); throw $e; }   // não deixa o arquivo novo órfão

if (array_key_exists('payment_proof_path', $update) && $anterior
    && $anterior !== ($update['payment_proof_path'] ?? null)) {
    ProofStorage::delete($anterior);
}

return response()->json($quota->fresh());   // mesmo formato de unpay(): inclui payment_note e payment_proof_url
```

**O que a rota não faz** (R2, D3): não altera `paid`, `paid_at`, `paid_by`; não chama `Notifier::expensePaid`, `WhatsAppNotifier::expenseProofPaid`, `sealCycleIfSettled` nem `unsealIfBroken`. O estado de pago não muda, então nada do que isso recalcula muda.

**Contrato** (R7): rota nova, nenhum campo existente muda, **sem migration** (`payment_proof_path` e `payment_note` já existem em `ex_quotas`).

**Parcela nascida paga** (R10): sem tratamento especial; `born_paid` é quota paga como outra qualquer para esta rota.

## 2. Backend: `pay()` repetido apaga a foto anterior (specify §2.3, R9)

Em `ExpenseController::pay()`:

- antes do `$quota->update($update)` (`:1085`), guardar `$anterior = $quota->payment_proof_path`;
- depois do `update`, se entrou foto nova (`array_key_exists('payment_proof_path', $update)`) e há `$anterior` diferente, chamar `ProofStorage::delete($anterior)`.

Mesma ordem de R3 (grava, atualiza, só então apaga). Nada mais muda em `pay()`: continua permissivo, continua notificando só na transição não paga → paga (`$wasPaid`) e continua mantendo a nota quando `descricao` não vem. Atualizar o docblock de `pay()` (`:1019-1041`) com uma linha sobre isso.

`unpay()` **não** é alterado, embora apague o arquivo *antes* do `update` (`:1152`): é a ordem inversa de R3, mas é comportamento existente e fora do escopo.

## 3. Frontend: hook `usePaymentActions` (specify D4, D6, R1)

`frontend/src/hooks/usePaymentActions.ts`:

- **`canEditProof(exp)`**, ao lado de `canUnpay` (`:65`):
  `cycleAllowsProofEdit && exp.paid && isCreditor(exp)`, com `cycleAllowsProofEdit = summary !== null && !cycleIsFuture && summary.cycle.settled !== true`. É o espelho de D4: nunca oferece a ação num ciclo selado, e, sem o resumo carregado (não dá para provar que o ciclo não está selado), também não oferece. `canPay` e `canUnpay` não mudam e seguem só excluindo `future`. `born_paid` entra naturalmente (R10).
- **Tipo exportado** `EditProofInput = { proof?: File; removeProof?: boolean; note: string }`. `note` é obrigatório no tipo porque a tela sempre envia o estado final da descrição.
- **`handleEditProof(expenseId, input)`**: `POST ${API_BASE_URL}/api/expenses/${expenseId}/payment-proof`.
  - com `proof`: `FormData` com `comprovante`, `cycles_ago`, `descricao` (mesmo vazio), e `remover_comprovante = '1'` só quando `removeProof` (o `boolean` do Laravel não aceita a string `"true"`);
  - sem `proof`: JSON `{ cycles_ago, descricao, remover_comprovante?: true }`;
  - `descricao` é o texto já aparado (`input.note.trim()`); vazio vai como `""` e o backend limpa;
  - reaproveita `payingExpenseId` e `payError`; novos `editSuccess` e `dismissEditSuccess`; no sucesso, `reload()`. Mensagem padrão de falha: "Falha ao atualizar o comprovante.".
- Atualizar o JSDoc do hook (menciona a edição) e o tipo `UsePaymentActionsResult`.

## 4. Frontend: `PaymentProofDialog` ganha o modo de edição (specify R9 do front, D2, D5)

`frontend/src/components/PaymentProofDialog.tsx`, aditivo e compatível com os usos atuais (Despesas ✓, Pagamentos credor e devedor):

- **Props novas, todas opcionais**: `initialNote?: string` (descrição atual), `currentProofUrl?: string | null` (foto atual) e `confirmLabel?: string` (padrão `"Confirmar"`; a edição passa `"Salvar"`). O modo de edição é implícito: liga quando `currentProofUrl` **ou** `initialNote` é passada, mesmo vazia ou `null` (`initialNote !== undefined || currentProofUrl !== undefined`). Isso cobre a despesa paga antes do popup existir, que não tem foto nem descrição: a tela passa `initialNote=""` e `currentProofUrl={null}`, e o botão fica desabilitado até haver foto ou texto. Título e texto de ajuda já vêm por prop.
- **`PaymentProofValue`** passa a `{ file: File | null; note: string; removeProof?: boolean }`. O campo só é preenchido no modo de edição, então as asserções dos testes atuais (`toHaveBeenCalledWith({ file, note })`) não quebram.
- **Estado**: `removeProof` (zerado ao abrir) e `note` inicializado com `initialNote ?? ''` ao abrir.
- **Interface** quando há `currentProofUrl`: uma linha "Foto atual" com o link "Ver foto atual" (nova aba) e um checkbox "Remover a foto atual". Marcar o checkbox limpa a foto escolhida; escolher uma foto desmarca o checkbox. Assim o front nunca monta a combinação que o backend recusa.
- **Regra de habilitação** (`canConfirmPayment`, helper puro exportado, ganha `hasCurrentProof?`, `removeProof?`, `changed?`):
  - `temFotoFinal = hasFile || (hasCurrentProof && !removeProof)`;
  - `requirePhoto`: `temFotoFinal`; senão `temFotoFinal || note.trim() !== ''` (D2/D5: o estado final precisa de foto ou descrição);
  - `changed === false` desabilita o botão (nada a salvar). `changed` = foto nova, remoção marcada ou descrição diferente da inicial (comparação aparada). Fora da edição o argumento não vem e a regra é a de hoje.
- Texto da descrição continua só como texto puro (R8).

## 5. Frontend: Despesas (specify D6)

`frontend/src/pages/ExpenseManager.tsx`:

- Em `renderExpenseActions` (`:429`, usado pela tabela e pelos cartões), um novo `IconButton` "Editar comprovante" **antes** de "Desfazer pagamento" (`:488`), com `canEditProof(exp)`, ícone `AttachFileOutlined` (o `EditOutlined` já é de "Editar despesa", e repetir confundiria), `disabled={payingExpenseId === exp.id}`.
- Estado `editProofTargetId` e uma segunda instância de `PaymentProofDialog` (a primeira é a do ✓, `:858`), com `title="Editar comprovante"`, `description="Troque a foto, remova-a ou ajuste a descrição. O pagamento continua marcado como pago."`, `confirmLabel="Salvar"`, `initialNote={alvo.paymentNote ?? ''}`, `currentProofUrl={alvo.paymentProofUrl}`. O alvo é buscado em `expenses` pelo id, como o `detailExpense`, para nunca usar um objeto defasado.
- `onConfirm` chama `handleEditProof(id, { proof: file ?? undefined, removeProof, note })` e fecha o popup, como o ✓ faz hoje; erro sai no Snackbar do hook.
- Snackbar de sucesso "Comprovante atualizado." (`editSuccess`).

## 6. Frontend: Pagamentos (specify D6)

`frontend/src/pages/Payments.tsx`: no cartão (caixa de ações em `:267`), botão `outlined` pequeno "Editar comprovante" (ícone `AttachFileOutlined`) antes de "Desfazer pagamento" (`:279`), com `canEditProof(exp)`. Mesma instância de diálogo de edição e o mesmo Snackbar de sucesso. Estado próprio (`editProofExpenseId`), separado do `confirmExpenseId` do popup de pagamento.

## 7. Testes (Constitution §2.2)

**Backend**, arquivo novo `backend/tests/Feature/ExpenseControllerPaymentProofTest.php` (o `ExpenseControllerPayTest` já passa de 800 linhas), com `Storage::fake('local')`, `Carbon::setTestNow` e os mesmos helpers de `ExpenseControllerPayTest`:

| Caso | Prova |
|---|---|
| anexa foto numa quota paga só com texto | grava o arquivo, mantém a descrição, `paid_at` e `paid_by` iguais (R2) |
| troca a foto | a nova existe, a antiga **some do disco** (R3) |
| remove a foto mantendo a descrição | `payment_proof_path` nulo, arquivo apagado |
| só edita a descrição | arquivo intocado |
| limpa a descrição mantendo a foto | `payment_note` nulo |
| `descricao` `"0"` | grava `"0"` (não repete o defeito do `empty()`) |
| remove a foto sem descrição, e limpa a descrição sem foto | 422, nada muda, a foto antiga continua no disco (D2, D5) |
| foto junto de `remover_comprovante` | 422 |
| não imagem, foto acima de 5 MB, 501 caracteres, `descricao` em array | 422 |
| quota não paga; `FIXED` sem ocorrência materializada | 422, **nenhuma quota criada** (R5) |
| não credor / não membro | 403 / 404 (R1) |
| ciclo selado | 422, snapshot e quota intactos (D4) |
| ciclo fechado por data e fechado manualmente, ainda não selados | 200 (R6) |
| `cycles_ago` aponta a quota certa | edita a do ciclo anterior, não a do atual |
| quota `born_paid` | 200 (R10) |
| nenhuma notificação | `Http::assertNothingSent()` e contagem de `ex_notifications` igual (R2, D3) |
| falha no `update` | o arquivo novo é apagado e o antigo permanece (R3). Simulado com `Quota::updating` lançando exceção, limpo com `Quota::flushEventListeners()` no `tearDown` |

Em `ExpenseControllerPayTest.php` (R9): `pay()` repetido com foto nova apaga a anterior; `pay()` repetido sem foto não apaga nada; `pay()` sem corpo continua 200 (já existe e não pode mudar).

**Frontend** (Vitest, ao lado de cada arquivo):

- `usePaymentActions.test.tsx`: `canEditProof` verdadeiro só para credor, quota paga, ciclo não futuro e não selado (incluindo `settled: true` e despesa de outro credor); `handleEditProof` envia JSON sem foto e `FormData` com foto; `descricao` sempre presente, inclusive vazia; `remover_comprovante` como `'1'` no `FormData` e `true` no JSON; `cycles_ago`; `editSuccess`; mensagem de erro da API.
- `PaymentProofDialog.test.tsx`: modo de edição pré-preenche a descrição; mostra o link "Ver foto atual"; "Remover a foto atual" limpa a foto escolhida e vice-versa; sem alteração o botão fica desabilitado; remover a foto e esvaziar a descrição desabilita; trocar só a descrição habilita; `onConfirm` leva `removeProof`; `canConfirmPayment` com os argumentos novos (os testes atuais seguem verdes sem edição).
- `ExpenseManager.test.tsx` e `Payments.test.tsx`: o botão só aparece para o credor com despesa paga e ciclo não selado (e some em ciclo selado, para outro credor e para despesa pendente); abre o popup preenchido; salvar faz `POST .../payment-proof` com o corpo certo; toast "Comprovante atualizado."; erro da API no Snackbar.
- `tsc --noEmit` limpo, nenhum `any` novo, `grep dangerouslySetInnerHTML frontend/src` vazio.

**Rastreabilidade das regras do specify:**

| Regra | Onde é garantida | Teste |
|---|---|---|
| R1 | §1 passos 1 e 2 | não credor 403, não membro 404 |
| R2 | §1 (o que a rota não faz) | `paid_at`/`paid_by` iguais; sem notificação |
| R3 | §1 (gravação) e §2 | foto antiga some; falha no `update` |
| R4 | §1 (validação) | 422 de tipo, tamanho e 501 caracteres |
| R5 | §1 passo 5 | não paga e `FIXED` sem quota |
| R6 | §1 passo 5 | `cycles_ago` e ciclos fechados |
| R7 | §1 (contrato) | nenhuma migration no diff |
| R8 | §4 | teste de markup literal já existente nas telas |
| R9 | §2 | `pay()` repetido |
| R10 | §1 (parcela nascida paga) | `born_paid` 200 |

## 8. Segurança (Constitution §6)

- Rota nova **dentro** de `jwt.auth`, com checagem de membro (`findExpenseForMember`) e de credor antes de qualquer escrita; sem IDOR novo (a quota sai da própria despesa autorizada, nunca de um id solto).
- Arquivo: mesma validação de `pay()` (`image`, `max:5120`); gravação via `ProofStorage` (disco privado por grupo, URL assinada de 30 minutos para ler).
- Texto: limitado a 500 caracteres no servidor, renderizado como texto puro, sem novo canal (e-mail, WhatsApp, log).
- Ordem de verificações garante que nada é gravado em disco antes de todas as recusas.
- Rodar o agent `security-reviewer` depois das tasks de backend (`CLAUDE.md`, "Padrão de trabalho" item 4).

## 9. Documentação e backlog

- `MANUAL.md` (seção Despesas e seção Pagamentos): descrever o "Editar comprovante".
- `MANUAL.pdf`: regenerar. **O HTML que gera o PDF não está no repositório** (só no diretório temporário desta sessão). Ver decisão em aberto abaixo.
- **Backlog novo** (specify §2.5): registrar o item "link do comprovante expira em ciclo selado" (snapshot congela a URL assinada de 30 minutos). ID: o maior em `dev` hoje é 084 e a branch local do painel financeiro reservou o 083, então o próximo livre é **085**; reconfirmar na hora de gravar.
- Ao fim: seção "Resolução" no item 084 e mudança dele para `docs/backlog/concluidos/` (etapa 6 do `/promover-backlog`), com a linha correspondente no índice. Atenção: `docs/backlog/README.md` volta a ser tocado e a branch do painel financeiro também o altera; espere conflito de texto em quem mergear depois.

## 10. Riscos aceitos

- **Corrida entre editar e desfazer**: fechada na TASK-462 (achado B2 da revisão de segurança). A leitura da quota, as verificações que dependem do estado e o `update` rodam dentro de `DB::transaction` com `lockForUpdate`, e o arquivo anterior só é apagado depois do commit; assim, a edição nunca grava foto ou descrição numa quota que o `unpay` acabou de despagar. Resta, no pior caso, uma **foto nova órfã** quando o `unpay` concorrente já tinha lido o caminho antigo (nunca um comprovante válido perdido nem um estado inconsistente). Também resta, aceita, a janela mínima em que uma selagem de ciclo concorrente (disparada por outro `pay`) congela o snapshot um instante antes da gravação; a nota só é informativa e a edição de ciclo selado já é recusada.
- **Falha ao apagar o arquivo antigo** depois do `update` fica silenciosa (`ProofStorage::delete` ignora arquivo ausente): pior caso, um arquivo órfão, nunca um comprovante perdido (R3).
- **Link do comprovante em ciclo selado** continua expirando (specify §2.5). Como a edição é bloqueada em ciclo selado (D4), esta feature não piora isso.

## 11. Decisão técnica em aberto

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| T1 | Versionar a fonte do manual (HTML + as 7 capturas) para o PDF poder ser regenerado | A) sim, em `docs/manual/` (HTML e `images/`, cerca de 350 KB), com um comando de geração documentado<br>B) não; regenerar a partir do diretório temporário desta sessão e deixar o PDF como único artefato | **A** | Sem a fonte, o próximo ajuste do manual obriga a refazer o desenho do zero, como foi preciso agora. Também resolve as imagens `manual-assets/*.jpg` que o `MANUAL.md` cita e que nunca foram versionadas. Custo: arquivos binários novos no repositório e uma pasta nova fora das convenções atuais. |

## 12. Ordem de execução

1. **Backend** (§1, §2, §7 backend): `pay()` repetido (§2) e a rota nova (§1) são independentes entre si, mas mexem no mesmo controller, então ficam em sequência. Depois, `security-reviewer` (§8).
2. **Frontend base** (§3 e §4): hook e diálogo são independentes entre si e do backend (os testes usam mock); o §5 e o §6 dependem dos dois.
3. **Telas** (§5 e §6) em sequência (ambas usam o mesmo hook e o mesmo diálogo, em arquivos diferentes).
4. **Documentação** (§9): `MANUAL.md`, backlog novo, `MANUAL.pdf` (depois das telas, para descrever o comportamento final).
5. **Verificação** na branch integrada (pré-PR) e **validação local do usuário** na stack isolada, que é o gate antes do push.

Sem migration, então não há gate de banco. Branch única `feature/20261022-editar-comprovante-depois-de-paga`; push e PR só depois do aval do usuário.

# Specify — Editar comprovante e descrição depois de paga

> Feature: o credor passa a poder acrescentar, trocar ou remover a foto do comprovante e a descrição de uma despesa **já marcada como paga**, sem desfazer o pagamento. Promoção do item 084 de `docs/backlog/` (origem: auditoria da feature `20261021-comprovante-ao-marcar-paga`, item 6 e §4 do specify dela).

Versão: 1.0 · Criado em: 20261022

---

## 1. Problema

Depois que o credor marca uma despesa como paga, não há como acrescentar o comprovante que chegou depois, trocar uma foto errada ou corrigir a descrição. O ✓ e o botão "Confirmar pagamento" somem, e o único caminho é **desfazer o pagamento** e refazê-lo. Isso:

- apaga a foto e a descrição que já estavam certas (`unpay`);
- perde o `paid_at` e o `paid_by` originais;
- dessela o ciclo, se ele estava selado (`unsealIfBroken`), e reabre a pendência para os devedores.

Com a regra "foto OU descrição" da feature `20261021-comprovante-ao-marcar-paga`, pagar só com texto passou a ser comum, e anexar a foto depois é o complemento natural.

Além disso, existe hoje um caminho de API que quase faz isso e tem um defeito: um segundo `pay()` numa quota já paga grava a foto nova **sem apagar a antiga**, que fica órfã no disco privado.

## 2. Achados confirmados

Todos conferidos lendo o código de `dev` (`9d395e3009`) em 2026-10-22.

### 2.1 A interface não oferece edição depois de paga

- `canPay` exige `!exp.paid` e `canUnpay` exige `exp.paid`, ambos só para o credor (`frontend/src/hooks/usePaymentActions.ts:60` e `:65`).
- Em Despesas, uma despesa paga mostra "Ver detalhes", "Ver comprovante" (se houver foto), "Editar despesa" (que edita a despesa, não o comprovante) e **Desfazer pagamento**; o ✓ não existe mais (`frontend/src/pages/ExpenseManager.tsx:476` e `:488`).
- Em Pagamentos, o cartão pago mostra só "Desfazer pagamento" (`frontend/src/pages/Payments.tsx:268` e `:279`).

### 2.2 `unpay()` é destrutivo para o que só precisa de ajuste

`ExpenseController::unpay` (`backend/app/Http/Controllers/ExpenseController.php:1129`) apaga a foto do disco (`:1152`), zera `paid`, `paid_at`, `paid_by`, `payment_proof_path` e `payment_note`, e chama `unsealIfBroken`.

### 2.3 `pay()` repetido deixa a foto antiga órfã

`pay()` aceita uma quota já paga (`$wasPaid`, `:1083`) e, se vier foto, grava a nova com `ProofStorage::store` (`:1074`) sem chamar `ProofStorage::delete` para o `payment_proof_path` anterior. O arquivo antigo continua em `backend/storage/app/comprovantes/<grupo>/`, sem nenhuma referência no banco. A interface nunca chama `pay()` de novo (por 2.1), então o defeito só aparece via API direta.

### 2.4 Ciclo selado guarda uma cópia congelada

`sealCycleIfSettled` grava o `summary` inteiro em `GroupCycleSnapshot` com `settled_at` (`:1551` a `:1562`), e `summary()` devolve essa cópia sem recalcular (`:688`). Editar a foto ou a descrição de uma quota de ciclo selado deixaria a cópia desatualizada, a menos que a edição a regrave. Por isso o tratamento do ciclo selado é uma decisão (D4).

### 2.5 Achado incidental, fora do escopo: o link do comprovante expira no ciclo selado

O snapshot congela também `expenses[].paymentProofUrl`, que é uma **URL assinada de 30 minutos** gerada no instante da selagem (`backend/app/Models/Quota.php:70` a `:76`). Como `summary()` devolve o snapshot como está (`:688`), o link "Ver comprovante" de uma despesa em ciclo selado deixa de funcionar cerca de 30 minutos depois da selagem. O comprovante do acerto do devedor não sofre disso, porque `confirmedProofUrl` é decorado ao vivo (`attachSettlementConfirmations`). Verificado só pela leitura do código; não reproduzi em execução. Não bloqueia esta feature e vira item de backlog (task de documentação da feature).

## 3. Requisitos

### 3.1 O que a feature entrega

Uma ação nova para o credor numa despesa paga: **editar comprovante**. Ela abre o mesmo popup de comprovante, já preenchido, e permite trocar ou remover a foto e trocar ou limpar a descrição, sem alterar o estado de pago. Entra uma rota nova no backend para isso e o modo de edição no `PaymentProofDialog`, usado em Despesas e em Pagamentos. A feature também corrige o arquivo órfão do `pay()` repetido (2.3).

### 3.2 Regras adotadas

Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Só o credor (`user_payer_id`) edita; quem não é membro do grupo recebe 404 e o membro que não é o credor recebe 403, como em `pay()` e `unpay()`. |
| R2 | A edição não altera `paid`, `paid_at` nem `paid_by`, e não dispara `Notifier::expensePaid` nem muda a selagem de nenhum ciclo. |
| R3 | A foto substituída ou removida é apagada do disco (`ProofStorage::delete`) **depois** de a nova estar gravada e o banco atualizado, para nunca perder a antiga se a gravação falhar. |
| R4 | Mesmos limites de `pay()`: foto `image\|max:5120`, descrição até 500 caracteres, `trim` e vazio vira `null`. |
| R5 | Só edita quota **paga**. Quota não paga responde 422 ("para marcar como paga use o pagamento"); ocorrência `FIXED` ainda não materializada nunca está paga, então também responde 422. |
| R6 | A competência alvo vem de `cycles_ago` (>= 0, padrão 0), como em `pay()` e `unpay()`. |
| R7 | Contrato aditivo (Constitution §4.1) e sem migration: `payment_proof_path` e `payment_note` já existem em `ex_quotas`. |
| R8 | A descrição continua renderizada como texto puro; nada novo entra em e-mail, WhatsApp ou log. |
| R9 | `pay()` repetido numa quota já paga que traga foto nova passa a **apagar a foto anterior** (corrige 2.3). O contrato de `pay()` não muda. |
| R10 | Quota nascida paga (`born_paid`, parcela retroativa) é editável pelo credor, com as mesmas regras de `unpay()`. |

### 3.3 Decisões em aberto

A fechar na aprovação deste documento.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como a API recebe a edição | A) reaproveitar `POST /expenses/{id}/pay` numa quota já paga<br>B) rota nova `POST /expenses/{id}/payment-proof` | **B** | `pay()` tem efeitos de transição (notifica, sela o ciclo) que a edição não deve ter, e seu contrato é permissivo por compatibilidade. Uma rota própria deixa a regra de edição explícita. Precisa ser `POST` e não `PUT`, porque o PHP só preenche arquivos enviados (`multipart`) em requisições `POST`. |
| D2 | Poder remover a foto ou limpar a descrição | A) sim, desde que o resultado final tenha pelo menos um dos dois<br>B) não, só substituir | **A** | O credor pode ter anexado a foto errada. A regra "foto OU descrição" continua valendo no estado final: não deixa salvar sem nenhum dos dois. |
| D3 | Avisar os pagadores por WhatsApp ao trocar a foto | A) sim, mesmo gatilho do `pay()` com foto<br>B) não, só no primeiro pagamento | **B** | Trocar a foto não é um fato novo para quem deve, e o re-`pay()` de uma quota já paga já não gera `Notifier::expensePaid`. |
| D4 | Quota de ciclo **selado** | A) permitir e regravar o snapshot<br>B) bloquear com 422; para corrigir, o credor desfaz o pagamento, o que já dessela | **B** | O ciclo selado é histórico congelado (`20260902-pagamento-ciclo-fechado` §2.3). Regravar o snapshot altera um registro que a regra diz ser imutável. Na tela, o botão "Editar comprovante" não aparece quando `summary.cycle.settled` é verdadeiro. |
| D5 | O servidor exige "foto OU descrição" no estado final da rota nova | A) sim, 422 se o resultado ficar sem nenhum dos dois<br>B) não, só a interface exige (como em `pay()`) | **A** | Em `pay()` a permissividade existe por compatibilidade com clientes antigos (feature `20261021`, D1). A rota é nova e não há cliente antigo a preservar; sem a exigência, uma chamada direta deixaria uma despesa paga sem nenhuma informação. |
| D6 | Onde fica a ação na interface | A) ícone "Editar comprovante" ao lado de "Desfazer pagamento" em Despesas e botão no cartão de Pagamentos<br>B) só no modal de detalhes | **A** | Mesmo lugar das demais ações de pagamento, e o credor não precisa abrir o modal. Só aparece para o credor, com a despesa paga e o ciclo não selado. |

### 3.4 Documentação

`MANUAL.md` e `MANUAL.pdf` passam a descrever a edição do comprovante depois de paga. O item de backlog novo para o achado 2.5 (link do comprovante expirando em ciclo selado) é registrado como task de documentação desta feature, e o item 084 do backlog vai para `docs/backlog/concluidos/` ao fim.

## 4. Fora de escopo desta feature

- **Link do comprovante expirando em ciclo selado** (2.5): defeito pré-existente com causa própria (snapshot guarda URL assinada). Vira item de backlog, com solução decidida lá.
- **Editar o comprovante do devedor** (acerto): já existe o reenvio (`confirmSettlement`, `updateOrCreate`), que substitui foto e descrição.
- **Notificações** de qualquer tipo ao editar (D3 e R2).
- **Histórico de alterações** do comprovante (quem trocou o quê e quando): não há tabela de auditoria hoje e esta feature não cria uma.
- **Pagamento individual por despesa** (item 075) e a **corrida entre pagamento e edição de despesa**: itens de backlog próprios, com decisões abertas próprias.
- **Mudar o contrato de `pay()`**: segue permissivo (a única mudança nele é R9, que apaga a foto anterior).

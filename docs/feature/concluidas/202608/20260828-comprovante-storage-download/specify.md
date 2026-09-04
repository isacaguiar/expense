# Specify — Storage e download de comprovante de pagamento

> Feature: comprovantes de pagamento (do credor e do devedor) param de 404 na hora de visualizar, e passam a ser guardados por grupo em disco privado e servidos por download autenticado. Pedido novo, levantado em 2026-08-28 a partir de "na tela de Pagamentos o upload do comprovante não está funcionando".

Versão: 1.0 · Criado em: 20260828

---

## 1. Problema

Na Tela de Pagamentos, anexar o comprovante funciona (o arquivo é gravado e o path vai para o banco), mas **clicar em "Ver comprovante" retorna 404** — nos dois fluxos: o do credor (`ExpenseController@pay`, `ex_quotas.payment_proof_path`) e o do devedor confirmando o Pix (`ExpenseController@confirmSettlement`, `ex_settlement_confirmations.proof_path`).

Causa raiz: `Quota::getPaymentProofUrlAttribute()` e `SettlementConfirmation::getProofUrlAttribute()` retornam `Storage::disk('public')->url($path)`, gerando `https://expense-api.novemax.com.br/storage/...`. Essa URL só resolve se existir o symlink `public/storage → storage/app/public` (`php artisan storage:link`). O `.github/workflows/deploy-backend.yml` não roda `storage:link`, o deploy é um rsync de `backend/` empacotado e enviado por FTP (symlink não é transportado e o destino FTP normalmente não cria symlink), e `backend/.gitignore` lista `/public/storage` como artefato gerado. Em produção o symlink não existe → todo `proof_url` 404.

Além do 404, o armazenamento atual tem duas fraquezas que a correção deve resolver de uma vez:

- Comprovante é dado financeiro, mas hoje fica em disco `public` — acessível por URL direta, sem autenticação, contrariando o espírito de `00-constitution.md` §5.5.
- Todos os comprovantes caem em dois diretórios planos (`comprovantes/`, `comprovantes-settlements/`), sem separação por grupo.

## 2. Requisitos

### 2.1 Armazenamento por grupo em disco privado

Comprovante novo (fluxo do credor e do devedor) é gravado no disco `local` (privado, `storage/app/`), em `comprovantes/<groupId>/<uuid>.<ext>`:

- `<groupId>` é o id do grupo dono do recurso (`$expense->group_id` no fluxo do credor; o `$groupId` da rota no fluxo do devedor).
- `<uuid>` gerado (`Str::uuid()`); a extensão do arquivo enviado é preservada.
- O diretório do grupo é criado sob demanda na primeira gravação.
- Nada de novo vai para o disco `public`.
- As colunas `ex_quotas.payment_proof_path` e `ex_settlement_confirmations.proof_path` já existem (varchar) e continuam guardando o path relativo — **sem migration de schema**. Só muda o valor gravado (agora com o segmento `<groupId>`).

### 2.2 Rota de download autenticada por URL assinada

Uma rota nova serve o arquivo:

```
GET /api/groups/{groupId}/proofs/{type}/{id}     type ∈ {quota, settlement}
```

- Fica **fora** do grupo de middleware `jwt.auth` (uma aba de browser aberta por `<a target="_blank">` não manda `Authorization: Bearer`), protegida pelo middleware `signed`.
- O controller carrega o recurso (`Quota` ou `SettlementConfirmation`) pelo `id` e confirma que ele pertence ao `{groupId}` da URL — senão 404 (evita enumeração de id de outro grupo).
- Resolve o disco pelo formato do path: path com segmento de grupo → disco `local` (novo); path legado (`comprovantes/xxx` ou `comprovantes-settlements/xxx`, sem grupo) → disco `public`.
- `Storage::disk($disk)->exists($path)` senão 404; devolve `Storage::disk($disk)->download($path)`.

A autorização efetiva é a assinatura (só emitível dentro de um contexto já autenticado e com checagem de membership) + a expiração curta + a revalidação recurso×grupo no controller. Isso tangencia o texto de `00-constitution.md` §5.5 ("rota deve estar dentro do grupo `jwt.auth`") e por isso vem acompanhado de um ADR e de um ajuste no §5.5 (ver `plan.md`).

### 2.3 URL assinada temporária nos accessors dos models

`Quota::getPaymentProofUrlAttribute()` e `SettlementConfirmation::getProofUrlAttribute()` passam a retornar `URL::temporarySignedRoute('proofs.show', now()->addMinutes(30), [...])` apontando para a rota de 2.2, em vez de `Storage::disk('public')->url()`.

- Cobre tanto os consumidores que serializam o model cru (`ExpenseView.tsx` lê `q.payment_proof_url`) quanto os campos decorados nos controllers (`paymentProofUrl`, `confirmedProofUrl`, que leem `$quota->payment_proof_url` / `$confirmation->proof_url`).
- No `Quota`, o `groupId` vem de `$this->expense->group_id` — os pontos que serializam `Quota` com o comprovante precisam ter a relação `expense` eager-carregada (evitar N+1).

### 2.4 Limpeza de arquivo

- `ExpenseController@unpay` deleta o arquivo do disco certo (novo → `local`; legado → `public`) ao desfazer o pagamento, e zera a coluna — hoje só tenta `Storage::disk('public')->delete()`.
- `ExpenseController@confirmSettlement`, ao substituir um comprovante já existente do mesmo settlement (`updateOrCreate`), apaga o arquivo anterior antes de gravar o novo — hoje o arquivo antigo vaza no disco.

### 2.5 Comprovantes legados de produção

Não há migração de arquivos. Registros pré-existentes (path no disco `public`, sem segmento de grupo) continuam funcionando porque a rota de 2.2 resolve os dois formatos. Layout novo vale só para uploads a partir da entrega desta feature.

### 2.6 Frontend

Nenhuma mudança de comportamento no frontend: `Payments.tsx`, `ExpenseManager.tsx`, `ExpenseView.tsx` e `PayableSettlementList.tsx` já renderizam `<a href={...proofUrl} target="_blank">`. O campo passa a conter a URL assinada. Ajustar apenas mocks de teste que assertem o formato antigo (`/storage/...`), se houver.

## 3. Fora de escopo desta feature

- Aceitar PDF (ou outro tipo além de `image`) no upload do comprovante — hoje a validação é `image|max:5120` e continua assim. Candidato a `docs/backlog/`.
- Adicionar `php artisan storage:link` ao deploy — a feature torna o disco `public` desnecessário para comprovante; mexer no deploy por outro motivo é assunto à parte.
- Endpoint de "desfazer confirmação de settlement" (já fora de escopo desde `docs/feature/concluidas/202608/20260825-pagamentos-grid-pix/specify.md` §4).
- Retrofit das telas de comprovante do `ExpenseManager`/`ExpenseView` além de consumirem o novo campo de URL.
- Migração dos arquivos de comprovante legados para o novo layout.

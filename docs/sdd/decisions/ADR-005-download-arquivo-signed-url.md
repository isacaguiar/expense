# ADR-005: Download de arquivo servido por URL assinada, fora do `jwt.auth`

Status: Aceita
Data: 2026-08-28

## Contexto

Comprovantes de pagamento (do credor via `pay()`, do devedor via `confirmSettlement()`) precisam ser exibidos por um link "Ver comprovante" que abre em nova aba (`<a target="_blank">`). Uma aba de browser aberta assim **não envia o header `Authorization: Bearer`** — o frontend guarda o JWT em `localStorage` e só o injeta em chamadas `fetch`/`axios`, não em navegação de link.

A Constitution §6.5 exige que "toda rota que expõe dado financeiro ou pessoal esteja dentro do grupo `jwt.auth`". Uma rota de download de arquivo consumível por `<a href>` não consegue satisfazer isso ao pé da letra: se estivesse sob `jwt.auth`, o link simplesmente responderia 401.

O comportamento anterior (servir via disco `public` + `Storage::url()`) contornava o problema deixando o comprovante **acessível por URL pública sem nenhuma autenticação** — pior ainda sob a ótica do §6.5 — e além disso dependia do symlink `public/storage`, que não existe em produção (deploy FTP), causando 404 (ver `docs/feature/concluidas/202608/20260828-comprovante-storage-download/specify.md` §1).

## Decisão

Uma rota cujo **único propósito é entregar um arquivo** a uma aba do browser pode ficar **fora** do grupo `jwt.auth`, desde que **todas** as condições abaixo sejam satisfeitas:

1. A rota é protegida pelo middleware `signed` (assinatura HMAC da URL, verificada pelo Laravel).
2. A URL é **temporária** (`URL::temporarySignedRoute` com expiração curta — 30 min no caso do comprovante).
3. A URL só é **emitida** dentro de um contexto já autenticado (`jwt.auth`) e que já fez a checagem de relação usuário↔recurso — no caso, os accessors `Quota::payment_proof_url` / `SettlementConfirmation::proof_url`, alcançados apenas por endpoints sob `jwt.auth` que já validam membership no grupo.
4. O controller de download **revalida** que o recurso pertence ao escopo declarado na própria URL (o `{groupId}` do path bate com o grupo do `Quota`/`SettlementConfirmation`), respondendo 404 de forma indistinta para recurso inexistente, de outro grupo, ou sem arquivo — sem vazar qual dos três é.
5. A resposta entrega **apenas o binário do arquivo**, nenhum outro dado.

Este é o mesmo mecanismo que o Laravel usa para `signed`/`temporarySignedRoute` em verificação de e-mail e para links de download assinados — padrão consolidado do framework, não invenção deste projeto.

Aplicação concreta: rota `proofs.show` (`GET /api/groups/{groupId}/proofs/{type}/{id}`), `ProofDownloadController@show`, disco privado `local` com `comprovantes/<groupId>/<uuid>.<ext>` (`docs/feature/concluidas/202608/20260828-comprovante-storage-download/plan.md` §2).

## Consequências

- A Constitution §6.5 ganha um parágrafo com essa exceção estrita (bump 1.2 → 1.3). Fora dessas condições, a regra "dentro do `jwt.auth` + checagem de membership no controller" continua valendo integralmente.
- Comprovante deixa de ser acessível por URL pública perpétua — passa a exigir uma assinatura válida e não expirada, emitida a um membro do grupo.
- Sem dependência do symlink `public/storage` — o 404 de produção some.
- Custo: um link "Ver comprovante" copiado e colado funciona por até 30 min para quem tiver a URL. Aceito: é um comprovante de pagamento entre membros de um grupo, a janela é curta, e a alternativa (fetch+blob) tem custo de UX maior sem eliminar a possibilidade de o usuário salvar/compartilhar o arquivo depois de aberto.
- A exceção é **para download de arquivo**. Não autoriza expor JSON de domínio, mutação, nem qualquer endpoint que faça mais do que devolver um binário, fora do `jwt.auth`.

## Alternativas consideradas

- **Frontend faz `fetch(url, { headers: Authorization })` → `response.blob()` → `URL.createObjectURL`**: mantém a rota sob `jwt.auth` e não mexe na Constitution. Rejeitada — espalha manuseio de binário + token por todos os pontos de link (`Payments`, `ExpenseManager`, `ExpenseView`, `PayableSettlementList`), exige um helper de download e tratamento de erro em cada um, e não traz ganho de segurança real sobre uma signed URL de 30 min emitida só a membro do grupo.
- **Manter disco `public` + rodar `php artisan storage:link` no deploy**: rejeitada — o symlink não sobrevive ao deploy FTP e o comprovante continuaria world-readable por URL, contra o §6.5.
- **Signed URL sem expiração**: rejeitada — uma URL assinada permanente é quase tão exposta quanto a URL pública que ela substitui.

## Referências

- `docs/sdd/00-constitution.md` §6.5 (parágrafo da exceção) e linha de versão (1.3).
- `docs/feature/concluidas/202608/20260828-comprovante-storage-download/` — `specify.md` §2.2, `plan.md` §2 e §5, `tasks.md` TASK-219/TASK-224.
- `docs/sdd/decisions/ADR-004-fluxo-bugfix.md` — precedente de exceção estrita registrada com ADR + ajuste de texto da Constitution.

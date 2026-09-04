# Tasks — Storage e download de comprovante de pagamento

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260828

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-218 | Criar `App\Support\ProofStorage` (store, resolveDisk, delete) | backend | plan.md §1 | nenhum | Pendente |
| TASK-219 | Adicionar rota `proofs.show` (fora de `jwt.auth`, `signed`) e `ProofDownloadController@show` | backend | plan.md §2 | nenhum | Pendente |
| TASK-220 | Migrar gravação/remoção de comprovante em `pay`/`unpay`/`confirmSettlement` para disco privado por grupo via `ProofStorage` | backend | plan.md §1, §2.4 | nenhum | Pendente |
| TASK-221 | Fazer os accessors `payment_proof_url` / `proof_url` emitirem `URL::temporarySignedRoute` para `proofs.show`, com `expense` eager-carregada nos pontos que serializam `Quota` | backend | plan.md §3 | nenhum | Pendente |
| TASK-222 | Escrever testes de feature (gravação por grupo, download assinado, resolução de path legado, `unpay`, reenvio de settlement) | backend | plan.md §6 | nenhum | Pendente |
| TASK-223 | Verificar frontend e ajustar mocks de teste que assumem o formato `/storage/...` | frontend | plan.md §4 | nenhum | Pendente |
| TASK-224 | Redigir `ADR-005-download-arquivo-signed-url.md`, ajustar `00-constitution.md` §5.5 (bump 1.2→1.3) e indexar em `decisions/README.md` | doc | plan.md §5 | antes do merge (edição da Constitution) | Pendente |

## Critérios de aceite

- **TASK-218**: `ProofStorage::store(UploadedFile, int $groupId)` grava em `comprovantes/<groupId>/<uuid>.<ext>` no disco `local` e devolve o path; `resolveDisk($path)` devolve `local` para path com `^comprovantes/\d+/` e `public` para path legado; `delete($path)` remove do disco resolvido. Teste unitário cobrindo os três, com `Storage::fake`.
- **TASK-219**: `GET /api/groups/{groupId}/proofs/{type}/{id}` existe, está fora do grupo `jwt.auth`, tem middleware `signed` e nome `proofs.show`. Com assinatura válida e recurso pertencente ao grupo: responde 200 com o arquivo. Sem assinatura ou com assinatura adulterada: 403. `type` diferente de `quota`/`settlement`: 404. Recurso de outro grupo: 404.
- **TASK-220**: após `pay()` com `comprovante`, `ex_quotas.payment_proof_path` = `comprovantes/<groupId>/<uuid>.<ext>` e o arquivo existe no disco `local` (nada gravado em `public`). `unpay()` remove o arquivo (path novo e path legado) e seta a coluna para `null`. `confirmSettlement()` reenviado para a mesma chave remove o arquivo anterior antes de gravar o novo. Verificável por teste de feature com `Storage::fake`.
- **TASK-221**: `GET /expenses/{id}` e as respostas de ciclo da Tela de Pagamentos trazem `payment_proof_url` / `paymentProofUrl` / `confirmedProofUrl` como URL absoluta para `proofs.show` que passa em `URL::hasValidSignature`. Sem comprovante: campo `null`. Nenhum N+1 novo em `Quota` (assert de contagem de query ou `DB::listen` no teste, ou revisão do `with('expense')` nos pontos citados no plan).
- **TASK-222**: `php artisan test --filter=Proof` (ou a suíte da feature) verde, cobrindo todos os casos de `plan.md` §6, incluindo o path legado gravado à mão em `public` resolvendo 200.
- **TASK-223**: `cd frontend && npx tsc --noEmit` limpo e `npx vitest run` verde; nenhum teste depende do formato `/storage/...` da URL do comprovante.
- **TASK-224**: `ADR-005` existe com as 5 seções do formato de `decisions/README.md`; `00-constitution.md` §5.5 tem o parágrafo da exceção e `Versão: 1.3`; `decisions/README.md` lista ADR-005. Diff do §5.5 aprovado pelo dono do projeto antes do merge do PR da feature.

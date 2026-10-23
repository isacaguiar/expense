# Tasks — Editar comprovante e descrição depois de paga

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261022

IDs a partir de TASK-449: o maior em qualquer branch do repositório é TASK-448 (feature `20261021-comprovante-ao-marcar-paga`, já em `dev`). Reconfirmar a numeração se outra feature em andamento reservar IDs antes do merge. Atenção: a branch `frontend/20261010-painel-financeiro-pessoal-TASK-433` (feature do painel financeiro, ainda não mergeada) usa um ID que já foi tomado e mergeado em `dev`; quem continuar aquela feature precisa renumerar.

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-449 | Fazer `pay()` repetido apagar a foto anterior, com testes PHPUnit | backend | plan.md §2 e §7 | nenhum | Concluída |
| TASK-450 | Criar a rota `POST /expenses/{id}/payment-proof` (editar foto e descrição de despesa paga), com testes PHPUnit | backend | plan.md §1 e §7 | nenhum | Concluída |
| TASK-451 | Revisar o diff de backend com o agent `security-reviewer` e registrar os achados | backend | plan.md §8 | nenhum | Concluída |
| TASK-452 | Acrescentar `canEditProof` e `handleEditProof` ao `usePaymentActions`, com testes | frontend | plan.md §3 e §7 | nenhum | Concluída |
| TASK-453 | Dar ao `PaymentProofDialog` o modo de edição (foto atual, remover foto, descrição pré-preenchida), com testes | frontend | plan.md §4 e §7 | nenhum | Concluída |
| TASK-454 | Oferecer "Editar comprovante" na tela Despesas (ícone, popup e aviso de sucesso), com testes | frontend | plan.md §5 e §7 | nenhum | Concluída |
| TASK-455 | Oferecer "Editar comprovante" no cartão da tela Pagamentos, com testes | frontend | plan.md §6 e §7 | nenhum | Concluída |
| TASK-456 | Atualizar `MANUAL.md` com a edição do comprovante depois de paga | doc | plan.md §9 | nenhum | Concluída |
| TASK-457 | Versionar a fonte do manual em `docs/manual/` (HTML, capturas e script de geração) e provar que reproduz o PDF atual | doc | plan.md §9 e §11 (T1) | nenhum | Pendente |
| TASK-458 | Acrescentar a edição de comprovante à fonte do manual e regenerar `MANUAL.pdf` | doc | plan.md §9 | nenhum | Pendente |
| TASK-459 | Registrar em `docs/backlog/` o item "link do comprovante expira em ciclo selado" | doc | plan.md §9 e specify.md §2.5 | nenhum | Concluída |
| TASK-460 | Rodar o checklist pré-PR completo na branch da feature integrada | backend | plan.md §7 e §12 | nenhum | Pendente |
| TASK-461 | Validar o fluxo localmente na stack isolada e registrar o resultado | frontend | plan.md §12 | antes do merge (o usuário valida localmente antes do push e do PR) | Pendente |
| TASK-462 | Fechar a corrida entre editar e desfazer em `updatePaymentProof` (travar a quota e reler o estado dentro de uma transação), com testes PHPUnit | backend | plan.md §1 e §10 (achado B2 da TASK-451) | nenhum | Concluída |
| TASK-463 | Recusar com 500, antes de qualquer escrita, quando o disco não grava o comprovante (`store` devolve `false`) em `updatePaymentProof` e em `pay()`, com testes PHPUnit | backend | plan.md §1 e §2 (achado B3 da TASK-451) | nenhum | Concluída |
| TASK-464 | Registrar em `docs/backlog/` o item "endurecer o upload e o download de comprovante" (SVG aceito, extensão do cliente, cabeçalhos do download, caminho interno na resposta, `cycles_ago` sem teto) | doc | plan.md §8 e §9 (achados B1, B4, B5 e B6 da TASK-451) | nenhum | Concluída |

Notas de composição:

- **TASK-450 é uma task só** de propósito: validação, ordem das recusas, gravação com limpeza e resposta são o mesmo contrato; dividir deixaria uma rota meio pronta e testes que dependem dela.
- **TASK-457 e TASK-458 são separadas** porque a primeira não muda nenhuma palavra do manual (é só tornar o PDF reproduzível, com prova), e a segunda muda o conteúdo. Assim, se a regeneração falhar em reproduzir o PDF atual, isso aparece antes de qualquer mudança de texto.
- **TASK-451 e TASK-460 são tasks de verificação**, sem escopo de funcionalidade: o que elas acharem e exigir correção vira task nova abaixo de TASK-461, não expande a task que a originou (`docs/sdd/04-implementation.md` §1, item 2).

## Dependências e ordem

```
449 ── 450 ── 451
450 ── 462 ── 463   (462 e 463 mexem no mesmo método; nasceram na revisão da TASK-451)
452 ┐
453 ┴─ 454 ── 455
454, 455 ── 456
[PR #224 mergeado + merge de dev na branch] ── 457 ── 458 (458 também depende de 456)
459, 464 (independentes)
todas ── 460 ── 461
```

- TASK-449 e TASK-450 mexem no mesmo controller, então ficam em sequência. TASK-452 e TASK-453 são independentes entre si e do backend (os testes usam mock); TASK-454 e TASK-455 dependem das duas.
- **Pré-requisito de TASK-457 e TASK-458**: o PR [isacaguiar/expense#224](https://github.com/isacaguiar/expense/pull/224) (que regenera `MANUAL.pdf`) precisa estar mergeado em `dev`, e a branch da feature precisa receber esse `dev` (`git merge dev`). Os dois PRs alteram o mesmo arquivo binário e, sem isso, o PR desta feature conflita em `MANUAL.pdf`. Se o #224 ainda não tiver sido mergeado quando as demais tasks terminarem, parar antes da TASK-457 e pedir o merge a você.
- Fluxo de branch conforme `docs/sdd/04-implementation.md` §1: TASK-449 direto em `feature/20261022-editar-comprovante-depois-de-paga` (já criada, com specify e plan); as demais em `<tipo>/20261022-editar-comprovante-depois-de-paga-TASK-0xx` a partir da branch da feature, integradas com `git merge --no-ff`, sem PR. Um único PR da branch da feature contra `dev` no fim.
- Sem migration (`payment_proof_path` e `payment_note` já existem), então não há gate de banco.

## Critérios de aceite

- **TASK-449**:
  - Testes novos em `ExpenseControllerPayTest.php`: (a) `pay` repetido com foto nova numa quota que já tinha foto: a nova existe em `Storage::disk('local')`, a anterior **não existe mais** (`assertMissing`) e `payment_proof_path` aponta para a nova; (b) `pay` repetido **sem** foto: a foto existente continua no disco e o caminho não muda; (c) o primeiro `pay` com foto continua gravando normalmente. O teste (a) é visto falhando antes da correção e o resultado é registrado.
  - `cd backend && php artisan test --filter=ExpenseControllerPayTest` verde, e os testes que já existiam no arquivo passam sem edição (incluindo `pay` sem corpo respondendo 200).
  - O docblock de `pay()` cita que a foto anterior é apagada. `./vendor/bin/pint --test` limpo.
- **TASK-450**:
  - `cd backend && php artisan route:list --path=payment-proof -v` lista `POST api/expenses/{expenseId}/payment-proof` com o middleware `jwt.auth`.
  - `php artisan test --filter=ExpenseControllerPaymentProofTest` verde, em arquivo novo, cobrindo: (1) anexar foto a quota paga só com texto, mantendo a descrição e com `paid_at` e `paid_by` iguais; (2) trocar a foto, com a antiga apagada do disco; (3) remover a foto mantendo a descrição; (4) editar só a descrição, com o arquivo intocado; (5) limpar a descrição mantendo a foto; (6) `descricao` igual a `"0"` gravada; (7) corpo vazio responde 200 e não muda nada; (8) remover a foto sem descrição, e limpar a descrição sem foto, respondem 422 e a foto antiga continua no disco; (9) foto junto de `remover_comprovante` responde 422; (10) arquivo que não é imagem, foto acima de 5 MB, 501 caracteres e `descricao` em array respondem 422; (11) quota não paga e `FIXED` sem ocorrência materializada respondem 422 **sem criar quota**; (12) não credor recebe 403 e não membro recebe 404; (13) ciclo selado responde 422 com snapshot e quota intactos; (14) ciclo fechado por data e fechado manualmente, ainda não selados, respondem 200; (15) `cycles_ago` edita a quota do ciclo pedido e não a do atual; (16) quota `born_paid` responde 200; (17) nenhuma notificação: `Http::assertNothingSent()` e a contagem de `ex_notifications` não muda; (18) falha no `update` (simulada com `Quota::updating` lançando exceção, limpa com `Quota::flushEventListeners()`) apaga o arquivo novo e preserva o antigo.
  - Em todo caso de recusa (404, 403 e os 422), `Storage::disk('local')->allFiles()` fica igual ao do início do teste: nada é gravado antes da recusa.
  - Regressão: `--filter="ExpenseControllerPayTest|SettlementConfirmationControllerTest|ExpenseControllerSummaryTest"` verde.
  - O diff não tem arquivo novo em `backend/database/migrations/`. `./vendor/bin/pint --test` limpo.
- **TASK-451**:
  - O agent `security-reviewer` roda sobre o diff de `backend/` desta feature e o resultado é registrado no `implementation.md`, achado por achado, com veredito (corrigir, aceitar ou backlog) e justificativa.
  - Itens conferidos explicitamente: rota dentro de `jwt.auth`; membro e credor verificados antes de qualquer escrita; nenhum IDOR (a quota sai da despesa autorizada); validação do arquivo igual à de `pay()`; nenhuma gravação em disco antes das recusas; `ProofStorage::delete` só recebe caminho vindo do banco, nunca da requisição; a resposta não expõe nada além do que `unpay` já expõe; sem canal novo para a descrição (e-mail, WhatsApp, log).
  - Achado que exige correção vira task nova (TASK-462 em diante), não se resolve dentro desta task.
- **TASK-452**:
  - `cd frontend && npx vitest run src/hooks/usePaymentActions.test.tsx` verde, com casos novos que provam: (a) `canEditProof` é verdadeiro **só** para credor, despesa paga, ciclo não futuro e não selado, e falso para despesa de outro credor, despesa pendente, ciclo `future` e `cycle.settled === true`; (b) `handleEditProof` sem foto envia JSON `{ cycles_ago, descricao }` e, com `removeProof`, acrescenta `remover_comprovante: true`; (c) com foto envia `FormData` com `comprovante`, `cycles_ago`, `descricao` e, com `removeProof`, `remover_comprovante` igual a `'1'`; (d) `descricao` vai sempre, inclusive vazia, e aparada; (e) a URL é `/api/expenses/{id}/payment-proof`; (f) no sucesso `editSuccess` fica verdadeiro e `reload` é chamado; (g) o erro da API aparece em `payError`, com mensagem padrão "Falha ao atualizar o comprovante.".
  - Os testes que já existiam no arquivo passam sem edição. `npx tsc --noEmit` sem erro e sem `any` novo.
- **TASK-453**:
  - `cd frontend && npx vitest run src/components/PaymentProofDialog.test.tsx` verde, com casos novos para o modo de edição: (a) a descrição abre pré-preenchida com `initialNote`; (b) com `currentProofUrl` aparece o link "Ver foto atual" e o checkbox "Remover a foto atual"; (c) marcar o checkbox limpa a foto escolhida e escolher uma foto desmarca o checkbox; (d) sem nenhuma alteração o botão fica desabilitado; (e) remover a foto atual e esvaziar a descrição desabilita o botão; (f) trocar só a descrição habilita; (g) `onConfirm` recebe `removeProof` e o botão usa o `confirmLabel` ("Salvar"); (h) reabrir zera as escolhas; (i) `canConfirmPayment` com os argumentos novos (`hasCurrentProof`, `removeProof`, `changed`).
  - Compatibilidade: os testes que já existiam do componente passam **sem edição**, e `PaymentProofValue.removeProof` só vem preenchido no modo de edição (as asserções `toHaveBeenCalledWith({ file, note })` seguem verdes).
  - `npx tsc --noEmit` sem erro, sem `any` novo e `grep -rn dangerouslySetInnerHTML frontend/src` vazio. Nenhuma tela foi alterada nesta task.
- **TASK-454**:
  - `cd frontend && npx vitest run src/pages/ExpenseManager.test.tsx` verde, com casos novos: (a) o ícone "Editar comprovante" aparece para o credor numa despesa paga, na tabela **e** nos cartões, antes de "Desfazer pagamento"; (b) não aparece em despesa pendente, em despesa de outro credor nem quando `cycle.settled` é verdadeiro; (c) clicar abre o popup "Editar comprovante" já preenchido com a descrição e a foto atual da despesa; (d) salvar envia `POST .../payment-proof` com o corpo certo (JSON sem foto, `FormData` com foto, `remover_comprovante` quando marcado) e `cycles_ago`; (e) aparece o aviso "Comprovante atualizado."; (f) o erro da API aparece no Snackbar; (g) o alvo do popup vem da lista atual, não de um objeto antigo.
  - Os testes que já existiam passam sem edição. `npx tsc --noEmit` limpo. Nenhum teste do `Payments` é alterado nesta task.
- **TASK-455**:
  - `cd frontend && npx vitest run src/pages/Payments.test.tsx` verde, com casos novos: (a) o botão "Editar comprovante" aparece no cartão da despesa paga, para o credor, antes de "Desfazer pagamento"; (b) não aparece para outro credor, para despesa pendente nem em ciclo selado; (c) abre o popup preenchido; (d) salvar faz `POST .../payment-proof` e mostra "Comprovante atualizado."; (e) o popup de "Confirmar pagamento" continua independente (abrir um não afeta o outro).
  - Os testes que já existiam passam sem edição. `npx tsc --noEmit` limpo.
- **TASK-456**:
  - `MANUAL.md` descreve, na seção Despesas, o ícone "Editar comprovante" (acrescentar ou trocar a foto, remover a foto, ajustar a descrição, sem desfazer o pagamento) e que ele não aparece em ciclo já encerrado, e, na seção Pagamentos, o botão equivalente no cartão. Texto em português, no tom das seções vizinhas, e preservando a quebra de linha CRLF do arquivo.
  - `grep -n -i "comprovante" MANUAL.md` não deixa nenhuma frase que diga que depois de paga a despesa não aceita mais ajuste do comprovante.
- **TASK-457**:
  - Pré-requisito verificado e registrado: PR #224 mergeado e a branch da feature com o `dev` mergeado (`git log` mostra o commit do #224).
  - Existem `docs/manual/manual.html`, `docs/manual/images/` (as 7 capturas: `01-login`, `02-meus-grupos`, `03-resumo`, `05-despesas`, `06-nova-despesa`, `07-pagamentos`, `08-relatorios`, em `.jpg`), `docs/manual/gerar-pdf.ps1` e `docs/manual/README.md` com o comando de geração, os requisitos (Chrome ou Edge e as fontes Georgia, Segoe UI e Consolas) e a origem das capturas.
  - **Prova de reprodutibilidade**: rodar o script, sem mudar nenhuma palavra do HTML, e comparar o PDF gerado com o `MANUAL.pdf` de `dev`: mesmo número de páginas, página Letter e `pdftotext -raw` com espaços normalizados **idêntico**. O resultado (comandos e saída) vai para o `implementation.md`. O PDF gerado nesta task não é commitado.
  - `MANUAL.md` passa a apontar as imagens para `docs/manual/images/`, e um `ls` confirma que cada um dos 7 caminhos citados existe. `git ls-files docs/manual` mostra só os arquivos previstos.
- **TASK-458**:
  - `docs/manual/manual.html` ganha o texto da edição nos parágrafos de Despesas e de Pagamentos, com o mesmo conteúdo da TASK-456.
  - `MANUAL.pdf` é regenerado **pelo script versionado**. A comparação de texto frase a frase entre o PDF anterior e o novo mostra mudanças só nesses dois parágrafos. Número de páginas e tamanho registrados; as páginas 4 e 5 são renderizadas e conferidas visualmente; `pdffonts` confirma Georgia, Segoe UI e Consolas embutidas.
  - Uma frase-chave da edição aparece igual em `MANUAL.md` e no texto extraído do PDF (conferido com `grep`).
- **TASK-459**:
  - Existe um arquivo novo em `docs/backlog/`, no formato de `docs/backlog/README.md`, com contexto, risco, **regras adotadas** e **decisões em aberto** em tabela (opções, recomendação e por quê). Descreve que o snapshot de ciclo selado congela `expenses[].paymentProofUrl`, URL assinada de 30 minutos, e cita `ExpenseController.php` (`summary()` devolvendo `$snapshot->expenses`) e `Quota.php` (`getPaymentProofUrlAttribute`) com linhas conferidas no código antes de escrever. Opções de solução listadas: renovar as URLs ao servir o snapshot, guardar só o id da quota e resolver na leitura, ou prazo maior.
  - O ID é o próximo livre depois de reconfirmar o maior em `dev` e nas branches locais e remotas, e há a linha correspondente no índice `docs/backlog/README.md`. **Reconfirmado em 2026-10-23: o maior em `dev` é 084, mas a branch `feature/20261010-painel-financeiro-pessoal` (ainda não mergeada) já usa de 085 a 095, então o próximo livre é 096** (a previsão de 085 deste critério estava desatualizada).
  - A reprodução do defeito (abrir o link do `summary()` 31 minutos depois da selagem e obter 403) foi feita num teste PHPUnit descartável, não commitado, e o resultado entra no item.
- **TASK-460**:
  - Na branch da feature integrada, os comandos abaixo rodam e o resultado de cada um é registrado no `implementation.md`: `cd backend && ./vendor/bin/pint --test`; `php artisan test` (suíte completa, contra MySQL descartável na 3307); `cd frontend && npx tsc --noEmit`; `npx vitest run` (suíte completa); `grep -rn dangerouslySetInnerHTML frontend/src` vazio.
  - `git diff dev...HEAD` revisado: nenhum segredo, nenhum `.env.testing`, nenhum arquivo fora do escopo, nenhum `git add -A`. O agent `pr-readiness-checker` roda e seu relatório é anexado ao `implementation.md`. Falha não trivial vira task nova (TASK-462 em diante).
- **TASK-461**:
  - Roteiro executado na stack isolada (MySQL 3307, backend 8002, Vite 3002 — a 8001/3001 estavam com a stack de outra sessão em 2026-10-22, ver `implementation.md` §1; nunca a 3000/8000 do usuário nem as portas de outra sessão), cada passo conferido na tela **e** no banco, com o que foi observado registrado no `implementation.md`: (1) o credor numa despesa paga só com texto vê o ícone "Editar comprovante" (tabela e cartões a 375px), abre o popup preenchido, anexa uma foto e passa a ver "Ver comprovante" com a descrição preservada e `paid_at`/`paid_by` iguais; (2) trocar a foto apaga a antiga do disco e o link novo baixa `200 image/png`; (3) remover a foto mantendo a descrição, esvaziar a descrição mantendo a foto, e remover a foto com descrição vazia (botão desabilitado); (4) sem alteração o botão fica desabilitado; (5) o mesmo fluxo no cartão de Pagamentos; (6) o devedor (outro usuário) não vê o botão, e uma chamada direta à rota com o token dele responde 403; (7) em ciclo selado o botão não aparece e a chamada direta responde 422; (8) depois de editar, "Desfazer pagamento" apaga a foto nova; (9) sem erros novos no console e sem scroll horizontal a 375px.
  - Stack encerrada ao final: processos do backend e do Vite derrubados, `docker rm -f mysql-expense-test`, `backend/.env.testing` apagado (não é ignorado pelo git), portas 3307/8002/3002 livres (as da stack de outra sessão, 8001/3001, não são tocadas), e apagados do disco só os comprovantes da própria validação (os referenciados no banco descartável).
  - O usuário confirma por escrito que validou localmente. Sem essa confirmação, **não há push nem PR**.
- **TASK-462** (nasceu na TASK-451, achado B2; executa depois da TASK-450 e antes da TASK-463):
  - Em `updatePaymentProof`, a busca da quota, as verificações que dependem do estado (quota paga, ciclo não selado, estado final com foto ou descrição) e o `update` passam a rodar dentro de `DB::transaction`, com a quota lida por `lockForUpdate`. O caminho anterior (`$previousPath`) passa a ser o lido sob o lock. Nada de `Quota::where('paid', true)->update(...)` com contagem de linhas afetadas: em MySQL um `update` que não muda nenhum valor devolve 0 e recusaria uma edição legítima sem alteração.
  - Teste novo prova que o lock é tomado: com `DB::listen`, a requisição executa um `select` em `ex_quotas` com `for update`, e `DB::transactionLevel()` nesse instante é **maior** que o nível antes da requisição (a transação é aninhada ao `DatabaseTransactions` do teste).
  - Teste novo: se a quota deixou de estar paga entre a primeira e a segunda leitura (simulado com `Quota::retrieved` desfazendo o pagamento direto no banco depois da leitura sem lock, se ainda houver uma), a resposta é 422 `Esta despesa não está paga nessa competência.`, **nenhum arquivo** fica gravado e a quota não ganha foto nem descrição. Se a implementação final tiver uma única leitura (a travada), o teste usa o desfazer feito por `unpay` antes da requisição e a prova da corrida fica só no teste do lock, com o motivo registrado.
  - Os 29 testes de `ExpenseControllerPaymentProofTest` passam **sem edição**, e `--filter="ExpenseControllerPayTest|SettlementConfirmationControllerTest|ExpenseControllerSummaryTest"` continua verde. `./vendor/bin/pint --test` limpo.
  - Efeito do `unpay` concorrente, aceito e registrado: como ele lê o caminho antes de apagar, no pior caso a foto nova fica órfã (sem inconsistência de estado).
- **TASK-463** (nasceu na TASK-451, achado B3; executa depois da TASK-462):
  - Hoje o disco `local` tem `'throw' => false` e `storeAs` devolve `false` quando a gravação falha (disco cheio, permissão). Em `updatePaymentProof`, a edição seguiria com 200 sem a foto; em `pay()` o `false` iria para a coluna `payment_proof_path`. Passa a haver, logo depois de `ProofStorage::store`, uma recusa `abort(500, 'Não foi possível gravar o comprovante.')` antes de qualquer `update`, nos dois pontos.
  - Testes novos, simulando o disco com falha (um disco registrado com `Storage::set('local', ...)` cujo `putFileAs` devolve `false`): (a) `updatePaymentProof` com foto nova responde 500, a quota fica **igual** (foto, descrição e `paid`) e nenhum arquivo é gravado; (b) o caso "foto nova mais descrição limpa" não deixa a quota sem foto e sem descrição; (c) `pay()` com foto responde 500 e a quota continua **não paga**, com `payment_proof_path` nulo.
  - `--filter="ExpenseControllerPayTest|ExpenseControllerPaymentProofTest"` verde, com os testes que já existiam passando sem edição. `./vendor/bin/pint --test` limpo.
- **TASK-464** (nasceu na TASK-451, achados B1, B4, B5, B6):
  - Existe um arquivo novo em `docs/backlog/`, no formato de `docs/backlog/README.md`, com **regras adotadas** e **decisões em aberto** em tabela (opções, recomendação e por quê), mais a linha no índice. O ID é o próximo livre depois de reconfirmar o maior em `dev` e nas branches locais (a TASK-459 usou o 096, então este é o 097; reconfirmar na hora, porque a branch do painel financeiro pode ter reservado mais IDs).
  - Registra, com arquivo e linha **conferidos no código antes de escrever**: (B1) a regra `image` do Laravel 10 aceita SVG e o download serve inline com o `Content-Type` do conteúdo, sem `X-Content-Type-Options` nem CSP, o que permite script num SVG aberto no domínio da API; (B4) a extensão vem de `getClientOriginalExtension()` e uma extensão longa estoura a coluna `payment_proof_path` (`string(255)`); (B5) `payment_proof_path` (caminho interno) é serializado nas respostas de `pay`, `unpay` e da rota nova; (B6) `cycles_ago` não tem teto nas rotas de pagamento. Opções de solução: trocar `image` por `mimes:jpg,jpeg,png,webp,gif`, usar `guessExtension()`, acrescentar os cabeçalhos no download, esconder o caminho interno.
  - Deixa explícito que remover SVG de `pay()` toca o contrato público (Constitution §4.1) e é decisão humana, e registra a precondição de infraestrutura: a segurança do disco privado depende de o docroot de produção ser `.../api/public` (a memória do deploy diz que é; não foi reverificado ao vivo nesta feature).

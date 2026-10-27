# Bugfix — Comprovante no iPhone abre só a câmera e não deixa escolher foto da galeria nem arquivo

Versão: 1.0 · Criado em: 20261027 · Branch: `fix/20261027-comprovante-iphone-so-camera`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.
>
> Origem: relato do mantenedor em 2026-10-27 ("usando o iPhone, ao tentar adicionar um comprovante ele abre para foto, porém não permite selecionar o arquivo"). Item de backlog correspondente: `docs/backlog/comprovante-capture-impede-escolher-arquivo-no-iphone.md` (ID 105, PR #244). Por ser defeito em comportamento existente, o caminho é este fluxo, e não `/promover-backlog`.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [ ] **Auth / autorização / dado sensível** — a correção prevista é só de cliente: o atributo de um `<input type="file">` no frontend. Nenhuma rota, controller, middleware, regra de autorização ou geração de Pix muda, e ninguém passa a poder fazer o que antes não podia (mesma natureza do bugfix `20261006-acerto-comprovante-sem-cycles-ago`, que também corrigiu o fluxo de comprovante só no cliente).
- [ ] **Migration ou contrato de API** — nenhum schema, rota, payload ou status code muda: o backend continua recebendo o campo `comprovante` com a regra atual (`image|max:5120`, `ExpenseController.php:1076`, `:1234` e `:1352`). **Se a decisão for aceitar PDF ou outro tipo de arquivo**, isso muda a regra de validação do backend (contrato) e a Triagem passa a marcar esta caixa: o trabalho sobe para feature.
- [ ] **Causa raiz obscura / correção ampla** — causa provável identificada por leitura de código (§1), em 1 componente de produção (`PaymentProofDialog.tsx`) mais o teste dele, bem abaixo do limite de ~3 arquivos. **Ainda não reproduzida em iPhone**: se a reprodução mostrar outra causa, ou se a correção passar do componente, escalar.
- [ ] **Decisão de produto/arquitetura** — remover o `capture` (opção A do §2) devolve o comportamento esperado sem comportamento novo. Oferecer dois botões, "Tirar foto" e "Escolher da galeria" (opção B), já é decisão de interface; se for a escolhida, reavaliar esta caixa.

Nenhuma marcada → segue no BFF.

## 1. Problema

- **Sintoma:** no iPhone, ao tocar no botão de anexar o comprovante ("Selecionar foto" e equivalentes), o sistema abre para tirar uma foto e **não oferece escolher uma imagem da galeria nem um arquivo**.
- **Reprodução:** **não reproduzida** (a sessão em que o relato chegou não tinha iPhone). Passos esperados, a confirmar em iPhone real ou no simulador de Safari do iOS, com o navegador e a versão do iOS do relato: 1. abrir Pagamentos do grupo; 2. numa despesa em que o usuário é credor, tocar em "Confirmar pagamento" (ou, num acerto em que ele é devedor, em "Enviar comprovante"); 3. tocar no botão de foto do diálogo; 4. o sistema abre direto a câmera, sem o menu com "Biblioteca de Fotos" e "Escolher Arquivo".
- **Esperado vs. atual:** esperado, poder escolher uma imagem que já está na galeria ou nos arquivos (o comprovante costuma ser o print do Pix); atual, só tirar uma foto nova.
- **Causa raiz (hipótese, verificada só por leitura de código em 2026-10-27):** `frontend/src/components/PaymentProofDialog.tsx:203-205` renderiza `<input type="file" accept="image/*" capture="environment" hidden>`. No Safari do iOS, o atributo `capture` faz o seletor abrir direto a câmera, sem a opção de biblioteca ou de arquivo. O campo da foto de perfil (`frontend/src/pages/Profile.tsx:211-212`) usa `accept="image/*"` sem `capture` e não tem essa limitação. O mesmo diálogo atende todos os fluxos de comprovante: Despesas (`ExpenseManager.tsx`), Pagamentos (`Payments.tsx`) e o Painel (`GroupPositionCard.tsx`), então a correção vale para todos.

## 2. Correção

- **O que muda e por quê:** opção A, escolhida pelo mantenedor em 2026-10-27: remover `capture="environment"` do `<input type="file">` de `PaymentProofDialog.tsx`, mantendo `accept="image/*"`, e deixar um comentário no JSX explicando por que não há `capture` (para ninguém reintroduzi-lo). Sem o atributo, o Safari do iOS volta a oferecer câmera, biblioteca de fotos e arquivos. A opção B (dois botões, "Tirar foto" e "Escolher da galeria") foi descartada por ser decisão de interface; a Triagem seguiu sem caixa marcada.
- **Arquivos tocados:** `frontend/src/components/PaymentProofDialog.tsx` (1 linha removida e 1 comentário), `frontend/src/components/PaymentProofDialog.test.tsx` (1 teste parametrizado novo, 2 casos) e esta documentação com o índice `docs/bugfix/README.md`. Backend e contrato intactos.
- **Teste de regressão:** `PaymentProofDialog.test.tsx`, "lets the user pick an existing image: the file input has no capture attribute", com 2 casos (fluxo padrão e foto obrigatória, a do comprovante do acerto): afirma `accept="image/*"` e a ausência de `capture`. Falhou antes da correção (2 falhas, `expect(element).not.toHaveAttribute("capture")`) e passa depois. **Limite:** o teste prova que o atributo saiu, não o comportamento do sistema; a conferência em iPhone real continua obrigatória e deve ser registrada no §3.
- **Riscos / efeitos colaterais:** no Android, o toque no botão deixa de abrir direto a câmera e passa a mostrar o seletor do sistema (consequência aceita na opção A; **não verificado em Android real** se o seletor lista a câmera). O diálogo atende Despesas, Pagamentos e Painel, então a mudança vale para os três fluxos de comprovante; nenhuma outra regra do diálogo muda.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-10-27 | `cd frontend && npx vitest run src/components/PaymentProofDialog.test.tsx`, com o teste novo e **antes** da correção (RED) | `Tests 2 failed \| 55 passed (57)`: `expect(element).not.toHaveAttribute("capture")`, nos dois casos |
| 2026-10-27 | o mesmo comando, depois de remover `capture="environment"` | `Test Files 1 passed (1)`, `Tests 57 passed (57)` |
| 2026-10-27 | mutação no input, um defeito por vez, rodando o mesmo comando (arquivo restaurado e conferido com `diff -q` depois de cada um): `capture` booleano; `capture="user"`; `accept` removido | `2 failed \| 55 passed (57)` nos três: todos detectados |
| 2026-10-27 | `cd frontend && npx tsc --noEmit` | exit 0, sem saída |
| 2026-10-27 | `cd frontend && npx vitest run --maxWorkers=1` (suíte completa) | `Test Files 1 failed \| 67 passed (68)`, `Tests 7 failed \| 903 passed (910)`, `Duration 1382.06s`. As 7 falhas são todas de `src/pages/RegisterPage.test.tsx` (5 × `Test timed out in 15000ms` e 2 erros decorrentes), arquivo que esta correção não toca; 910 = 908 + os 2 testes novos |
| 2026-10-27 | `cd frontend && npx vitest run src/pages/RegisterPage.test.tsx` (isolado, logo depois) | `Test Files 1 passed (1)`, `Tests 22 passed (22)`. A falha só aparece com a máquina sob carga (a execução da suíte levou 1382 s, contra 513 s da última vez), o mesmo padrão já registrado na feature `20261010-filtros-credor-devedores-status-despesas` (TASK-407) e na TASK-504 de `20261026-organizacao-despesas-pagamentos` |
| 2026-10-27 | `git diff --stat origin/dev HEAD -- backend/` | vazio: backend intacto, `pint`/`phpunit` N/A |
| 2026-10-27 | Conferência em iPhone real (o bug "não reproduz mais") | **Pendente.** Não verificada: não há iPhone nesta sessão e o teste só prova que o atributo saiu. O mantenedor confere depois do merge (Safari do iOS: o toque no botão de foto deve oferecer câmera, biblioteca e arquivos) e, se ainda falhar, reabre este bug ou escala para feature |

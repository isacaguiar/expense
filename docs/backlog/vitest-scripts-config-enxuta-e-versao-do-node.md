# Vitest do frontend: scripts de teste e typecheck, config enxuta e versão do Node registrada

ID: 137
Origem: docs/feature/concluidas/202611/20261109-responsavel-do-devedor/ (TASK-590: a suíte inteira do front não concluiu na máquina de desenvolvimento e a análise do Vitest que se seguiu); `frontend/vite.config.js`, `frontend/package.json`, `.github/workflows/ci-frontend.yml`
Criado em: 2026-11-10
Prioridade: BAIXA
Status: Aberto

## Descrição
O que o Vitest precisa para rodar está certo, mas espalhado e sem nome:
- **Node:** local v25.2.0, CI Node 20 (`ci-frontend.yml:21`). Não há `.nvmrc` nem `engines`, e o `vite.config.js` ramifica por versão (`execArgv` só em Node ≥ 22, linha 53).
- **Paralelismo:** o padrão do Vitest é um worker por núcleo (8 na máquina de desenvolvimento), cada um com jsdom + MUI (cerca de 250 MB). Com outras sessões abertas e pouca RAM livre, a suíte cheia não termina. `--maxWorkers=1` só existe na memória de quem já tropeçou nisso.
- **Progresso:** em modo não interativo o relatório padrão não imprime progresso por arquivo; um log só com o cabeçalho parece travamento e não é (uma feature já registrou um diagnóstico errado por causa disso).
- **Typecheck:** não há script `typecheck`; o checklist pré-PR (`04-implementation.md:18`) manda rodar `npx tsc --noEmit` "sem script".
- **Ruído e código morto em `vite.config.js`:** `console.log('🛠️  Carregando vite.config.js…')` na linha 1 (imprime em toda carga) e um bloco comentado de uma config antiga nas linhas 66 a 74.

## Por que importa
Custo de cada task: sem script de baixa memória e sem a dica de `vitest related`, o caminho natural é rodar a suíte cheia (dezenas de minutos sob carga) ou desistir dela. O custo fixo por arquivo domina: medido em arquivos isolados, 31 testes em 62 ms levaram 25 s (ambiente jsdom 18 s, setup 5,6 s) e 15 testes em 1,1 s levaram 50 s (ambiente 25 s, import do MUI 18,5 s); só o `Payments.test.tsx` tem custo próprio de teste (76 testes, 56 s). A estimativa para os 78 arquivos é de 20 s cada em série, não uma medição da suíte inteira.

## Proposta (escopo deste item)
1. `package.json`: `"typecheck": "tsc --noEmit"` e `"test:lowmem": "vitest run --maxWorkers=1 --reporter=verbose"`. O `test` e o CI não mudam.
2. `vite.config.js`: remover o `console.log` e o bloco comentado.
3. Registrar a versão de referência do Node (`frontend/.nvmrc` com `20`, alinhado ao CI) e atualizar `04-implementation.md:18` e `05-context-frontend.md` para citar `npm run typecheck` e `vitest related <arquivo> --run` como ciclo de cada task, deixando a suíte cheia para o fim da feature.

Fora do escopo, de propósito: trocar jsdom por happy-dom ou desligar o isolamento (`isolate: false`) para ganhar tempo. Os testes mexem em `localStorage`, mocks globais e timers; sem auditoria isso troca tempo por falso verde. Dividir os dois arquivos grandes é o item 138.

## Decisão a tomar na triagem

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Onde registrar a versão do Node | A) `frontend/.nvmrc`<br>B) `engines` no `package.json`<br>C) os dois | **A** | O `engines` faz o `npm ci` reclamar em Node diferente, e a máquina local é 25 de propósito (a flag do webstorage existe por causa disso). |
| D2 | Teto de workers no padrão do config | A) manter o padrão do Vitest e só oferecer o script `test:lowmem`<br>B) fixar `maxWorkers` no `vite.config.js` | **A** | B deixaria o CI mais lento sem necessidade; o problema é local. |

Não medido: se uma exclusão do Windows Defender para a pasta do projeto e para o `node_modules` reduziria os 18 a 25 s de ambiente por arquivo. É hipótese, e mexer em segurança da máquina é decisão de quem a usa.

Tipo sugerido: frontend (config e scripts) + doc.

# Bugfix — Comentário de manutenção dentro do texto jurídico redata o documento

Versão: 1.0 · Criado em: 20260921 · Branch: `fix/20260921-legal-comentario-redata-documento`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [ ] **Auth / autorização / dado sensível**
- [ ] **Migration ou contrato de API**
- [ ] **Causa raiz obscura / correção ampla**
- [ ] **Decisão de produto/arquitetura**

Nenhuma marcada → segue no BFF.

## 1. Problema

- **Sintoma:** `site/src/legal/privacidade.php:5-14` e `site/src/legal/termos.php:5-14` carregam um docblock de manutenção que cita `docs/feature/20260920-data-por-documento-legal/plan.md` §1. Esse caminho deixou de existir quando a feature foi arquivada em `concluidas/202609/` (PR #179). Corrigir a citação, porém, **redata o documento legal**: são exatamente os arquivos cujo último commit define a data de "Última atualização" publicada (`legal_updated_at()` em `site/src/helpers.php`, alimentada por `site/tools/gerar-datas-legais.sh`).

- **Reprodução:**
  1. Arquivar (ou mover) a feature citada, invalidando o caminho no comentário.
  2. Corrigir o caminho nos dois arquivos com um commit comum.
  3. Rodar `bash site/tools/gerar-datas-legais.sh` — as duas datas passam a ser a do commit da correção.
  4. `/termos.php` passa a anunciar atualização dos Termos de Serviço num dia em que **nenhuma linha do contrato mudou**.

- **Esperado vs. atual:** esperado, manutenção documental (corrigir um caminho, reformatar um comentário) não alterar a data publicada de um documento legal. Atual: qualquer commit nesses arquivos altera.

- **Causa raiz:** o arquivo de conteúdo acumula duas responsabilidades — o texto jurídico e a prosa de manutenção sobre o mecanismo. A feature `20260920-data-por-documento-legal` (specify §2.2) declarou e aceitou que "qualquer commit que toque o arquivo conta como atualização"; o que não foi previsto é que o próprio comentário explicativo, morando lá dentro, cria motivos recorrentes de commit que nada têm a ver com o texto. O caso apareceu em menos de 24h, na primeira arrumação de pastas.

## 2. Correção

- **O que muda e por quê:** a explicação do mecanismo sai dos arquivos de conteúdo e vai para `site/src/legal/README.md`, novo. Os dois arquivos ficam com um cabeçalho de 6 linhas — `declare(strict_types=1)` e a anotação `@var` do `$config`, que ambos usam — e nada mais além do texto jurídico. Manutenção documental passa a acontecer num arquivo cujo histórico não alimenta data nenhuma.
- **Arquivos tocados:** `site/src/legal/privacidade.php` e `site/src/legal/termos.php` (cabeçalho de 16 → 6 linhas cada); `site/src/legal/README.md` (novo); este arquivo e `docs/bugfix/README.md`.
- **Teste de regressão:** sem teste automatizado — o site não tem suíte, e o que precisa ser garantido é que a *data publicada não mudou*, o que se verifica rodando o gerador real. Registrado no log §3: `gerar-datas-legais.sh` continua devolvendo `2026-09-20` e `2026-08-24`, e o HTML renderizado é idêntico ao de antes da mudança.
- **Riscos / efeitos colaterais:** o principal risco era a própria correção redatar os documentos. Contido com um commit por arquivo, cada um com `--date` da última alteração real do texto. Se algum dia esses dois commits forem reescritos (rebase que descarte a data de autoria), as datas publicadas mudam sem ninguém pedir — é o preço do mecanismo escolhido na feature `20260920-data-por-documento-legal`.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-09-21 | `php -l` em `privacidade.php` e `termos.php` | "No syntax errors detected" nos dois |
| 2026-09-21 | `diff` do HTML renderizado antes × depois, ignorando CR, em `localhost:4173` | **idêntico** nas duas páginas; 8 e 9 seções `<h2>`; `0` erros de PHP no HTML |
| 2026-09-21 | `TZ=America/Sao_Paulo git log -1 --format=%ad -- site/src/legal/<doc>.php` | `privacidade` → `2026-09-20`; `termos` → `2026-08-24` |
| 2026-09-21 | `bash site/tools/gerar-datas-legais.sh` | `privacidade -> 2026-09-20`, `termos -> 2026-08-24` — iguais às de produção |
| 2026-09-21 | Páginas renderizadas com o artefato gerado | `/privacidade.php` → "20 de setembro de 2026"; `/termos.php` → "24 de agosto de 2026" |
| 2026-09-21 | `rg "docs/feature" site/src/legal/*.php` | `0` ocorrências — nenhuma referência de documentação dentro do texto jurídico |

## Resolução
Concluído em: 2026-09-21
Branch: `fix/20260921-legal-comentario-redata-documento`
PR: https://github.com/isacaguiar/expense/pull/182

# Plan — Data de atualização por documento legal

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260920

---

## 0. Restrições herdadas

- O site é PHP puro, sem build e sem dependências: `site/public` e `site/src` sobem por FTP direto (`deploy-site.yml`). Nada de composer, nada de pacote novo.
- `php -m | grep intl` não devolve nada no ambiente de desenvolvimento, e a hospedagem é compartilhada — **não dá para depender de `IntlDateFormatter`** para escrever a data por extenso.
- O servidor não tem git: qualquer coisa derivada do histórico precisa ser calculada no runner e enviada junto com os arquivos.

## 1. O problema que aparece no primeiro dia (specify §2.2)

Derivar a data do último commit que tocou **o arquivo da página** produz, hoje, isto:

| Documento | Último commit no arquivo | Data | Correto? |
|---|---|---|---|
| `privacidade.php` | `bb3dcfb63c` — reescreveu o texto de cookies/GA | 2026-09-20 | **sim** |
| `termos.php` | `ca28312560` — acrescentou `$pagePath` para o SEO | 2026-09-20 | **não** — o texto jurídico não muda desde agosto |

Ou seja: o mecanismo escolhido, aplicado ao arquivo da página, já nasceria mentindo em um dos dois documentos — e como a data passa a ser automática, não haveria onde corrigir.

**Decisão: a data deriva de um arquivo de conteúdo, não da página.** O texto jurídico de cada documento sai para `site/src/legal/privacidade.php` e `site/src/legal/termos.php`, incluídos pelas páginas, que ficam só com cabeçalho, metadados e a chamada do template. Assim:

- mexer em SEO, layout ou navegação **não** anuncia atualização do documento;
- mexer no texto **sempre** anuncia.

**Por que não as alternativas:**

- *Aceitar o ruído*: publicaria "Termos atualizados em 20 de setembro" sem que uma linha do contrato mudasse — pior do que a string fixa que estamos consertando.
- *Override manual por documento no `config.php`*: reintroduz exatamente a fonte de verdade manual que o item 051 pede para eliminar (specify §2.7).

**Problema residual, e como resolver:** extrair o texto cria arquivos novos, cujo primeiro commit é o desta feature — então os Termos mostrariam 2026-09-20 de novo. O commit que extrai o texto dos Termos vai com data de autoria explícita da última alteração real do texto:

```
git commit --date="2026-08-24T12:00:00-03:00" ...
```

É o uso legítimo da data de autoria (quando o conteúdo foi escrito) contra a data de commit (quando entrou no repo), e a mensagem do commit registra o porquê. A Política não precisa disso: a última alteração real dela é de hoje mesmo.

## 2. Geração no deploy (specify §2.2, §2.3 e §2.4)

- Script versionado `site/tools/gerar-datas-legais.sh`, chamado pelo workflow — **não** um bloco `run:` inline no YAML. O modo de falha do §2.3 (clone raso devolve data errada, sem erro) é invisível em revisão de YAML e verificável rodando o script localmente.
- O script escreve `site/src/legal-dates.php`:
  ```php
  <?php return ['privacidade' => '2026-09-20', 'termos' => '2026-08-24'];
  ```
  Fica em `site/src/`, que já é enviado pelo segundo passo do FTP — nenhum passo de upload novo.
- Data extraída com fuso fixo, da **autoria** e não do commit:
  ```
  TZ=America/Sao_Paulo git log -1 --date=format-local:'%Y-%m-%d' --format=%ad -- <arquivo>
  ```
  `%ad` (autoria) e não `%cd` (commit) porque rebase e cherry-pick reescrevem a data de commit sem que o texto tenha mudado.
- `.github/workflows/deploy-site.yml`: `actions/checkout@v4` ganha `fetch-depth: 0`. Sem isso o clone é raso e `git log` do arquivo não devolve o commit certo — a feature passaria no teste local e falharia calada em produção.
- O script falha ruidosamente (`exit 1`) se o `git log` vier vazio, para o deploy quebrar em vez de publicar data errada.
- `site/src/legal-dates.php` entra no `.gitignore`: é artefato de deploy, e versioná-lo criaria a segunda fonte de verdade de novo.

## 3. Consumo na página e comportamento local (specify §2.5 e §2.6)

- `site/src/helpers.php` ganha `legal_updated_at(string $documento): string`, que resolve nesta ordem:
  1. `legal-dates.php`, se existir — o caminho de produção;
  2. `filemtime()` do arquivo de conteúdo — o caminho local, onde o artefato não existe.
- Formatação por extenso com um array de meses no próprio helper, sem `intl` (§0).
- **Por que `filemtime` como fallback e não erro**: em desenvolvimento a página precisa renderizar; e o `filemtime` local é aproximadamente verdadeiro (é quando o arquivo foi mexido na máquina). Em produção ele nunca é usado, porque o artefato do deploy está lá.
- `privacidade.php` e `termos.php` trocam `<?= e($config['updated_at']) ?>` por `<?= e(legal_updated_at('privacidade')) ?>` / `('termos')`.

## 4. Remoção da chave compartilhada (specify §2.7)

- `'updated_at' => '24 de agosto de 2026'` e o comentário associado saem do `site/src/config.php`.
- `rg` por `updated_at` no `site/` tem que voltar limpo depois, senão sobrou consumidor.

## 5. Ordem de execução

1. **§3 primeiro, com o fallback** — o helper e o formato funcionando por `filemtime` deixam as páginas verificáveis localmente antes de existir qualquer coisa de deploy.
2. **§1 (extração do conteúdo)** — com o helper pronto, a extração é verificável: a data de cada documento passa a seguir o arquivo de conteúdo.
3. **§2 (script + workflow)** — a automação por último, quando o que ela alimenta já está provado.
4. **§4 (remoção do `updated_at`)** — só depois que ninguém mais lê a chave.

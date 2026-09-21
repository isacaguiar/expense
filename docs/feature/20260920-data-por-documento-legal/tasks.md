# Tasks — Data de atualização por documento legal

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260920

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-321 | Criar `legal_updated_at()` no `helpers.php`, com fallback por `filemtime` | frontend | plan.md §3 | nenhum | Concluída |
| TASK-322 | Passar as duas páginas legais a exibir a data pelo helper | frontend | plan.md §3 | nenhum | Concluída |
| TASK-323 | Extrair o texto da Política de Privacidade para `site/src/legal/privacidade.php` | frontend | plan.md §1 | nenhum | Concluída |
| TASK-324 | Extrair o texto dos Termos para `site/src/legal/termos.php`, com autoria de 2026-08-24 | frontend | plan.md §1 | nenhum | Concluída |
| TASK-325 | Criar o gerador `site/tools/gerar-datas-legais.sh` | infra | plan.md §2 | nenhum | Concluída |
| TASK-326 | Ligar o gerador ao `deploy-site.yml` com histórico completo | infra | plan.md §2 | nenhum | Concluída |
| TASK-327 | Remover `updated_at` do `config.php` | frontend | plan.md §4 | nenhum | Concluída |

## Critérios de aceite

- **TASK-321**: `php -l site/src/helpers.php` limpo. Com `site/src/legal-dates.php` **ausente**, `php -r` chamando `legal_updated_at('privacidade')` devolve a data por extenso derivada do `filemtime` do arquivo de conteúdo (formato `20 de setembro de 2026`). Com um `legal-dates.php` de teste presente, devolve a data dele, provando a precedência. Funciona sem `intl` — `php -m | grep -c intl` devolve `0` neste ambiente e mesmo assim a chamada não falha.

- **TASK-322**: em `localhost:4173`, `/privacidade.php` e `/termos.php` renderizam "Última atualização: <data por extenso>", sem nenhum aviso ou erro de PHP no HTML. Nenhuma das duas lê mais a chave compartilhada: `grep -c "config\['updated_at'\]" site/public/privacidade.php site/public/termos.php` devolve `0`.

  > **Critério corrigido em 2026-09-20, durante a execução.** A redação original pedia `grep -c "updated_at"` igual a `0`, o que é impossível: o nome do próprio helper — `legal_updated_at` — contém a string. O que interessa é não restar leitura de `$config['updated_at']`, e é isso que o comando acima mede. Mesma correção aplicada ao critério da TASK-327.

- **TASK-323**: o texto da Política vive em `site/src/legal/privacidade.php` e a página o inclui. Prova de que nada de conteúdo se perdeu: HTML renderizado capturado **antes** e **depois** da extração, e o `diff` entre os dois é vazio — exceto, se for o caso, a linha da data. As 8 seções continuam presentes (`h2` contados no DOM).

- **TASK-324**: mesmo critério de conteúdo da TASK-323 aplicado a `/termos.php`. Além disso, `TZ=America/Sao_Paulo git log -1 --date=format-local:'%Y-%m-%d' --format=%ad -- site/src/legal/termos.php` devolve **`2026-08-24`**, não a data de hoje — é o que faz a página declarar a verdade no primeiro deploy.

- **TASK-325**: `bash site/tools/gerar-datas-legais.sh` gera `site/src/legal-dates.php` com as duas chaves; `php -r` sobre o arquivo gerado devolve `privacidade => 2026-09-20` e `termos => 2026-08-24`. **Falha ruidosa verificada**: rodar o script sobre um clone raso (`git clone --depth 1` do próprio repo, em diretório temporário) faz o script sair com código **1** e não gerar arquivo, em vez de gravar data errada. `git check-ignore -v site/src/legal-dates.php` confirma que o artefato está ignorado.

- **TASK-326**: `grep -n "fetch-depth: 0" .github/workflows/deploy-site.yml` encontra a linha no passo de checkout. O passo que chama o gerador aparece **antes** dos dois passos de FTP — verificável comparando os números de linha. O YAML continua válido (`python -c "import yaml, sys; yaml.safe_load(open(...))"` sem exceção).

- **TASK-327**: `rg "'updated_at'" site/` não devolve nenhuma ocorrência — a chave do `config.php` e qualquer leitura dela desapareceram (o helper `legal_updated_at` continua existindo, e é o ponto). `/privacidade.php` e `/termos.php` continuam respondendo 200 e exibindo a data, agora sem nenhuma fonte manual.

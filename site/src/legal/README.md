# Textos dos documentos legais

`privacidade.php` e `termos.php` deste diretório contêm **apenas o texto jurídico** publicado em
`site/public/privacidade.php` e `site/public/termos.php`. As páginas guardam cabeçalho, metadados,
navegação e a data; aqui mora o conteúdo.

## Por que o texto vive separado da página

A data de "Última atualização" de cada documento é a data de autoria do último commit que alterou
**o arquivo de conteúdo deste diretório** — não a página. É o que permite mexer em SEO, layout ou
navegação sem anunciar uma alteração de contrato que não houve.

O caminho completo: `site/tools/gerar-datas-legais.sh` lê o histórico do git durante o deploy e
escreve `site/src/legal-dates.php`; `legal_updated_at()` (`site/src/helpers.php`) consome esse
artefato, com `filemtime` como reserva fora do deploy. Desenho e alternativas descartadas em
`docs/feature/concluidas/202609/20260920-data-por-documento-legal/plan.md` §1.

## A regra que vale ao editar

> **Todo commit que toca um arquivo deste diretório redata o documento legal correspondente.**

Isso inclui corrigir uma vírgula, reformatar markup ou atualizar uma referência de documentação.
Por isso estes arquivos não carregam prosa de manutenção: qualquer explicação sobre o mecanismo
mora **neste README**, que pode ser editado à vontade sem efeito nenhum nas datas publicadas.

Foi exatamente esse o defeito corrigido em
`docs/bugfix/concluidos/202609/20260921-legal-comentario-redata-documento.md`: o comentário
explicativo morava dentro do texto, e arquivar uma pasta de feature bastaria para os Termos
declararem atualização num dia em que nada no contrato mudou.

Se precisar mesmo alterar um arquivo daqui sem alterar o texto — uma correção mecânica inevitável —
use `git commit --date` com a data da última alteração real do documento, e explique o motivo na
mensagem do commit.

# Site não tem página 404

ID: 049
Origem: docs/feature/concluidas/202609/20260920-site-conteudo-e-precos/specify.md §3
Criado em: 2026-09-20
Prioridade: BAIXA
Status: Aberto

## Descrição
Uma URL inexistente em `site/public/` devolve a página de erro padrão do servidor — sem o cabeçalho, sem o menu e sem nenhum caminho de volta. Foi observado durante a feature `20260920-site-conteudo-e-precos`, ao acessar `/recursos.php` antes de a página existir.

## Por que importa
Com o site passando de 3 para 8 páginas, link quebrado (interno, externo ou digitado errado) fica mais provável. Uma 404 com o layout do site e links para home, recursos e ajuda recupera a visita em vez de encerrá-la. Depende de configuração do servidor de produção (`ErrorDocument` no Apache ou equivalente), não só de criar o arquivo.

Tipo sugerido: frontend

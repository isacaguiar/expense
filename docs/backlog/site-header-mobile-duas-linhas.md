# Header do site ocupa duas linhas no celular

ID: 055
Origem: docs/feature/concluidas/202609/20260920-site-conteudo-e-precos/ (verificação do deploy em produção)
Criado em: 2026-09-20
Prioridade: BAIXA
Status: Aberto

## Descrição
No breakpoint de celular (`@media (max-width: 640px)` em `site/public/assets/style.css:954`), `.site-header-inner` ganha `flex-wrap: wrap` e os filhos recebem `order`: hambúrguer `2`, `.header-actions` `3` (com `margin-left: auto`), `.main-nav` `4` em linha própria. Como o par "Entrar" + "Cadastre-se" mede 194px e sobram só ~75px à direita do hambúrguer, `.header-actions` quebra para uma segunda linha.

Medido em produção (`https://expense.novemax.com.br/`) a 375×812: header com **182px** de altura — logo em `top: 20`, hambúrguer em `top: 16`, ações em `top: 90`. São ~22% da altura da viewport ocupados antes de qualquer conteúdo; no desktop o mesmo header mede ~74px.

## Por que importa
O primeiro contato de um visitante de celular perde um quinto da tela para cabeçalho, empurrando o título e o CTA principal para baixo — e o site institucional existe justamente para converter esse primeiro contato.

**Antes de mexer, decida se é proposital.** O `order: 3` combinado com `margin-left: auto` sugere que a intenção era manter as ações na mesma linha, à direita, e a quebra seria só o fallback quando não cabe; mas manter "Cadastre-se" sempre visível, sem depender de abrir o menu, também é uma escolha defensável. Não há comentário no CSS registrando a intenção (diferente do bloco logo abaixo, que documenta o porquê do `data-nav`), então isso é leitura de código, não fato declarado.

Se a decisão for enxugar: as saídas usuais são mover "Entrar"/"Cadastre-se" para dentro do menu colapsado, ou manter só "Cadastre-se" na barra e jogar "Entrar" para o menu. Ambas mexem no mesmo bloco de `style.css` e no `header.php`, sem tocar PHP de dados.

Tipo sugerido: frontend

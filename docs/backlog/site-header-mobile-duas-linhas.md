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

## Atualização de 2026-09-21

**O item continua aberto** — ele pede uma decisão de produto (o que fazer com "Entrar"/"Cadastre-se"
no celular) que ainda não foi tomada. Mas três coisas mudaram desde o registro:

1. **~32px já foram recuperados** por `docs/bugfix/concluidos/202609/20260921-header-linha-morta-celular.md`:
   o CSS escondia `.main-nav ul` mas não o container `.main-nav`, que seguia ocupando uma terceira
   linha de altura zero e cobrando mais um `row-gap`. O cabeçalho caiu de **182px para 150px**. As
   duas linhas de conteúdo, que são a queixa deste item, permanecem.

2. **Duas referências deste item estavam erradas.** O markup do cabeçalho está em
   `site/src/templates/nav.php:50-53`, não em `header.php` (que é só `<head>` + abertura do
   `<body>`). E o bloco `@media (max-width: 640px)` começa hoje em `style.css:1027`, não em `:954`.

3. **Tirar só o "Entrar" não resolve.** Medido: o "Cadastre-se" sozinho, com `.btn-sm`, ocupa
   ~115-130px, contra os ~75px que sobram à direita do hambúrguer. Essa saída exige vir acompanhada
   de um redutor — `gap` do `.site-header-inner` (2rem → 0.5rem devolve 24px), fonte do `.logo-word`
   (1.2rem → 1rem devolve ~22px) ou um `.btn-sm` mais estreito no mobile.

A saída que fecha o item sem ajuste fino é mover **os dois** para dentro do menu colapsado: a linha 1
ficaria com logo (178px) + gap (32) + hambúrguer (42) = 252px de 327px disponíveis, e o cabeçalho
voltaria a ~75px, equiparado ao desktop. O custo é o CTA de cadastro deixar de estar visível sem
abrir o menu — que é justamente a decisão de produto pendente.

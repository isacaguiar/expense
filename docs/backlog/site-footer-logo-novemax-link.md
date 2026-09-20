# Trocar link de e-mail do rodapé do site pelo logo da Novemax

ID: 041
Origem: solicitação direta do usuário (conversa), 2026-09-19
Criado em: 2026-09-19
Prioridade: BAIXA
Status: Aberto

## Descrição

O rodapé do site institucional (`site/src/templates/footer.php:13`, incluído pela página
inicial `site/public/index.php` e pelas páginas legais `termos.php`/`privacidade.php`) exibe
hoje o link `mailto:novemax@gmail.com` com o próprio e-mail como texto visível. A ideia é
substituir esse link por um logo da Novemax (imagem, ainda não existe em
`site/public/assets/` — só há `logo-expense.png`/`logo-expense-footer.png`, do produto) que
aponte para `https://novemax.com.br`, abrindo em uma nova aba/página (`target="_blank"` +
`rel="noopener"`).

## Por que importa

Hoje o rodapé só oferece contato por e-mail; não há nenhum link para o site institucional da
Novemax (empresa por trás do produto). Trocar o texto do e-mail por um logo clicável dá
identidade visual à marca e direciona o usuário para novemax.com.br. Baixa prioridade: é um
ajuste de marca/rodapé, sem prazo natural e sem impacto funcional no produto.

Tipo sugerido: frontend (site institucional, não o app React)

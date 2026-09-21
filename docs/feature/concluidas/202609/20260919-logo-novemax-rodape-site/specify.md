# Specify — Logo Novemax no rodapé do site

> Feature: trocar o link de e-mail (`novemax@gmail.com`) do rodapé do site institucional por um logo clicável da Novemax, apontando para `https://novemax.com.br` em nova aba. Origem: item de backlog [041 — site-footer-logo-novemax-link.md](../../backlog/site-footer-logo-novemax-link.md), promovido a pedido direto do usuário em 2026-09-19.

Versão: 1.0 · Criado em: 20260919

---

## 1. Problema

O rodapé do site institucional (`site/src/templates/footer.php:13`, incluído por `site/public/index.php:140` e pelas páginas legais `termos.php`/`privacidade.php`) hoje mostra o link `mailto:novemax@gmail.com` com o próprio e-mail como texto visível. Não existe hoje nenhum link para o site institucional da empresa (`novemax.com.br`) em nenhuma página do site — só o contato por e-mail. Isso reduz a identidade visual da marca no rodapé e não direciona o visitante para o site da Novemax.

## 2. Requisitos

### 2.1 Substituir o link de e-mail por um logo clicável

Em `site/src/templates/footer.php:13`, trocar `<a href="mailto:...">novemax@gmail.com</a>` por um link `<a href="https://novemax.com.br" target="_blank" rel="noopener">` contendo uma `<img>` com o logo da Novemax (`alt="Novemax"`), em vez do texto do e-mail.

### 2.2 Asset do logo ainda não existe no repositório

Hoje `site/public/assets/` só tem `logo-expense.png`/`logo-expense-footer.png` (logo do produto Shared Expense, usado em `site/src/templates/logo.php`), não há nenhum arquivo com o logo da Novemax (confirmado por busca em todo o repositório). É preciso decidir a origem do arquivo de imagem antes do Tech Plan — ex.: usuário fornece o arquivo, ou se reaproveita algum SVG/PNG já usado em outro lugar (não encontrado nenhum candidato). Esta decisão bloqueia o Tech Plan e deve ser resolvida com o usuário antes da etapa 3.

### 2.3 Manter o e-mail de contato acessível

O e-mail `novemax@gmail.com` continua sendo o contato oficial (usado também em `site/public/termos.php:97` e `site/public/privacidade.php:89`, fora do escopo desta feature). Remover o texto do e-mail do rodapé não deve remover a forma de contatar por e-mail do site como um todo — avaliar no Tech Plan se o e-mail deve continuar em algum lugar do rodapé (ex.: como `title`/tooltip do link do logo, ou mantido em outro ponto) ou se o link "Contato" do menu (`site/src/config.php:29`, `mailto:novemax@gmail.com`) já é suficiente por si só.

## 3. Fora de escopo desta feature

- Qualquer alteração em `site/src/config.php` `nav` (menu "Contato") — o item de backlog fala apenas do rodapé.
- Criar ou hospedar uma página nova em `novemax.com.br` — esse domínio é externo ao repositório; a feature só adiciona o link/logo apontando para lá.
- Qualquer alteração no app React (`frontend/`) ou no logo do produto (`logo-expense*.png`) — escopo é só o site institucional (`site/`).

# Plan — Site Institucional Público

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.1 · Criado em: 20260824 · Revisado em: 20260824 (stack PHP + landing page completa)

---

## 1. Diretório e stack (specify §2.1–2.3, §3 — revisão 1.1)

- Novo diretório `/site` na raiz do repo, irmão de `backend/`, `frontend/` e `app/`.
- PHP puro (sem framework — Laravel já é do `backend/`, não faz sentido aqui para 3 páginas sem
  banco/rota dinâmica), com **document root em `site/public/`** e templates fora dele em
  `site/src/`, para não expor include parcial via URL:
  ```
  site/
    public/                 <- document root (php -S serve só isso)
      index.php
      privacidade.php
      termos.php
      assets/style.css
      assets/favicon.png
    src/
      config.php             <- dados do site (nome, nav, contato, URLs de app) num só lugar
      helpers.php             <- e() = htmlspecialchars() helper, asset()
      templates/
        header.php             <- <head> + <html><body> aberto
        nav.php                 <- header visual (logo + navegação + CTAs)
        footer.php               <- rodapé + </body></html>
  ```
- Boas práticas aplicadas: `declare(strict_types=1)`, todo texto dinâmico passa por `e()`
  (`htmlspecialchars` com `ENT_QUOTES`), includes via `__DIR__` (nunca path relativo solto), HTML5
  semântico (`header`/`nav`/`main`/`section`/`footer`), sem inline `style`/`script`, meta tags
  (charset, viewport, description, Open Graph) centralizadas em `config.php` para não duplicar
  string em cada página.
- Motivo da troca de stack: decisão explícita do usuário nesta revisão (era HTML puro na v1.0).
  Continua sem build step — PHP roda direto, sem Composer/Vite, porque não há dependência externa
  nem lógica de negócio real.

## 2. Página index — landing page (specify §2.1, revisão 1.1)

- `site/public/index.php`, usando `nav.php`/`header.php`/`footer.php`.
- Reproduz as seções do mockup (`assets/images/site.png`, fornecido pelo usuário, fora do repo):
  1. Header fixo: logo (SVG inline "ícone + wordmark", não o PNG antigo) + nav (`Recursos`,
     `Como funciona`, `Benefícios`, `Preços`, `Contato`) + `Entrar`/`Cadastre-se`.
  2. Hero: headline em duas linhas, subtexto, 3 destaques rápidos (Grupos/Despesas/Divisão
     igualitária), 2 CTAs, selo de confiança, e um cartão ilustrativo do dashboard do app
     (dados fictícios, deixado explícito no código como exemplo/mock, não vem da API).
  3. Grid de 6 recursos ("Tudo o que você precisa para organizar as despesas").
  4. "Como funciona em 3 passos simples".
  5. Faixa final de CTA (fundo escuro) com 2 botões e selos.
- Avatares do cartão de dashboard: iniciais coloridas (não fotos de pessoas reais/genéricas —
  evita usar fotos de banco de imagem fabricadas se passando por usuários reais).
- Ícones: SVG inline, sem CDN/ícone-fonte externo (mantém a página autocontida e sem
  dependência externa, alinhado com a regra de não commitar chamada a serviço externo desnecessário).
- Nav items sem destino real (`Preços`) apontam para `#` explícito; `Contato` aponta para
  `mailto:novemax@gmail.com`; `Entrar`/`Cadastre-se` apontam para constantes em `config.php`
  (`APP_LOGIN_URL`/`APP_SIGNUP_URL`), hoje `#`, fáceis de apontar pro domínio real do `frontend/`
  quando existir.
- CSS em `site/public/assets/style.css`, mobile-first, com breakpoints para empilhar as colunas
  do hero e do grid em telas estreitas.

## 3. Página de Política de Privacidade (specify §2.2)

- `site/public/privacidade.php`, usando os mesmos `header.php`/`nav.php`/`footer.php` da landing
  (nav consistente entre as 3 páginas — trade-off aceito: nav de marketing aparece também nas
  páginas legais, mas evita duplicar dois sistemas de header).
- Conteúdo mantido da v1.0 (ver `01-specify.md` §3): "Controle de Despesas Compartilhadas", dados
  coletados (conta, grupos, despesas, dados de pagamento/Pix), finalidade, compartilhamento,
  direitos do usuário, contato `novemax@gmail.com`. Só migra de HTML para PHP (mesmo texto).

## 4. Página de Termos de Serviço (specify §2.3)

- `site/public/termos.php`, mesma estrutura de `header.php`/`nav.php`/`footer.php`.
- Conteúdo mantido da v1.0: descrição do serviço, responsabilidades do usuário, limitação de
  responsabilidade, alterações nos termos, contato `novemax@gmail.com`. Só migra de HTML para PHP.

## 5. Verificação local

- Servidor embutido do PHP: `php -S localhost:4173 -t site/public`, configurado como
  `site-static` em `.claude/launch.json` (troca do `python -m http.server` da v1.0, que não
  executa PHP).

## 6. Ordem de execução

Sem dependência técnica entre §3 e §4 entre si. §1 (estrutura + config/helpers/templates) e §2
(landing) vêm primeiro porque `privacidade.php`/`termos.php` reaproveitam os templates criados
ali. Arquivos `.html`/PNG da v1.0 (`site/index.html`, `privacidade.html`, `termos.html`,
`site/assets/`) são removidos após a migração, para não deixar duas versões do site no repo.

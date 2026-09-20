# Plan — Logo Novemax no rodapé do site institucional

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260919

---

## 1. Asset do logo (specify §2.2)

- Copiar `E:\Projetos\Controle de Despesas\expense\assets\images\logo-novemax.png` (130×142px
  PNG RGBA, já fornecido pelo usuário) para `site/public/assets/logo-novemax.png`, mesmo
  diretório dos demais logos versionados (`logo-expense.png`, `logo-expense-footer.png`).
  Nenhum processamento/otimização adicional — mesmo padrão dos assets existentes (PNG puro,
  sem pipeline de build no `site/`).

## 2. Link do rodapé (specify §2.1 e §2.3)

- Em `site/src/templates/footer.php:13`, trocar `<a href="mailto:novemax@gmail.com">novemax@gmail.com</a>`
  por:
  ```php
  <a href="https://novemax.com.br" target="_blank" rel="noopener" class="legal-footer-logo-link" aria-label="Novemax">
    <img src="<?= e(asset('logo-novemax.png')) ?>" alt="Novemax" class="legal-footer-logo" />
  </a>
  ```
  `target="_blank"` + `rel="noopener"` cumpre o pedido de abrir em nova aba/página sem expor
  `window.opener` à página de destino. `alt="Novemax"` (não vazio) porque, diferente do ícone
  em `site/src/templates/logo.php` (que tem o nome da marca ao lado em texto), aqui o link não
  tem nenhum texto companheiro — o `alt` é a única forma de leitores de tela identificarem o
  destino do link.
- O e-mail de contato **não** é duplicado no rodapé: o item "Contato" do menu
  (`site/src/config.php:29`) já aponta para `mailto:novemax@gmail.com` e continua intocado —
  resolve o requisito §2.3 do specify sem repetir informação.
- Em `site/public/assets/style.css`, adicionar perto do bloco `/* ---------- Footer (legal) ---------- */`
  (linhas 583–610):
  ```css
  .legal-footer-logo-link {
    display: inline-flex;
    align-items: center;
  }

  .legal-footer-logo {
    height: 22px;
    width: auto;
    display: block;
  }
  ```
  `height: 22px` mantém o logo na mesma ordem de grandeza visual dos links de texto vizinhos
  (`font-size: 0.85rem` ≈ 13.6px, mais um respiro razoável para um ícone). `.legal-footer-inner`
  já usa `flex-wrap: wrap` e `align-items: center`, então o novo elemento se encaixa sem exigir
  mudança de layout no container.

## 3. Ordem de execução

Dependência técnica direta: o item 2 (`footer.php`) referencia o arquivo do item 1
(`logo-novemax.png`) via `asset()` — se a task de código rodar antes da task do asset, a
imagem quebra (404) até a outra task ser integrada. Por isso a ordem em `tasks.md` é: primeiro
o asset (TASK-292), depois o código (TASK-293).

# Bugfix — Terceira linha invisível cobra 32px no cabeçalho do celular

Versão: 1.0 · Criado em: 20260921 · Branch: `fix/20260921-header-linha-morta-celular`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [ ] **Auth / autorização / dado sensível**
- [ ] **Migration ou contrato de API**
- [ ] **Causa raiz obscura / correção ampla**
- [ ] **Decisão de produto/arquitetura**

Nenhuma marcada → segue no BFF.

A quarta caixa merece uma palavra: o **item 055 do backlog** pede uma decisão de produto (o que fazer com "Entrar"/"Cadastre-se" no celular) e **continua aberto**. Este bugfix não a toma — corrige um defeito mecânico que existe independentemente dela.

## 1. Problema

- **Sintoma:** no celular, o cabeçalho do site tem uma faixa vazia de ~32px abaixo dos botões "Entrar"/"Cadastre-se", em todas as 8 páginas. Medido a 375×812: header com **182px**.

- **Reprodução:**
  1. Abrir qualquer página do site em viewport de 375px de largura, com JavaScript ativo.
  2. Sem abrir o menu, medir `document.querySelector('.site-header').getBoundingClientRect().height` → `182`.
  3. Medir `.main-nav` → `top: 165`, `height: 0`, `width: 327`, com `display: block` — um elemento de altura zero ocupando uma linha inteira.

- **Esperado vs. atual:** esperado, o cabeçalho colapsado ocupar só as linhas que têm conteúdo. Atual: uma terceira linha vazia consome mais um `row-gap`.

- **Causa raiz:** `site/public/assets/style.css`, no `@media (max-width: 640px)` — a regra `:root[data-nav='collapsed'] .main-nav ul { display: none; }` esconde a **lista**, mas não o container `.main-nav`, que continua sendo flex item com `flex-basis: 100%` e `width: 100%`. Com o `flex-wrap: wrap` do `.site-header-inner`, ele forma uma terceira linha de altura zero, e o `gap: 2rem` do container — que também vale como `row-gap` — cobra 32px por ela.

## 2. Correção

- **O que muda e por quê:** a regra passa a esconder `.main-nav` inteiro em vez de só o `ul`. Uma linha de CSS, com comentário explicando por que o container precisa sair do fluxo e não apenas a lista.
- **Arquivos tocados:** `site/public/assets/style.css` (uma regra, dentro do bloco de 640px).
- **Teste de regressão:** sem teste automatizado — o site não tem suíte. Verificação por medição real no navegador, registrada no log §3: altura do cabeçalho antes e depois, abertura do menu, comportamento sem JavaScript e desktop inalterado.
- **Riscos / efeitos colaterais:** o risco real era quebrar o menu ou o progressive enhancement. Os dois foram medidos: o menu abre e fecha normalmente, e sem `data-nav` no `<html>` (cenário sem JS) a navegação continua visível — o seletor exige o atributo, que só o `nav.js` escreve.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-09-21 | Medição a 375×812, antes | header `182px`; `.main-nav` em `top: 165`, `height: 0`, `display: block`; `ul` em `display: none` |
| 2026-09-21 | Medição a 375×812, depois | header **`150px`**; `.main-nav` em `display: none` — os 32px recuperados |
| 2026-09-21 | Clique no hambúrguer (via `.nav-toggle.click()`) | `data-nav` `collapsed` → `open`, `aria-expanded` → `true`, `.main-nav` → `block`, os 5 itens visíveis; header cresce para `443px` |
| 2026-09-21 | Segundo clique | volta a `collapsed` e a `150px` |
| 2026-09-21 | `removeAttribute('data-nav')` (simula ausência de JS) | `.main-nav` → `display: block` com **5 itens visíveis** — progressive enhancement intacto |
| 2026-09-21 | Medição a 1100px (desktop) | header `76px`, menu visível com 5 itens, hambúrguer `display: none` — inalterado |
| 2026-09-21 | `curl` nas 8 páginas | todas `200`; `/precos.php` também mede `150px` no celular |
| 2026-09-21 | Console do navegador | sem erros |

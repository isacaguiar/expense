# Implementation — Logo Novemax no rodapé do site institucional

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260919

---

## 1. Desvios do fluxo padrão

Esta feature foi promovida do item de backlog 041 e executada numa worktree isolada
(`.claude/worktrees/logo-novemax-rodape-site`, branch `worktree-logo-novemax-rodape-site`)
para não interferir na branch ativa `feature/20260919-cadastro-de-usuarios-TASK-290`. Ambas
as duas tasks foram feitas direto na branch da feature (sem sub-branch por task), dado o
escopo pequeno — sem desvio do fluxo de `04-implementation.md` além disso.

## 2. Log de implementação

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-292 | Concluída | 2026-09-19 | Claude Code | `cp "E:\Projetos\Controle de Despesas\expense\assets\images\logo-novemax.png" "site/public/assets/logo-novemax.png"` — arquivo copiado, 12162 bytes, `file` confirma PNG 130x142 RGBA idêntico à origem | — |
| TASK-293 | Concluída | 2026-09-19 | Claude Code | Editado `site/src/templates/footer.php` (link mailto → link-imagem) e `site/public/assets/style.css` (`.legal-footer-logo-link`/`.legal-footer-logo`). Validado subindo `php -S localhost:4183 -t site/public` e navegando via browser: `read_network_requests` mostrou `GET /assets/logo-novemax.png → 200 OK`; `javascript_tool` confirmou `naturalWidth/Height=130/142` (imagem carregou, não é 404), `renderedHeight="22px"`, `alt="Novemax"`, `link.href="https://novemax.com.br/"`, `target="_blank"`, `rel="noopener"`. Testado também em `termos.php` (rodapé aparece igual) e em viewport mobile 375×812 (`footerWidth=375`, `overflowsViewport=false`, `bodyScrollWidth=375` — sem overflow horizontal) | Servidor de teste (`php -S`) parado ao final da validação |

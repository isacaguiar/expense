# Tasks — Logo Novemax no rodapé do site institucional

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260919

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-292 | Adicionar asset `logo-novemax.png` em `site/public/assets/` | frontend | plan.md §1 | nenhum | Pendente |
| TASK-293 | Trocar link de e-mail do rodapé pelo logo clicável da Novemax | frontend | plan.md §2 | antes do merge (PR único da feature) | Pendente |

## Critérios de aceite

- **TASK-292**: `site/public/assets/logo-novemax.png` existe no repositório, é idêntico
  (mesmo conteúdo binário) ao arquivo fornecido em `assets/images/logo-novemax.png`, e
  `asset('logo-novemax.png')` resolve para `assets/logo-novemax.png` (mesmo padrão de
  `logo-expense.png`).
- **TASK-293**: abrindo `site/public/index.php` (ou `termos.php`/`privacidade.php`) no
  navegador, o rodapé mostra o logo da Novemax no lugar do texto do e-mail; clicar nele abre
  `https://novemax.com.br` em nova aba; o link "Contato" do menu principal continua
  funcionando (`mailto:novemax@gmail.com`); nenhuma quebra visual do rodapé em viewport
  mobile (largura ~375px).

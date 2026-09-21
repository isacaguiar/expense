# O produto se chama de três formas diferentes

ID: 050
Origem: docs/feature/concluidas/202609/20260920-site-conteudo-e-precos/specify.md §3
Criado em: 2026-09-20
Prioridade: BAIXA
Status: Aberto

## Descrição
O mesmo produto aparece com três nomes:

- **"Shared Expense"** — `brand_name` em `site/src/config.php`, usado na landing e nos títulos das páginas do site.
- **"Controle de Despesas Compartilhadas"** — `legal_name`, usado nos Termos e na Política de Privacidade.
- **"SCD"** — `<title>` fixo de `frontend/index.html` (ver item 036).

A divergência entre `brand_name` e `legal_name` é deliberada e está registrada em `docs/feature/concluidas/202608/20260824-site-institucional-publico/specify.md` §3. O terceiro nome não foi decidido por ninguém.

## Por que importa
Quem sai do site e entra no app vê o nome mudar na aba do navegador. Não quebra nada, mas corrói a impressão de que é um produto só. O ponto de decisão é se "SCD" some em favor de um dos dois nomes oficiais — e isso se resolve junto com o item 036, que já trata do `<title>` estático do frontend.

Tipo sugerido: frontend

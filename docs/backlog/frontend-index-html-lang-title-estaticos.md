# `frontend/index.html` com `lang="en"` e `<title>` estático

ID: 036
Origem: docs/bugfix/concluidos/20260901-frontend-meta-viewport-mobile.md §2 (achado ao corrigir a meta viewport ausente)
Criado em: 2026-09-01
Prioridade: BAIXA
Status: Aberto

## Descrição

O `frontend/index.html` tem `<html lang="en">` — o app é todo em pt-BR. E o `<title>` é
`SCD - Login`, fixo: aparece assim em todas as rotas (dashboard, despesas, pagamentos, etc.),
não só na tela de login. Não há hoje nenhuma lógica de `document.title` por rota no
`frontend/`.

Corrigir o `lang` é trocar uma palavra. O `<title>` dinâmico é um pouco mais: escolher um
mecanismo (efeito por página, um pequeno hook `useDocumentTitle`, ou lib) e aplicar nas
telas — trabalho pequeno mas com decisão de padrão envolvida.

## Por que importa

`lang` errado afeta acessibilidade (leitores de tela usam a pronúncia errada) e SEO/indexação.
`<title>` fixo prejudica orientação do usuário (abas do navegador, histórico, favoritos todos
com o mesmo texto "SCD - Login" mesmo em telas logadas).

Tipo sugerido: frontend

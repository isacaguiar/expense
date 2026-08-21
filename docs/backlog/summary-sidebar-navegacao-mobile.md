# Navegação alternativa para a sidebar da tela de Resumo em mobile

ID: 022
Origem: docs/feature/20260819-novo-layout-tela-entrada/specify.md (responsividade, decisão adiada do specify para o plan.md)
Criado em: 2026-08-19
Prioridade: MEDIA
Status: Aberto

## Descrição

O layout novo da tela de Resumo oculta a sidebar de navegação abaixo do breakpoint `md` (mesmo padrão usado na tela de login para a coluna de branding), sem nenhum substituto (menu hambúrguer, drawer, bottom nav). Em mobile, o usuário perde a navegação para Despesas/Participantes que a sidebar oferecia nessa tela — só chega lá por outro caminho (ex.: `Dashboard`) ou navegação do browser.

## Por que importa

É uma regressão de usabilidade em mobile específica desta tela (as demais páginas do app continuam com a `Navbar` de sempre, que ao menos lista os links, mesmo sem ser otimizada para mobile). Vale resolver antes da tela de Resumo virar o principal ponto de entrada do fluxo em telas pequenas.

Tipo sugerido: frontend

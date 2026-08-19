# Implementar internacionalização (i18n) real do frontend

ID: 016
Origem: docs/feature/20260819-novo-layout-tela-login/specify.md §3 (seletor de idioma na tela de login é só visual)
Criado em: 2026-08-19
Prioridade: BAIXA
Status: Aberto

## Descrição

O novo layout da tela de login (`novo-layout-tela-login`) inclui um seletor de idioma (`Select` do MUI, "Português (Brasil)" / "English (US)") funcional apenas como componente de UI — escolher uma opção só atualiza o estado local do combobox, sem trocar nenhum texto da tela nem persistir a preferência. Implementar i18n de verdade exigiria: (1) escolher uma biblioteca de tradução para React (ex.: `react-i18next`, `react-intl`), (2) extrair todos os textos hoje hardcoded em português para arquivos de tradução, (3) persistir a preferência de idioma escolhida (ex.: `localStorage`), e (4) decidir se o backend também precisa responder mensagens de erro/validação traduzidas.

## Por que importa

Sem isso, o seletor de idioma é uma promessa visual não cumprida — o usuário troca para "English (US)" e a tela continua inteira em português. Vale planejar como trabalho futuro dedicado (escopo grande: toda a base de textos do frontend, não só a tela de login) em vez de expandir a feature de login para cobrir i18n completo.

Tipo sugerido: frontend

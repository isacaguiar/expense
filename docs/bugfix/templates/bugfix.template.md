# Bugfix — <título legível curto>

Versão: 1.0 · Criado em: <AAAAMMDD> · Branch: `fix/<AAAAMMDD>-<slug>`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**

- [ ] **Auth / autorização / dado sensível** — rotas/controllers/middleware de auth, Pix, grupos, despesas, usuários.
- [ ] **Migration ou contrato de API** — altera schema do banco (mesmo aditivo) ou muda resposta/rota/status/payload que `frontend`/`app` consomem.
- [ ] **Causa raiz obscura / correção ampla** — causa não clara após timebox, ou > ~3 arquivos / vários módulos.
- [ ] **Decisão de produto/arquitetura** — depende de comportamento novo, ou contradiz a Constitution.

Nenhuma marcada → segue no BFF.

## 1. Problema

- **Sintoma:** <o que o usuário vê / o que quebra>
- **Reprodução:** <passos numerados; ambiente, se relevante>
- **Esperado vs. atual:** <o que deveria acontecer / o que acontece>
- **Causa raiz:** <arquivo:linha do código real + explicação curta>

## 2. Correção

- **O que muda e por quê:** <descrição da mudança>
- **Arquivos tocados:** <lista>
- **Teste de regressão:** <qual teste reproduz o bug e passa a verde com a correção — ou "sem teste: <motivo>", ex. bug puramente visual>
- **Riscos / efeitos colaterais:** <o que mais pode ser afetado; "nenhum identificado" se for o caso>

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| <AAAA-MM-DD> | <ex.: `cd frontend && npx tsc --noEmit`> | <ex.: sem erros> |

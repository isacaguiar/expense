# Dividir por assunto os dois arquivos de teste gigantes do frontend (ExpenseManager e Payments)

ID: 138
Origem: docs/feature/20261109-responsavel-do-devedor/ (análise do Vitest após a TASK-590); `frontend/src/pages/ExpenseManager.test.tsx` (2756 linhas) e `frontend/src/pages/Payments.test.tsx` (1864 linhas, 76 testes)
Criado em: 2026-11-10
Prioridade: BAIXA
Status: Aberto

## Descrição
Os dois arquivos concentram a maior parte da tela de despesas e da tela de Pagamentos num arquivo cada. O Vitest paraleliza por arquivo, então um arquivo grande ocupa um worker sozinho: o `Payments.test.tsx` levou 56 s só de testes (68 s no total) rodando isolado, enquanto os arquivos pequenos gastam o tempo no ambiente, não nos testes.

## Por que importa
- Qualquer task que toque a tela de Pagamentos (como a TASK-592 da feature `20261109-responsavel-do-devedor`) paga os 56 s inteiros para rodar um teste.
- Com mais de um worker, a divisão por assunto distribuiria o trabalho; com um worker, ao menos permite `vitest related` e `-t` mais úteis.
- Risco ao dividir: os blocos compartilham `vi.mock`, fixtures e helpers no topo do arquivo. A divisão tem de extrair isso para um helper comum antes de mover testes, e comparar a contagem de testes antes e depois (76 em `Payments`) para não perder nenhum.

## Decisão a tomar na triagem

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Quando | A) junto com a próxima feature que reescreva a tela<br>B) item próprio, só refatoração de teste | **B** | Misturar refatoração de teste com feature dificulta provar que nenhum teste sumiu. |
| D2 | Como provar equivalência | A) contagem de testes antes e depois<br>B) A mais mutation check nos arquivos movidos | **A** | É refatoração sem mudança de código de produção; a contagem pega o risco real (teste perdido). |

Tipo sugerido: frontend (somente testes).

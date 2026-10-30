# Remetente dos e-mails ainda diz "SCD Novemax" e o `01-specify.md` afirma que o token é "comparado por hash"

ID: 124
Origem: docs/feature/20260930-recuperacao-acesso/specify.md §2.3 e revisão de segurança de 2026-10-30 (F9 e F4); `.github/workflows/deploy-backend.yml:53`, `docs/sdd/01-specify.md:42`
Criado em: 2026-10-30
Prioridade: BAIXA
Status: Aberto

## Descrição
- **Remetente.** `deploy-backend.yml:53` escreve `MAIL_FROM_NAME="SCD Novemax"` no `.env` gerado. A TASK-380 trocou a marca dentro dos quatro templates, mas o nome do remetente fica fora do diff dela, então a correção de marca está **incompleta**: o e-mail chega "de SCD Novemax" com o corpo em "Shared Expense".
- **Documentação.** `docs/sdd/01-specify.md:42` diz que o token de recuperação é "comparado por hash"; o código compara o valor em claro (item 120).

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Alterar `MAIL_FROM_NAME` altera o ambiente de produção, então o merge e o deploy continuam sendo gate humano (`00-constitution.md` §5.2). |
| R2 | A decisão de marca é do usuário: a TASK-380 já a tomou para o corpo dos e-mails. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Nome do remetente | A) "Shared Expense", coerente com a TASK-380<br>B) manter "SCD Novemax" | **A** | O destinatário vê remetente e corpo na mesma caixa de entrada; marcas diferentes parecem erro (ou golpe). |

## Por que importa
É o último resto visível da marca antiga nos e-mails; o item da documentação evita que quem leia o `01-specify.md` ache que o token é protegido por hash.

Tipo sugerido: doc e config (mudar o remetente é alteração de ambiente de produção)

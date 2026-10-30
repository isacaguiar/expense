# Executar o checklist do GA4: a medição aprimorada pode levar e-mail e token da URL de recuperação ao Google

ID: 122
Origem: docs/feature/20260930-recuperacao-acesso/specify.md e revisão de segurança de 2026-10-30 (F6); `docs/analytics/README.md:105`, `frontend/src/analytics/sanitizePath.ts:33-35`, `frontend/src/analytics/consent.ts:85`, `docs/feature/20261004-metricas-aquisicao-ativacao/plan.md:24`
Criado em: 2026-10-30
Prioridade: MEDIA
Status: Aberto

## Descrição
`sanitizePath.ts` descarta query e hash e `pageView.ts` sobrescreve o `page_location`, então o `page_view` e os eventos que o app dispara chegam ao Google como `/recuperar-senha`, sem `email` nem `token`. Mas a "medição aprimorada" do GA4 gera sozinha eventos de rolagem, clique de saída e interação de formulário **com o `document.location` real**: em `/recuperar-senha?email=…&token=…` isso entregaria ao Google o e-mail e um token válido por 60 minutos.

O checklist de `docs/analytics/README.md:105` existe para fechar isso (desligar a medição aprimorada ou redigir os parâmetros), mas **nunca foi executado**: a tabela "Registro de execução" está vazia. Não foi possível ver as configurações do console do GA4 nem se há redação de dados. O código não muda com este item; a ação é de quem tem acesso ao console.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Nada de código: o que muda é a configuração da propriedade do GA4, registrada no README de analytics. |
| R2 | O consentimento de cookies continua como está (o GA só carrega depois dele). |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como fechar | A) desligar os eventos da medição aprimorada na propriedade<br>B) manter e configurar a redação de dados de parâmetros de URL (`email`, `token`) | **A** | Mais simples e sem depender de lembrar o nome dos parâmetros; B protege só o que foi listado e quebra em silêncio se um parâmetro novo aparecer. |

## Por que importa
Um token de recuperação válido em terceiros é o tipo de vazamento que o `sanitizePath` quis evitar; o risco só existe se a medição aprimorada estiver ligada, o que não foi verificado.

Tipo sugerido: infra (ação humana no console do GA4, sem código)

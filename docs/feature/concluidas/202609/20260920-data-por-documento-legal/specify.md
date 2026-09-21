# Specify — Data de atualização por documento legal

> Feature: cada documento legal do site passa a exibir a data da sua própria última alteração, derivada do histórico do git no deploy, em vez de uma string única editada à mão. Promoção do item **051** do backlog (`/promover-backlog 051`), decidida em 2026-09-20.

Versão: 1.0 · Criado em: 20260920

---

## 1. Problema

`site/src/config.php` tem `'updated_at' => '24 de agosto de 2026'`, exibido como "Última atualização" tanto em `privacidade.php` quanto em `termos.php`. Uma string, dois documentos, mantida à mão.

O problema deixou de ser hipotético: a feature `20260920-analytics-app-e-consentimento` reescreveu trechos da Política de Privacidade (cookies, Google Analytics, revogação do consentimento), o texto foi para produção, **e a página continua declarando 24 de agosto de 2026**. Num documento em que a data tem peso jurídico, isso é uma afirmação falsa publicada.

O segundo problema é simétrico: os Termos de Serviço **não** mudaram, mas qualquer correção da data teria mexido nos dois, passando a anunciar uma atualização que não houve.

## 2. Requisitos

### 2.1 Uma data por documento

`privacidade.php` e `termos.php` exibem datas independentes. Mudar um não afeta o outro.

### 2.2 A data vem do histórico do git, calculada no deploy

A fonte da verdade é o último commit que alterou o arquivo do documento, e não um valor digitado. Decisão do usuário em 2026-09-20, entre três alternativas (valor explícito por documento; `filemtime()`; derivação do git).

Consequência aceita junto com a decisão: **qualquer** commit que toque o arquivo passa a contar como atualização do documento — inclusive correção de vírgula ou ajuste de markup. A precisão vem de commits com escopo limpo, não do mecanismo.

### 2.3 O deploy precisa de histórico

`.github/workflows/deploy-site.yml` usa `actions/checkout@v4` sem `fetch-depth`, o que traz um clone raso de profundidade 1 — nele `git log` de um arquivo não devolve o commit certo. O workflow tem que passar a clonar com histórico.

### 2.4 Fuso horário explícito

A data precisa ser interpretada no fuso do projeto (`America/Sao_Paulo`), não em UTC. O caso concreto já existe: o commit que reescreveu a Política é `bb3dcfb63c`, de **2026-09-20 21:58 -0300** — que em UTC já é dia 21. Sem fixar o fuso, o mesmo commit produz datas diferentes dependendo de onde o workflow roda.

### 2.5 Formato mantido

A exibição continua em português por extenso, como hoje (`24 de agosto de 2026`), para não mudar a aparência dos documentos.

### 2.6 A página precisa funcionar fora do deploy

O site roda localmente pelo servidor PHP embutido (`site-static` em `.claude/launch.json`), onde o artefato gerado pelo deploy não existe. As páginas não podem quebrar nem exibir vazio nesse cenário.

### 2.7 `updated_at` sai do `config.php`

Depois que cada documento tiver a própria data, a chave compartilhada perde a razão de existir e deve ser removida, junto com o comentário que a acompanha — caso contrário fica uma segunda fonte de verdade convidando ao mesmo erro.

## 3. Fora de escopo desta feature

- **Outros documentos ou páginas**: só `privacidade.php` e `termos.php` exibem "Última atualização". Nenhuma outra página ganha o recurso.
- **Histórico de versões dos documentos legais** (changelog do que mudou em cada data): é outro produto, muito além de uma data.
- **Aviso ao usuário sobre mudança de política** (banner, e-mail, reconsentimento): decisão de produto, não entra aqui.
- **Os demais itens de backlog do site** seguem abertos: 049 (página 404), 050 (três nomes do produto), 054 (og:image dedicada), 055 (header em duas linhas no celular).
- **Revisão do conteúdo jurídico** dos documentos: esta feature mexe na data, não no texto.

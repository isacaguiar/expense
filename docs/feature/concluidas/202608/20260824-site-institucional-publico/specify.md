# Specify — Site Institucional Público

> Feature: cria uma presença pública do produto (`/site`), fora do app autenticado — landing page de marketing e páginas legais (Política de Privacidade e Termos de Serviço). Pedido novo, discutido diretamente com o usuário nesta conversa (sem task/épico anterior em `03-tasks.md`).

Versão: 1.1 · Criado em: 20260824 · Revisado em: 20260824 (index deixa de ser só a logo — vira landing page completa a partir de um mockup fornecido pelo usuário; stack muda de HTML puro para PHP)

---

## 1. Problema

O projeto hoje só tem `backend/` (API), `frontend/` (app web autenticado) e `app/` (futuro Expo). Não existe nenhuma página pública e estática que não dependa de login — nem uma landing de marketing, nem os textos legais de Política de Privacidade e Termos de Serviço que fluxos como login social (Google, ver `docs/feature/20260821-login-social-google/`) tipicamente exigem linkar.

## 2. Requisitos

### 2.1 Página inicial (index) — landing page de marketing

Landing page completa, reproduzindo o mockup fornecido pelo usuário em
`E:\Projetos\Controle de Despesas\assets\images\site.png` (fora do repo — referência de design,
não asset a versionar): header com logo + navegação + CTAs de login/cadastro, hero com headline,
subtexto, 3 destaques rápidos, CTAs e um preview ilustrativo do dashboard do app, grid de 6
recursos, seção "como funciona" em 3 passos, e faixa final de CTA. Marca usada no mockup:
"Shared Expense".

### 2.2 Página de Política de Privacidade

Página pública explicando quais dados o produto coleta (conta, grupos, despesas, dados de pagamento/Pix quando aplicável), como são usados e o contato para dúvidas.

### 2.3 Página de Termos de Serviço

Página pública com os termos de uso do serviço, acessível sem autenticação.

## 3. Decisões já confirmadas com o usuário (2026-08-24)

- Stack (revisão 1.1): PHP, seguindo boas práticas (separação view/template/config, escaping
  consistente, sem lógica de negócio real por não haver dado dinâmico) — decisão explícita do
  usuário nesta revisão, substitui a decisão anterior de HTML/CSS puro.
- Logo/marca da landing (revisão 1.1): segue o mockup — ícone + wordmark "Shared Expense"
  (recriado em SVG inline, não é o PNG antigo). O PNG `frontend/src/assets/images/logo-expense.png`
  continua usado como favicon.
- Nome do produto nos textos legais (Privacidade/Termos): mantido "Controle de Despesas
  Compartilhadas" — o mockup só define a marca da landing, não pediu para renomear os textos
  legais já aprovados.
- E-mail de contato: `novemax@gmail.com`.
- Botões "Entrar"/"Cadastre-se" e os itens de nav "Preços"/"Contato" não têm destino real definido
  ainda (não existe tela de preços nem domínio de produção); ficam como placeholder (`#`) até
  existir uma URL real do app/marketing a apontar — registrado para não ficar implícito.

## 4. Fora de escopo desta feature

- Deploy real em domínio público ou pipeline de CI/CD para `/site`.
- Qualquer lógica dinâmica real, formulário de contato funcional, analytics ou integração com o
  backend/API (a landing é estática; os números do dashboard exibido são ilustrativos, não vêm da
  API).
- Tradução multi-idioma.
- Página de preços real (o nav item "Preços" é só um placeholder visual do mockup).
- Revisão jurídica formal dos textos de Privacidade/Termos — são um rascunho razoável, não
  substituem validação por um advogado antes de uso em produção real.

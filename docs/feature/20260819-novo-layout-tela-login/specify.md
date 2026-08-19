# Specify — Novo Layout da Tela de Login

> Feature: modernizar visualmente a tela de login do `frontend/` (React + MUI), substituindo o layout atual — minimalista, card único centralizado — por um layout de duas colunas com branding, proposta de valor e ilustração, conforme mockup fornecido pelo usuário. Pedido novo, sem épico correspondente em `docs/sdd/03-tasks.md`.

Versão: 1.0 · Criado em: 20260819

---

## 1. Problema

A tela de login atual (`frontend/src/pages/LoginPage.tsx:56-116`) é um card MUI genérico e centralizado: avatar com ícone de cadeado, título "Login", campos de e-mail/senha, checkbox "Lembrar-me" e um texto estático "Esqueceu sua senha?" sem link funcional. Não há identidade visual do produto (nome, logo, proposta de valor) nem elementos de confiança — a tela não comunica o que é o "Shared Expense" nem por que usá-lo antes do usuário logar.

O usuário forneceu um mockup de referência (duas colunas: branding/ilustração à esquerda, card de login à direita) e pediu que o layout seja seguido à risca, incluindo textos, ícones e ilustração — sem alterar a lógica de autenticação existente (`handleSubmit` em `LoginPage.tsx:21-53`, que chama `POST {API_BASE_URL}/api/login` e grava `accessToken`/`refreshToken` no `localStorage`).

## 2. Requisitos

### 2.1 Layout geral (desktop, ≥ md no breakpoint MUI)

- Duas colunas ocupando a viewport inteira: coluna esquerda com fundo em gradiente suave (verde-claro → branco) contendo branding; coluna direita com fundo claro contendo o card de login centralizado verticalmente.
- Rodapé global fixo na base da página, fora das duas colunas, com copyright e links "Termos de uso" / "Política de privacidade".
- Seletor de idioma ("🌐 Português (Brasil)") fixo no canto superior direito, acima do card de login.

### 2.2 Coluna esquerda — branding e proposta de valor

- Logo: ícone circular (cifrão "$" com duas cabeças estilizadas ao redor, remetendo a "pessoas compartilhando") + wordmark "Shared Expense" (com "Shared" em tom escuro e "Expense" em verde).
- Headline em duas linhas: "Despesas compartilhadas," (tom escuro) / "contas em dia." (verde).
- Parágrafo de apoio: "Controle de despesas mensais fixas e variáveis entre grupos de usuários, com divisão igualitária dos valores entre os pagadores designados."
- Lista de 3 diferenciais, cada um com ícone em card branco arredondado + título em negrito + descrição de uma linha:
  1. "Grupos organizados" — "Crie grupos e convide amigos, familiares ou colegas."
  2. "Divisão igualitária" — "O sistema divide os valores igualmente entre os membros."
  3. "Seguro e confiável" — "Seus dados são protegidos com segurança e privacidade garantida."
- Ilustração na parte inferior: três pessoas em torno de uma mesa com notebook/celulares, mais três badges flutuantes decorativos (gráfico de barras, ícone de cifrão em círculo verde, gráfico de pizza) — asset fornecido pelo usuário (`assets/images/img_login.png` na raiz do projeto, copiado para `frontend/src/assets/illustrations/login-hero.png`), não gerado pela IA.
- Rodapé da coluna esquerda: selo "🛡 Seguro e confiável · Seus dados protegidos".

### 2.3 Coluna direita — card de login

- Card branco arredondado, sombra suave, centralizado.
- Ícone de cadeado em círculo verde-claro no topo do card.
- Título "Bem-vindo de volta!" + subtítulo "Faça login para acessar sua conta".
- Campo "E-mail" com ícone de envelope, placeholder "seu@email.com" — vinculado ao estado `email` já existente em `LoginPage.tsx`.
- Campo "Senha" com ícone de cadeado, placeholder "Sua senha", botão de alternar visibilidade (ícone de olho) — vinculado ao estado `password` já existente.
- Linha com checkbox "Lembrar de mim" (à esquerda) e link "Esqueci minha senha" (à direita, verde).
- Botão "Entrar" — largura total, verde sólido — dispara o `handleSubmit` já existente (mesma chamada a `/api/login`, sem alteração de lógica).
- Divisor com texto "ou continue com".
- Dois botões secundários lado a lado: "Google" (logo colorido) e "Microsoft" (logo colorido) — cada um é um link apontando para `href="#"`, sem integração OAuth (ver §3).
- Rodapé do card: "Ainda não tem uma conta? Cadastre-se" (link em verde) — apenas visual nesta feature (ver §3).

### 2.4 Identidade visual

- Paleta: verde principal (~#1FA64A / tom próximo ao já usado em botões primários do MUI theme atual), verde-escuro/petróleo para textos de destaque, cinza neutro para textos secundários, branco para cards.
- Tipografia: manter a fonte padrão já configurada no tema MUI do projeto; não introduzir nova fonte.
- Todos os componentes construídos com MUI (`Box`, `Grid`/`Stack`, `TextField`, `Button`, `Checkbox`, `Typography`, `Avatar`, ícones `@mui/icons-material`), consistente com o restante do `frontend/`.

## 3. Fora de escopo desta feature

- **Login social funcional (Google/Microsoft)**: os botões "Google" e "Microsoft" são apenas links visuais para `href="#"`, sem integração OAuth. Implementar o login de fato depende de decisão de produto ainda pendente (`TASK-021` em `docs/sdd/03-tasks.md`, e das credenciais órfãs registradas em `00-constitution.md` §5.3) — não é resolvido aqui. Registrado como ideias de backlog `014` (Google) e `015` (Microsoft) em `docs/backlog/`.
- **Fluxo de recuperação de senha**: o link "Esqueci minha senha" é visual; não existe página/endpoint de recuperação de senha no projeto hoje e esta feature não cria um.
- **Página de cadastro**: o link "Cadastre-se" é visual; não existe rota `/register` (ou equivalente) hoje e esta feature não cria uma.
- **Internacionalização real**: o seletor "Português (Brasil)" aparece no layout, mas não há sistema de i18n no `frontend/` hoje; esta feature não implementa troca de idioma — o seletor é estático/decorativo (ou, no máximo, um `Select` do MUI sem lógica associada).
- **Termos de uso / Política de privacidade**: links de rodapé são visuais; as páginas de destino não são criadas aqui.
- **Qualquer mudança na lógica de autenticação, backend, rotas privadas ou `RequireAuth`**: `handleSubmit`, chamada a `/api/login`, armazenamento de tokens e redirecionamento para `/dashboard` permanecem exatamente como estão hoje — só a camada visual muda.
- **Layout mobile/responsivo detalhado**: o mockup fornecido é desktop; o comportamento em telas estreitas (empilhar colunas, ocultar ilustração, etc.) fica como decisão técnica a ser detalhada em `plan.md`, seguindo os breakpoints já usados no restante do `frontend/`.

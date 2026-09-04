# Plan — Novo Layout da Tela de Login

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260819

---

## 1. Layout geral de duas colunas (specify §2.1)

- Reescrever `frontend/src/pages/LoginPage.tsx` mantendo o componente único (sem criar rota nova) — o `<Route path="/" element={<LoginPage />} />` em `App.tsx:18` não muda.
- Estrutura com `Box`/`Grid` do MUI: um container `display: flex` de altura 100vh, duas colunas (`Box` esquerda e direita), mais um `Box` de rodapé fixo abaixo (fora do `flex` das colunas, ou como footer absoluto).
- Extrair subcomponentes dentro de `frontend/src/pages/login/` (pasta nova) para manter `LoginPage.tsx` legível: `LoginBrandingPanel.tsx` (coluna esquerda), `LoginFormCard.tsx` (coluna direita), `LoginPageFooter.tsx` (rodapé global). `LoginPage.tsx` vira o componente que monta os três e mantém o estado (`email`, `password`) e o `handleSubmit` — sem tocar na lógica hoje em `LoginPage.tsx:21-53`.
- Seletor de idioma: `Box` fixo (`position: absolute`, canto superior direito) com ícone `LanguageOutlined` (`@mui/icons-material`) + texto "Português (Brasil)" — sem `Select` funcional, é só texto (decisão mais simples que um `Select` desabilitado, e evita sugerir interatividade que não existe).

## 2. Coluna esquerda — branding (specify §2.2)

- Logo: `Avatar`/`Box` circular com o cifrão + wordmark "Shared Expense" em `Typography`, dois `<span>` de cor diferente (`color="text.primary"` para "Shared", verde para "Expense") — reaproveita o mesmo `Avatar` que hoje existe em `LoginPage.tsx:65-67`, só reestilizado.
- Ícones dos 3 diferenciais: `@mui/icons-material` já instalado (`^7.1.1`) tem os símbolos necessários — `GroupsOutlined` (Grupos organizados), `PieChartOutlineOutlined` (Divisão igualitária), `ShieldOutlined` (Seguro e confiável). Cada item é um `Box` com ícone num círculo branco + `Typography` título/descrição, em `Stack` vertical.
- Ilustração: `.svg` original criado em `frontend/src/assets/illustrations/login-hero.svg` (pasta nova — `frontend/src/assets/` ainda não existe) e importado como URL estática (`import loginHero from '../../assets/illustrations/login-hero.svg'`, uso em `<img src={loginHero} />` — Vite trata `.svg` como asset por padrão, sem precisar de plugin SVGR). Composição: 3 pessoas estilizadas ao redor de notebook/celulares + 3 badges decorativos (gráfico de barras, cifrão em círculo, gráfico de pizza), traço simples/flat, paleta da §2.4 — arte vetorial original, não referência a nenhum ícone de terceiros com direito autoral.
- Rodapé da coluna: `Typography` com ícone `ShieldOutlined` inline + texto fixo.

## 3. Coluna direita — card de login (specify §2.3)

- `Card`/`Box` branco arredondado (`borderRadius`, `boxShadow` via `sx`) envolvendo o conteúdo hoje solto em `LoginPage.tsx:56-113`.
- Ícone de cadeado: reaproveita `LockOutlinedIcon` já importado (`LoginPage.tsx:9`), só reposicionado dentro de um círculo verde-claro.
- Campos de e-mail/senha: os `TextField` existentes (`LoginPage.tsx:72-95`) ganham `InputAdornment` com ícone (`EmailOutlined`, `LockOutlined`) e placeholder — o `value`/`onChange` continuam ligados aos mesmos `useState` (`email`, `password`), sem mudança de comportamento.
- Alternar visibilidade de senha: novo `useState<boolean>` local (`showPassword`) só para alternar `type="password" | "text"` do campo — estado puramente de UI, não mexe em `handleSubmit`.
- Checkbox "Lembrar de mim": reaproveita o `Checkbox`/`FormControlLabel` já existente (`LoginPage.tsx:96-99`), só reposicionado ao lado do link "Esqueci minha senha".
- "Esqueci minha senha" e "Cadastre-se": `<a href="#">` (ou `Link` do MUI com `href="#"`) — mesmo padrão decidido para os botões sociais (ver `especify.md` §2.3/§3), sem rota nem `onClick`.
- Botão "Entrar": reaproveita o `Button type="submit"` existente (`LoginPage.tsx:100-107`), só restilizado (verde, largura total) — continua disparando o mesmo `handleSubmit`.
- Botões "Google"/"Microsoft": `Button` ou `<a>` com `href="#"`, ícone de marca à esquerda + texto. Como `@mui/icons-material` não tem os logos de marca (Google/Microsoft), criar dois SVGs simples e genéricos em `frontend/src/assets/illustrations/` (`google-logo.svg`, `microsoft-logo.svg`) — representação mínima reconhecível (cores oficiais, forma simplificada), não um asset baixado de terceiros. Sem `onClick`/handler — são apenas links visuais, conforme decidido no `specify.md` §3 e registrado nos itens de backlog `014`/`015`.

## 4. Identidade visual (specify §2.4)

- **Não alterar `frontend/src/theme.ts`** (tema global, `primary.main = '#1976d2'`) — mudar o tema global afetaria todas as outras telas do app, fora do escopo desta feature (redesign só da tela de login).
- Cores do mockup (verde ~`#1FA64A`, verde-escuro para headline, cinza neutro) ficam como constantes locais dentro dos componentes de `frontend/src/pages/login/` (ex.: um objeto `loginColors` exportado de um arquivo `frontend/src/pages/login/colors.ts`), aplicadas via `sx`/`style` — não via `ThemeProvider` novo, para não complicar o restante do app com um tema aninhado.
- Tipografia: nenhuma mudança — continua herdando `fontFamily: 'Roboto, Arial, sans-serif'` de `theme.ts:15`.

## 5. Responsividade (specify §3, decisão adiada no specify)

- Breakpoint `md` do MUI (mesmo padrão já usado implicitamente pelo resto do app via `Container maxWidth`): abaixo de `md`, a coluna esquerda (branding + ilustração) fica oculta (`display: { xs: 'none', md: 'flex' }`) e a coluna direita (card de login) ocupa 100% da largura, centralizada, com padding lateral.
- Rodapé global e seletor de idioma continuam visíveis em qualquer largura (ambos são leves e não competem por espaço com o card).
- Sem breakpoint intermediário (`sm`) dedicado — mobile e tablet tratados igual (coluna única), desktop (`md+`) usa as duas colunas do mockup.

## 6. Testes (convenção do projeto, `05-context-frontend.md`)

- Não existe hoje `LoginPage.test.tsx`. Criar um teste mínimo cobrindo: (a) os campos de e-mail/senha e o botão "Entrar" são renderizados; (b) preencher os campos e submeter ainda dispara a chamada a `${API_BASE_URL}/api/login` (mock de `fetch`, mesmo padrão dos testes existentes em `frontend/src/pages/*.test.tsx`); (c) os elementos novos do mockup (headline, 3 diferenciais, botões sociais) estão presentes no DOM. Não testar navegação real de "Esqueci minha senha"/"Cadastre-se"/social (são `href="#"`, sem comportamento).

## 7. Ordem de execução

Sem dependência técnica rígida entre os itens 2-6 (são áreas visuais distintas do mesmo componente), mas a ordem em `tasks.md` segue a sequência natural de montagem, do mais estrutural para o mais cosmético:

1. Estrutura de duas colunas + extração dos subcomponentes (item 1) — pré-requisito de arquivo para todo o resto.
2. Card de login reestilizado (item 3) — é a parte funcional (formulário), prioridade sobre a coluna decorativa.
3. Coluna de branding + ilustração + ícones de marca (itens 2 e parte de 3 — assets SVG).
4. Identidade visual/cores (item 4) — aplicado depois que a estrutura existe, para não retrabalhar estilo em componentes que ainda vão mudar de lugar.
5. Responsividade (item 5) — ajuste final sobre o layout já montado.
6. Testes (item 6) — cobre o resultado final de 1-5.

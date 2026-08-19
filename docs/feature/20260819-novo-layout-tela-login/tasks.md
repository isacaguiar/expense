# Tasks — Novo Layout da Tela de Login

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs a partir de `TASK-070` — maior ID já usado no projeto antes desta feature: `TASK-069` (`docs/feature/20260818-resumo-grupo-dashboard/tasks.md`).

Versão: 1.0 · Criado em: 20260819

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-070 | Estrutura de duas colunas em `LoginPage.tsx` (subcomponentes `LoginBrandingPanel`/`LoginFormCard`/`LoginPageFooter`), seletor de idioma estático e rodapé global | frontend | plan.md §1 | nenhum | Concluída |
| TASK-071 | Reestilizar `LoginFormCard` — ícone de cadeado, título/subtítulo, campos e-mail/senha com ícones e alternância de visibilidade, "Lembrar de mim" + "Esqueci minha senha" (`href="#"`), botão "Entrar" — mantendo `handleSubmit` existente | frontend | plan.md §3 | nenhum | Concluída |
| TASK-072 | Divisor "ou continue com" + botões "Google"/"Microsoft" (`href="#"`, sem OAuth) + link "Cadastre-se" (`href="#"`) em `LoginFormCard` | frontend | plan.md §3 | nenhum | Concluída |
| TASK-073 | Criar asset SVG original da ilustração (pessoas + badges decorativos) em `frontend/src/assets/illustrations/login-hero.svg` | frontend | plan.md §2 | nenhum | Concluída |
| TASK-074 | `LoginBrandingPanel`: logo/wordmark, headline, texto de apoio, 3 diferenciais com ícones, ilustração (TASK-073) e selo de rodapé | frontend | plan.md §2 | nenhum | Pendente |
| TASK-075 | Aplicar paleta de cores do mockup nos componentes de login via constantes locais (`loginColors`), sem alterar `theme.ts` global | frontend | plan.md §4 | nenhum | Pendente |
| TASK-076 | Responsividade: ocultar `LoginBrandingPanel` abaixo do breakpoint `md`, `LoginFormCard` ocupa largura total | frontend | plan.md §5 | nenhum | Pendente |
| TASK-077 | Testes frontend de `LoginPage` (campos/botões renderizados, submit ainda chama `/api/login`, elementos do novo layout presentes) | frontend | plan.md §6 | nenhum | Pendente |

## Critérios de aceite

- **TASK-070**: em viewport desktop (`≥ md`), `/` renderiza duas colunas ocupando a tela toda (esquerda vazia/placeholder, direita com o card atual); seletor "🌐 Português (Brasil)" visível no canto superior direito; rodapé com copyright e links "Termos de uso"/"Política de privacidade" visível no final da página, fora das duas colunas.
- **TASK-071**: preencher e-mail/senha e clicar "Entrar" ainda dispara `fetch` para `${API_BASE_URL}/api/login` (`read_network_requests` confirma a chamada) e navega para `/dashboard` em caso de sucesso — comportamento idêntico ao `handleSubmit` atual; ícone de olho alterna o campo de senha entre `type="password"` e `type="text"`; clicar "Esqueci minha senha" não dispara nenhuma requisição (é `href="#"`).
- **TASK-072**: botões "Google" e "Microsoft" e o link "Cadastre-se" estão visíveis no card, cada um como link `href="#"`; clicar em qualquer um deles não dispara requisição de rede nem navegação de rota.
- **TASK-073**: arquivo `frontend/src/assets/illustrations/login-hero.svg` existe, abre corretamente como imagem (sem erro de parsing SVG) e não é cópia de asset de terceiros com direito autoral.
- **TASK-074**: coluna esquerda em viewport desktop mostra, nesta ordem: logo "Shared Expense", headline de duas linhas, parágrafo de apoio, os 3 diferenciais (título + descrição + ícone cada), a ilustração de `TASK-073` e o selo de rodapé "Seguro e confiável".
- **TASK-075**: inspecionar (`javascript_tool`/`read_page`) os elementos de destaque do login (headline, botão "Entrar", wordmark "Expense") mostra as cores do mockup (verde ~`#1FA64A`); `frontend/src/theme.ts` permanece sem diff (`git diff` vazio no arquivo) — nenhuma outra tela do app muda de cor.
- **TASK-076**: `resize_window` para `mobile`/`tablet` (< breakpoint `md`) oculta `LoginBrandingPanel` por completo e `LoginFormCard` ocupa a largura da viewport com padding lateral, sem overflow horizontal; `resize_window` para `desktop` volta a mostrar as duas colunas.
- **TASK-077**: `npx vitest run` verde cobrindo: renderização dos campos/botão "Entrar"; submit chamando `fetch` mockado para `/api/login`; presença no DOM da headline, dos 3 diferenciais e dos botões "Google"/"Microsoft".

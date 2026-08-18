# Tasks — Migração do Frontend para React Native (Expo)

> IDs TASK-001 a TASK-010 preservados de `docs/sdd/03-tasks.md` (Épico A original) para manter rastreabilidade. TASK-022/023 (consolidação técnica) e TASK-024/025/026 (divisão da TASK-007 original, por atomicidade) são novas, com os próximos IDs livres do projeto (maior ID anterior: TASK-021). Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260817

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-022 | Consolidar client HTTP único em `frontend/src/api.ts` com interceptor de `Authorization`, substituindo chamadas axios/fetch diretas com URL hardcoded em todas as páginas | frontend | plan.md §2.1 | antes do merge | Pendente |
| TASK-023 | Introduzir abstração assíncrona de storage de token (`getToken`/`setToken`/`clearToken`) e `AuthContext` compartilhado, substituindo a leitura síncrona de `localStorage` duplicada em 8 arquivos | frontend | plan.md §2.1 | antes do merge | Pendente |
| TASK-001 | Scaffold do projeto Expo (TypeScript) em `expense/app`, com Expo Router configurado e uma rota placeholder — sem telas de negócio ainda | frontend | plan.md §1 | nenhum | Pendente |
| TASK-002 | Instalar e configurar `react-native-paper`, com tema (`MD3Theme`) espelhando cores/tipografia de `frontend/src/theme.ts` | frontend | plan.md §1 | nenhum | Pendente |
| TASK-003 | Portar client HTTP (`api.ts`, já consolidado pela TASK-022) para `expense/app`, trocando armazenamento do JWT para a abstração da TASK-023 (`expo-secure-store`), com fallback web funcional via `react-native-web` | frontend | plan.md §1.1 | nenhum | Pendente |
| TASK-004 | Implementar fluxo de login (`LoginPage` → tela Expo Router com RN Paper), incluindo persistência/leitura do token via TASK-003/023 | frontend | plan.md §1.2 | nenhum | Pendente |
| TASK-005 | Navegação principal: portar `Navbar`/`InternalLayout` para layout do Expo Router (tabs ou drawer, a definir na task) | frontend | plan.md §1.1 | nenhum | Pendente |
| TASK-006 | Tela Dashboard | frontend | plan.md §1.2 | nenhum | Pendente |
| TASK-024 | Tela `GroupList` (Expo Router + RN Paper) | frontend | plan.md §1.2 | nenhum | Pendente |
| TASK-025 | Tela `GroupForm` (criação/edição de grupo) | frontend | plan.md §1.2 | nenhum | Pendente |
| TASK-026 | Tela `GroupMembersForm` | frontend | plan.md §1.2 | nenhum | Pendente |
| TASK-008 | Tela de Despesas: `ExpenseManager` (criação de despesa, seleção de pagadores, parcelas) | frontend | plan.md §1.2 | nenhum | Pendente |
| TASK-009 | Validar build web do Expo (`react-native-web`) rodando em porta separada da atual (5173 é do `expense/frontend`), com todas as telas das TASK-004, TASK-005, TASK-006, TASK-024, TASK-025, TASK-026 e TASK-008 navegáveis | frontend | plan.md §1 | nenhum | Pendente |
| TASK-010 | Decisão de corte: apontar produção para `expense/app` no lugar de `expense/frontend` (ou manter os dois) | infra | plan.md §1 | **antes do deploy** | Pendente |

> Nota: TASK-007 do épico original ("Telas de Grupo: `GroupList`, `GroupForm`, `GroupMembersForm`") foi substituída pelas TASK-024/025/026 acima — a descrição original agrupava três entregas independentes numa task só, o que viola a regra de atomicidade deste mesmo arquivo.

## Critérios de aceite

- **TASK-022**: nenhuma página em `frontend/src/pages` chama `axios`/`fetch` diretamente com URL hardcoded — todas passam por `frontend/src/api.ts`; `grep -rn "axios\.\(get\|post\|put\|delete\)\|fetch(" frontend/src/pages` não retorna chamadas com URL literal.
- **TASK-023**: nenhum arquivo em `frontend/src` chama `localStorage.getItem('accessToken')`/`localStorage.setItem`/`localStorage.clear` diretamente fora do módulo de storage; todas as leituras passam pelo `AuthContext`.
- **TASK-001**: `npx expo start` sobe o app e a rota placeholder renderiza sem erro, em `expense/app`.
- **TASK-002**: alterar uma cor do tema em um único lugar reflete visualmente em pelo menos dois componentes RN Paper diferentes na tela placeholder.
- **TASK-003**: uma chamada autenticada de teste (ex. endpoint que exige JWT) funciona tanto no build web (`react-native-web`) quanto no simulador/dispositivo nativo, usando o mesmo client.
- **TASK-004**: login com credencial válida navega para a tela seguinte e persiste o token (fechar e reabrir o app mantém a sessão); credencial inválida mostra erro sem navegar.
- **TASK-005**: navegar entre pelo menos duas seções via tabs/drawer sem recarregar o app.
- **TASK-006**: Dashboard renderiza dados reais vindos da API autenticada.
- **TASK-024**: lista de grupos do usuário autenticado é exibida, uma linha por grupo.
- **TASK-025**: criar um grupo novo e editar um existente refletem no backend (verificável via `GroupList` atualizada).
- **TASK-026**: adicionar/remover um membro de grupo reflete no backend.
- **TASK-008**: criar uma despesa com múltiplos pagadores e parcelas é aceito pela API e aparece na tela.
- **TASK-009**: `expense/app` compilado para web sobe em porta diferente de 5173 e todas as telas das tasks anteriores são navegáveis sem erro de console.
- **TASK-010**: decisão registrada (documento ou ata) antes de qualquer alteração de deploy/produção; não há como concluir esta task sem aprovação humana explícita.

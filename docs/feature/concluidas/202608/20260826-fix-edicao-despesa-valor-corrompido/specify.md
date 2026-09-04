# Specify — Corrigir Corrupção de `total_value` ao Editar Despesa

> Feature: corrige um bug crítico já em produção — `ExpenseView.tsx` (tela de edição de despesa) reenvia `total_value` multiplicado por ~100 em toda edição salva, mesmo quando o usuário não toca no campo Valor. Origem: achado durante a implementação de `docs/feature/concluidas/202608/20260826-editar-tipo-despesa/` (um teste novo daquela feature expôs o bug); o usuário pediu correção imediata, em branch separada.

Versão: 1.0 · Criado em: 20260826

---

## 1. Problema

`frontend/src/pages/ExpenseView.tsx`, modo edição: `startEditing()` pré-preenche o campo Valor com `String(expense.total_value)`. `total_value` é `decimal:2` no backend (`Expense.php`), então a API sempre devolve uma string tipo `"100.00"`. `handleSave()` faz `parseFloat(value.replace('.', '').replace(',', '.'))` — lógica desenhada pra ler número digitado em pt-BR (ponto = separador de milhar, vírgula = decimal), não pro formato de máquina que a API devolve. Aplicado a `"100.00"`: remove o ponto (único caractere não-dígito) → `"10000"` → `parseFloat` → **10000**, não 100.

Como `total_value: valueNumber` é sempre incluído no payload do PUT (independente de qual campo o usuário realmente editou), **toda edição salva por essa tela multiplica `total_value` por ~100**, silenciosamente — sem erro, sem confirmação, dado financeiro real sendo corrompido a cada uso.

## 2. Achados confirmados

### 2.1 O bug é 100% determinístico e afeta qualquer edição, não só a de Valor

`frontend/src/pages/ExpenseView.tsx` (antes desta correção): `setValue(String(expense.total_value))` em `startEditing()`; `handleSave()` sempre lê `value` e sempre manda `total_value` no payload — editar só a descrição, a data ou o credor já dispara a corrupção.

### 2.2 `ExpenseForm.tsx` (criação) não tem o mesmo bug

Lá o campo Valor começa vazio (`useState<string>('')`) — o usuário sempre digita do zero, em formato pt-BR real, então o parser (`value.replace('.', '').replace(',', '.')`) funciona como desenhado. O bug é específico de pré-preencher com um valor vindo da API (formato de máquina) usando um parser feito pra leitura de digitação humana.

### 2.3 Nenhum teste existente pegava isso

O teste `'enters edit mode with the current values pre-filled and saves via PUT'` (`ExpenseView.test.tsx`) já fazia esse fluxo exato (editar descrição, salvar sem tocar em Valor) mas nunca afirmava o valor de `total_value` no payload — só `description`/`user_payer_id`/`payers` via `toMatchObject` (que não checa ausência/correção de outros campos).

### 2.4 Único lugar afetado no código

Busca por esse padrão de parsing (`replace('.', '').replace(',', '.')`) encontrou só `ExpenseForm.tsx` (seguro, achado 2.2) e `ExpenseView.tsx` (bug).

## 3. Requisitos

- **R1**: `startEditing()` pré-preenche o campo Valor no mesmo formato pt-BR que o parser espera (`Number(total_value).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2})`) em vez do formato cru da API.
- **R2**: Teste cobrindo especificamente "salvar sem tocar no campo Valor não corrompe `total_value`" — para não regredir silenciosamente de novo.

## 4. Fora de escopo

- Qualquer mudança na função de parsing em si (`replace/parseFloat`) — o formato de entrada nunca mais diverge do esperado por ela depois desta correção, então trocar a lógica de parsing não é necessário.
- `ExpenseForm.tsx` — não tem o bug (achado 2.2), não precisa de mudança.
- Auditoria de dado já corrompido em produção (despesas cujo `total_value` já foi multiplicado por edições anteriores) — decisão de correção de dado histórico é do usuário, não desta feature.

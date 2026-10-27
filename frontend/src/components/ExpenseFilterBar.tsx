import React from 'react';
import { Autocomplete, Box, TextField, ToggleButton, ToggleButtonGroup } from '@mui/material';
import SearchOutlinedIcon from '@mui/icons-material/SearchOutlined';
import type { ExpenseFilterControls } from '../hooks/useExpenseFilters';
import type { FilterOption } from '../utils/expenseFilters';

// O projeto não configura o locale do MUI; sem estes textos o Autocomplete
// aparece em inglês ("No options", "Clear").
const AUTOCOMPLETE_TEXTS = {
  noOptionsText: 'Nenhuma opção',
  clearText: 'Limpar',
  openText: 'Abrir',
  closeText: 'Fechar'
};

type PersonFilterProps = {
  label: string;
  options: FilterOption[];
  selected: FilterOption[];
  onChange: (keys: string[]) => void;
};

// Select múltiplo de pessoas, igual para Credor e Devedores. Em tela estreita
// ocupa a linha inteira, em vez de espremer os chips ao lado da busca.
const PersonFilter: React.FC<PersonFilterProps> = ({ label, options, selected, onChange }) => (
  <Autocomplete
    multiple
    size="small"
    limitTags={1}
    disableCloseOnSelect
    {...AUTOCOMPLETE_TEXTS}
    options={options}
    value={selected}
    getOptionLabel={option => option.name}
    isOptionEqualToValue={(option, value) => option.key === value.key}
    onChange={(_, values) => onChange(values.map(value => value.key))}
    renderInput={params => <TextField {...params} label={label} />}
    sx={{ width: { xs: '100%', sm: 240 } }}
  />
);

type ExpenseFilterBarProps = {
  filters: ExpenseFilterControls;
};

/**
 * Busca e filtros da lista de despesas (credor, devedores, tipo, status), os mesmos
 * em Despesas e em Pagamentos. Só desenha e repassa os `onChange` ao estado de
 * `useExpenseFilters`; quem filtra é `utils/expenseFilters.ts`.
 * docs/feature/concluidas/202610/20261026-organizacao-despesas-pagamentos/plan.md §4
 */
const ExpenseFilterBar: React.FC<ExpenseFilterBarProps> = ({ filters }) => (
  <Box display="flex" gap={2} mb={3} flexWrap="wrap" alignItems="center">
    <TextField
      label="Buscar despesa"
      value={filters.search}
      onChange={e => filters.setSearch(e.target.value)}
      size="small"
      sx={{ minWidth: 240 }}
      InputProps={{
        startAdornment: <SearchOutlinedIcon fontSize="small" sx={{ color: 'text.secondary', mr: 1 }} />
      }}
    />
    <PersonFilter
      label="Credor"
      options={filters.creditorOptions}
      selected={filters.selectedCreditors}
      onChange={filters.setCreditorKeys}
    />
    <PersonFilter
      label="Devedores"
      options={filters.debtorOptions}
      selected={filters.selectedDebtors}
      onChange={filters.setDebtorKeys}
    />
    <ToggleButtonGroup
      aria-label="Tipo"
      value={filters.typeFilter}
      exclusive
      size="small"
      onChange={(_, value) => value && filters.setTypeFilter(value)}
    >
      <ToggleButton value="all">Todas</ToggleButton>
      <ToggleButton value="fixed">Fixas</ToggleButton>
      <ToggleButton value="variable">Variáveis</ToggleButton>
    </ToggleButtonGroup>
    <ToggleButtonGroup
      aria-label="Status"
      value={filters.statusFilter}
      exclusive
      size="small"
      onChange={(_, value) => value && filters.setStatusFilter(value)}
    >
      <ToggleButton value="all">Todas</ToggleButton>
      <ToggleButton value="paid">Pagas</ToggleButton>
      <ToggleButton value="pending">Pendentes</ToggleButton>
    </ToggleButtonGroup>
  </Box>
);

export default ExpenseFilterBar;

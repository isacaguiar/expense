import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import GroupPositionCard from './GroupPositionCard';
import { overviewGroupFixture } from '../types/overview.fixtures';

/**
 * Feature 20261112-ajustes-de-texto-participantes-e-pagamentos (TASK-611, CA-6, D5-A): o Painel usa o mesmo
 * `PayableSettlementList` que Pagamentos, mas **não** muda: segue com a frase "X deve pagar R$ N a Y" e a seta
 * solta de hoje, porque só o painel de abas de Pagamentos liga o arranjo "route". Teste de caracterização: passa
 * antes e depois da feature.
 */

vi.mock('axios');

const ME = { id: 7, name: 'Isac' };

describe('GroupPositionCard: a linha de acerto do Painel não muda', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.spyOn(console, 'error').mockImplementation(() => {});
    localStorage.setItem('accessToken', 'token-de-teste');
  });

  afterEach(() => {
    localStorage.removeItem('accessToken');
    vi.restoreAllMocks();
  });

  it('keeps the sentence "X deve pagar R$ N a Y" and the loose arrow, with no anchors of the "route" layout', async () => {
    const user = userEvent.setup();
    const { container } = render(
      <MemoryRouter>
        <GroupPositionCard overview={overviewGroupFixture()} currentUser={ME} onChanged={vi.fn()} />
      </MemoryRouter>
    );

    await user.click(screen.getByRole('button', { name: /Ver detalhes/ }));

    expect(screen.getByText(/deve pagar/).textContent).toBe('Isac deve pagar R$ 439,23 a Nádia');
    expect(container.querySelectorAll('[data-testid="ArrowForwardIcon"]')).toHaveLength(1);
    expect(screen.queryByTestId('settlement-card')).not.toBeInTheDocument();
    expect(screen.queryByTestId('settlement-amount')).not.toBeInTheDocument();
  });
});

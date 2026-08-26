import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import GroupHeader from './GroupHeader';

describe('GroupHeader', () => {
  it('calls onMenuClick when the navigation menu button is clicked', async () => {
    const onMenuClick = vi.fn();
    const user = userEvent.setup();

    render(
      <GroupHeader
        title="Resumo"
        groups={[]}
        groupId=""
        onGroupChange={vi.fn()}
        userName={null}
        onMenuClick={onMenuClick}
      />
    );

    await user.click(screen.getByRole('button', { name: 'Abrir menu de navegação' }));

    expect(onMenuClick).toHaveBeenCalledTimes(1);
  });

  it('still renders the title', () => {
    render(
      <GroupHeader
        title="Despesas"
        groups={[]}
        groupId=""
        onGroupChange={vi.fn()}
        userName={null}
        onMenuClick={vi.fn()}
      />
    );

    expect(screen.getByRole('heading', { name: 'Despesas' })).toBeInTheDocument();
  });
});

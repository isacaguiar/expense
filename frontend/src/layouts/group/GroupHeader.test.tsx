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

  it('does not render the group selector when there are no groups', () => {
    render(
      <GroupHeader
        title="Resumo"
        groups={[]}
        groupId=""
        onGroupChange={vi.fn()}
        userName="Ana Paula"
        onMenuClick={vi.fn()}
      />
    );

    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });

  it('renders the group selector with the current group when groups are provided', () => {
    render(
      <GroupHeader
        title="Resumo"
        groups={[
          { id: 1, name: 'Casa' },
          { id: 2, name: 'Viagem' },
        ]}
        groupId="2"
        onGroupChange={vi.fn()}
        userName="Ana Paula"
        onMenuClick={vi.fn()}
      />
    );

    expect(screen.getByRole('combobox')).toHaveTextContent('Viagem');
  });

  it('renders the user name and avatar initials when a user is provided', () => {
    render(
      <GroupHeader
        title="Resumo"
        groups={[]}
        groupId=""
        onGroupChange={vi.fn()}
        userName="Ana Paula"
        onMenuClick={vi.fn()}
      />
    );

    // O nome fica oculto em telas estreitas via `display:{xs:'none',sm:'block'}`
    // (confirmado visualmente em 375px), mas continua no DOM.
    expect(screen.getByText('Ana Paula')).toBeInTheDocument();
    expect(screen.getByText('AP')).toBeInTheDocument();
  });
});

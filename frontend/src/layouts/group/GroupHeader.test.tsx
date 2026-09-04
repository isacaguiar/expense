import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GroupHeader from './GroupHeader';

// GroupHeader é testado isolado do menu de notificações (que tem teste próprio
// em src/components/NotificationsMenu.test.tsx e depende de <Router>/axios).
const notificationsMenuSpy = vi.fn();
vi.mock('../../components/NotificationsMenu', () => ({
  default: (props: { open: boolean }) => {
    notificationsMenuSpy(props);
    return null;
  }
}));

const baseProps = {
  title: 'Resumo',
  groups: [] as { id: number; name: string }[],
  groupId: '',
  onGroupChange: vi.fn(),
  userName: null as string | null,
  onMenuClick: vi.fn()
};

describe('GroupHeader', () => {
  beforeEach(() => {
    notificationsMenuSpy.mockClear();
  });

  it('calls onMenuClick when the navigation menu button is clicked', async () => {
    const onMenuClick = vi.fn();
    const user = userEvent.setup();

    render(<GroupHeader {...baseProps} onMenuClick={onMenuClick} />);

    await user.click(screen.getByRole('button', { name: 'Abrir menu de navegação' }));

    expect(onMenuClick).toHaveBeenCalledTimes(1);
  });

  it('still renders the title', () => {
    render(<GroupHeader {...baseProps} title="Despesas" />);

    expect(screen.getByRole('heading', { name: 'Despesas' })).toBeInTheDocument();
  });

  it('does not render the group selector when there are no groups', () => {
    render(<GroupHeader {...baseProps} userName="Ana Paula" />);

    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });

  it('renders the group selector with the current group when groups are provided', () => {
    render(
      <GroupHeader
        {...baseProps}
        groups={[
          { id: 1, name: 'Casa' },
          { id: 2, name: 'Viagem' }
        ]}
        groupId="2"
        userName="Ana Paula"
      />
    );

    expect(screen.getByRole('combobox')).toHaveTextContent('Viagem');
  });

  it('renders the user name and avatar initials when a user is provided', () => {
    render(<GroupHeader {...baseProps} userName="Ana Paula" />);

    // O nome fica oculto em telas estreitas via `display:{xs:'none',sm:'block'}`
    // (confirmado visualmente em 375px), mas continua no DOM.
    expect(screen.getByText('Ana Paula')).toBeInTheDocument();
    expect(screen.getByText('AP')).toBeInTheDocument();
  });

  it('shows the unread notifications count as a badge on the bell', () => {
    render(<GroupHeader {...baseProps} unreadCount={7} />);

    expect(screen.getByText('7')).toBeInTheDocument();
  });

  it('hides the badge when there are no unread notifications', () => {
    const { container } = render(<GroupHeader {...baseProps} />);

    expect(container.querySelector('.MuiBadge-badge')).toHaveClass('MuiBadge-invisible');
  });

  it('opens the notifications menu when the bell is clicked', async () => {
    const user = userEvent.setup();
    render(<GroupHeader {...baseProps} />);

    expect(notificationsMenuSpy).toHaveBeenLastCalledWith(expect.objectContaining({ open: false }));

    await user.click(screen.getByRole('button', { name: 'Notificações' }));

    expect(notificationsMenuSpy).toHaveBeenLastCalledWith(expect.objectContaining({ open: true }));
  });
});

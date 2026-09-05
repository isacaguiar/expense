import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import UserAvatar from './UserAvatar';

describe('UserAvatar', () => {
  it('renders the image when avatarUrl is provided', () => {
    render(<UserAvatar name="Ana Souza" avatarUrl="https://example.com/ana.jpg" />);
    const img = screen.getByRole('img', { name: 'Ana Souza' });
    expect(img).toHaveAttribute('src', 'https://example.com/ana.jpg');
  });

  it('falls back to initials when avatarUrl is null', () => {
    render(<UserAvatar name="Ana Souza" avatarUrl={null} />);
    expect(screen.getByText('AS')).toBeInTheDocument();
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
  });

  it('falls back to initials when avatarUrl is not provided', () => {
    render(<UserAvatar name="Bruno" />);
    expect(screen.getByText('B')).toBeInTheDocument();
  });

  it('shows the name in a tooltip on the avatar', async () => {
    render(<UserAvatar name="Carla Lima" avatarUrl={null} />);
    expect(screen.getByLabelText('Carla Lima')).toBeInTheDocument();
  });
});

import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { ScoreBadge, StatCard, SubPageHeading } from './shared';

describe('ScoreBadge (PHP score_badge / badge_result)', () => {
  it('shows a grey Pending badge when there is no score', () => {
    render(<ScoreBadge result="pass" score={null} />);
    const badge = screen.getByText('Pending');
    expect(badge).toHaveClass('bg-secondary');
  });

  it('shows a green percentage badge on pass', () => {
    render(<ScoreBadge result="pass" score={85} />);
    expect(screen.getByText('85%')).toHaveClass('bg-success');
  });

  it('shows a red percentage badge on fail', () => {
    render(<ScoreBadge result="fail" score={40} />);
    expect(screen.getByText('40%')).toHaveClass('bg-danger');
  });
});

describe('StatCard', () => {
  it('renders value and label', () => {
    render(<StatCard value={42} label="Classes Attended" />);
    expect(screen.getByText('42')).toBeInTheDocument();
    expect(screen.getByText('Classes Attended')).toBeInTheDocument();
  });
});

describe('SubPageHeading', () => {
  it('links back to the student dashboard tab and capitalizes the name', () => {
    render(
      <MemoryRouter>
        <SubPageHeading studentId={4} title="Payment History" name="emily wilson" />
      </MemoryRouter>,
    );
    expect(screen.getByRole('link', { name: /Dashboard/ })).toHaveAttribute('href', '/student/4');
    expect(screen.getByRole('heading')).toHaveTextContent('Payment History — Emily Wilson');
  });
});

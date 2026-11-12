import type { LeaderboardEntry } from '@/types/leaderboard';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { LeaderboardTable } from './leaderboard-table';

const entries: LeaderboardEntry[] = [
    {
        rank: 1,
        affiliateId: 8572722,
        name: 'Lily Kertzmann',
        email: 'lily@example.com',
        commissionCents: 702513,
        currency: 'USD',
    },
    {
        rank: 2,
        affiliateId: 8572717,
        name: 'Clark Rolfson',
        email: 'clark@example.com',
        commissionCents: 574870,
        currency: 'USD',
    },
    {
        rank: 3,
        affiliateId: 8572699,
        name: 'Peter Bosco',
        email: '',
        commissionCents: 572666,
        currency: 'USD',
    },
];

describe('LeaderboardTable', () => {
    it('renders the ranked affiliates with formatted commission', () => {
        render(<LeaderboardTable entries={entries} />);

        expect(screen.getByText('Lily Kertzmann')).toBeInTheDocument();
        expect(screen.getByText('Clark Rolfson')).toBeInTheDocument();
        expect(screen.getByText('$7,025.13')).toBeInTheDocument();
        expect(screen.getByText('$5,748.70')).toBeInTheDocument();
        expect(screen.getByLabelText('Rank 1')).toHaveTextContent('1');
        expect(screen.getByLabelText('Rank 2')).toHaveTextContent('2');
    });

    it('renders a dash when the email is missing', () => {
        render(<LeaderboardTable entries={entries} />);

        expect(screen.getByText('—')).toBeInTheDocument();
    });
});

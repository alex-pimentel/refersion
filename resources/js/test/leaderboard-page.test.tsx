import Leaderboard from '@/pages/leaderboard';
import type { LeaderboardPageData } from '@/types/leaderboard';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { post } = vi.hoisted(() => ({
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post },
}));

const entry = {
    rank: 1,
    affiliateId: 8572722,
    name: 'Avery Cole',
    email: 'avery@example.com',
    commissionCents: 702513,
    currency: 'USD',
};

const meta = {
    generatedAt: '2026-11-12T20:00:00+00:00',
    cached: false,
    totalAffiliates: 44,
    scannedAffiliates: 13,
    eligibleCount: 1,
    customFieldRequests: 20,
    durationMs: 120,
};

function pageData(overrides: Partial<LeaderboardPageData> = {}): LeaderboardPageData {
    return {
        data: [entry],
        meta,
        error: null,
        ...overrides,
    };
}

describe('Leaderboard page', () => {
    beforeEach(() => {
        post.mockClear();
    });

    it('shows a skeleton while the deferred data is loading', () => {
        render(<Leaderboard />);

        expect(screen.getByRole('status', { name: /loading leaderboard/i })).toBeInTheDocument();
    });

    it('preserves the page URL when refreshing', () => {
        render(<Leaderboard leaderboard={pageData()} />);

        fireEvent.click(screen.getByRole('button', { name: /refresh data/i }));

        expect(post).toHaveBeenCalledWith(
            '/leaderboard/refresh',
            {},
            expect.objectContaining({
                preserveState: true,
                preserveScroll: true,
                preserveUrl: true,
            }),
        );
    });

    it('labels cached data as stale when a refresh fails', () => {
        render(
            <Leaderboard
                leaderboard={pageData({
                    meta: { ...meta, cached: true },
                    error: {
                        message: 'Refersion is temporarily unavailable.',
                        reference: 'REF123',
                    },
                })}
            />,
        );

        expect(screen.getByText('Avery Cole')).toBeInTheDocument();
        expect(screen.getByText('Stale')).toBeInTheDocument();
        expect(screen.getByText(/showing last successful result/i)).toBeInTheDocument();
        expect(screen.queryByText('Live')).not.toBeInTheDocument();
    });
});

import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { EmptyState } from './empty-state';

describe('EmptyState', () => {
    it('explains the empty result and offers a refresh action', () => {
        const onRefresh = vi.fn();

        render(<EmptyState onRefresh={onRefresh} />);

        expect(screen.getByText(/No eligible affiliates yet/i)).toBeInTheDocument();
        expect(screen.getByText(/Display in leaderboard/i)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /refresh data/i }));

        expect(onRefresh).toHaveBeenCalledOnce();
    });
});

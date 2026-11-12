import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ErrorState } from './error-state';

describe('ErrorState', () => {
    it('renders a friendly message with a support reference and retry action', () => {
        const onRetry = vi.fn();

        render(
            <ErrorState
                message="We couldn't load the leaderboard right now."
                reference="ABC123"
                onRetry={onRetry}
            />,
        );

        expect(
            screen.getByRole('heading', { name: /couldn't load the leaderboard/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByText("We couldn't load the leaderboard right now."),
        ).toBeInTheDocument();
        expect(screen.getByText(/ABC123/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /try again/i }));

        expect(onRetry).toHaveBeenCalledOnce();
    });
});

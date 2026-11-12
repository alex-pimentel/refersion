import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { StatusBadge } from './status-badge';

describe('StatusBadge', () => {
    it('renders the live, cached, and stale labels', () => {
        const { rerender } = render(<StatusBadge status="live" />);
        expect(screen.getByText('Live')).toBeInTheDocument();

        rerender(<StatusBadge status="cached" />);
        expect(screen.getByText('Cached')).toBeInTheDocument();

        rerender(<StatusBadge status="stale" />);
        expect(screen.getByText('Stale')).toBeInTheDocument();
    });
});

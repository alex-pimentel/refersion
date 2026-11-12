import { describe, expect, it } from 'vitest';
import { formatCurrency, formatRelativeTime } from './format';

describe('formatCurrency', () => {
    it('formats integer cents as USD', () => {
        expect(formatCurrency(702513)).toBe('$7,025.13');
        expect(formatCurrency(574870)).toBe('$5,748.70');
    });

    it('formats zero and negative values', () => {
        expect(formatCurrency(0)).toBe('$0.00');
        expect(formatCurrency(-1250)).toBe('-$12.50');
    });
});

describe('formatRelativeTime', () => {
    it('formats a recent timestamp', () => {
        const now = new Date('2026-11-12T20:02:00Z');

        expect(formatRelativeTime('2026-11-12T20:00:00Z', now)).toBe('2 minutes ago');
    });

    it('handles an invalid timestamp safely', () => {
        expect(formatRelativeTime('not-a-date')).toBe('just now');
    });
});

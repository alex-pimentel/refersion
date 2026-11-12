import { cn } from '@/lib/utils';
import { RefreshCw } from 'lucide-react';

export function RefreshButton({
    loading,
    disabled,
    onClick,
}: {
    loading: boolean;
    disabled?: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled || loading}
            aria-busy={loading}
            className={cn(
                'inline-flex items-center justify-center gap-2 rounded-full bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm ring-1 ring-white/20 transition hover:bg-brand-cream focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2 focus-visible:ring-offset-brand-ink focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-70',
            )}
        >
            <RefreshCw className={cn('h-4 w-4', loading && 'animate-spin')} />
            {loading ? 'Refreshing…' : 'Refresh data'}
        </button>
    );
}

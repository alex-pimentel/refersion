import { cn } from '@/lib/utils';

export type LeaderboardStatus = 'live' | 'cached' | 'stale';

const styles: Record<LeaderboardStatus, string> = {
    live: 'bg-status-live-bg text-status-live-text',
    cached: 'bg-status-cached-bg text-status-cached-text',
    stale: 'bg-status-stale-bg text-status-stale-text',
};

const dots: Record<LeaderboardStatus, string> = {
    live: 'bg-brand-green',
    cached: 'bg-brand-blue',
    stale: 'bg-brand-orange',
};

const labels: Record<LeaderboardStatus, string> = {
    live: 'Live',
    cached: 'Cached',
    stale: 'Stale',
};

export function StatusBadge({ status }: { status: LeaderboardStatus }) {
    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold', styles[status])}>
            <span className={cn('h-1.5 w-1.5 rounded-full', dots[status])} />
            {labels[status]}
        </span>
    );
}

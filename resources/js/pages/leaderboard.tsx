import { EmptyState } from '@/components/empty-state';
import { ErrorState } from '@/components/error-state';
import { LeaderboardTable } from '@/components/leaderboard-table';
import { RefreshButton } from '@/components/refresh-button';
import { SkeletonTable } from '@/components/skeleton-table';
import { StatusBanner } from '@/components/status-banner';
import { formatRelativeTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { LeaderboardPageData } from '@/types/leaderboard';
import { Head, router } from '@inertiajs/react';
import { Trophy } from 'lucide-react';
import { useEffect, useState } from 'react';

type Props = {
    leaderboard?: LeaderboardPageData;
};

export default function Leaderboard({ leaderboard }: Props) {
    const [lastGood, setLastGood] = useState<LeaderboardPageData | null>(null);
    const [refreshing, setRefreshing] = useState(false);
    const [transportError, setTransportError] = useState<string | null>(null);

    useEffect(() => {
        if (leaderboard && leaderboard.error === null) {
            setLastGood(leaderboard);
        }
    }, [leaderboard]);

    const loading = leaderboard === undefined;
    const activeError =
        leaderboard?.error ??
        (transportError
            ? {
                  message: transportError,
                  reference: '',
              }
            : null);
    const data = activeError ? (lastGood?.data ?? []) : (leaderboard?.data ?? []);
    const meta = activeError ? (lastGood?.meta ?? null) : (leaderboard?.meta ?? null);
    const hasData = data.length > 0;
    const showFullError = activeError !== null && !hasData;
    const showEmpty = !loading && !activeError && data.length === 0;
    const showTable = !loading && hasData;

    const refresh = () => {
        setTransportError(null);

        router.post(
            '/leaderboard/refresh',
            {},
            {
                preserveState: true,
                preserveScroll: true,
                onStart: () => setRefreshing(true),
                onFinish: () => setRefreshing(false),
                onError: () => setTransportError('We could not reach the server. Please check your connection and try again.'),
            },
        );
    };

    return (
        <>
            <Head title="Top Affiliates" />
            <div className="page-atmosphere bg-background min-h-screen">
                <header className="bg-brand-ink relative overflow-hidden text-white">
                    <div className="bg-brand-blue/25 pointer-events-none absolute -top-24 right-[-8rem] h-80 w-80 rounded-full blur-3xl" />
                    <div className="bg-brand-green/15 pointer-events-none absolute bottom-[-10rem] left-[-6rem] h-80 w-80 rounded-full blur-3xl" />
                    <div className="relative mx-auto max-w-5xl px-5 pt-8 pb-24 sm:px-8">
                        <div className="flex items-center justify-between gap-4">
                            <div className="flex items-center gap-3">
                                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/20">
                                    <Trophy className="text-brand-green h-5 w-5" />
                                </span>
                                <div>
                                    <p className="text-[11px] font-semibold tracking-[0.28em] text-white/55 uppercase">Refersion</p>
                                    <p className="text-sm font-medium text-white/80">Affiliate program</p>
                                </div>
                            </div>
                            <span className="bg-brand-green rounded-full px-3 py-1 text-[11px] font-bold tracking-wide text-[#14532D] uppercase">
                                Top 5
                            </span>
                        </div>

                        <div className="mt-10 flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
                            <div className="max-w-2xl">
                                <h1 className="font-display text-3xl font-semibold tracking-tight text-balance sm:text-4xl">
                                    Top affiliates leaderboard
                                </h1>
                                <p className="mt-3 text-sm leading-6 text-white/70">
                                    Ranked by lifetime total commission. Only affiliates opted in with the{' '}
                                    <span className="text-brand-green font-semibold">Display in leaderboard</span> custom field are eligible.
                                </p>
                            </div>
                            <RefreshButton loading={refreshing} disabled={loading} onClick={refresh} />
                        </div>
                    </div>
                </header>

                <main className="relative mx-auto -mt-14 max-w-5xl px-4 pb-16 sm:px-6">
                    <section className="border-border bg-card overflow-hidden rounded-3xl border shadow-[0_24px_80px_-32px_rgba(34,31,78,0.35)]">
                        <div className="from-brand-green via-brand-blue to-brand-orange h-1 w-full bg-gradient-to-r" />
                        <div className="p-5 sm:p-8">
                            <div className="border-border/70 flex flex-col gap-4 border-b pb-5 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h2 className="font-display text-foreground text-lg font-semibold">Lifetime commission leaders</h2>
                                    <p className="text-muted-foreground mt-1 text-sm">
                                        {meta
                                            ? `Showing ${data.length} eligible affiliate${data.length === 1 ? '' : 's'}`
                                            : 'Loading the latest affiliate performance'}
                                    </p>
                                </div>
                                <div className="text-muted-foreground flex flex-wrap items-center gap-2 text-xs">
                                    {meta ? (
                                        <>
                                            <span
                                                className={cn(
                                                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold',
                                                    meta.cached ? 'bg-[#EAF2FF] text-[#1D4ED8]' : 'bg-[#EAF9EC] text-[#14532D]',
                                                )}
                                            >
                                                <span className={cn('h-1.5 w-1.5 rounded-full', meta.cached ? 'bg-brand-blue' : 'bg-brand-green')} />
                                                {meta.cached ? 'Cached' : 'Live'}
                                            </span>
                                            <span>Updated {formatRelativeTime(meta.generatedAt)}</span>
                                        </>
                                    ) : (
                                        <span className="animate-pulse">Loading status…</span>
                                    )}
                                </div>
                            </div>

                            {activeError && hasData ? (
                                <div className="mt-5">
                                    <StatusBanner
                                        message={activeError.message}
                                        reference={activeError.reference}
                                        onRetry={refresh}
                                        loading={refreshing}
                                    />
                                </div>
                            ) : null}

                            <div className="mt-5">
                                {loading ? <SkeletonTable /> : null}
                                {showFullError && activeError ? (
                                    <ErrorState message={activeError.message} reference={activeError.reference} onRetry={refresh} />
                                ) : null}
                                {showEmpty ? <EmptyState onRefresh={refresh} /> : null}
                                {showTable ? <LeaderboardTable entries={data} /> : null}
                            </div>

                            <footer className="border-border/70 text-muted-foreground mt-6 flex flex-col gap-2 border-t pt-4 text-[11px] sm:flex-row sm:items-center sm:justify-between">
                                <span>
                                    Eligibility: <span className="text-foreground font-mono">Display in leaderboard = TRUE</span>
                                </span>
                                {meta ? (
                                    <span>
                                        Scanned {meta.scannedAffiliates} of {meta.totalAffiliates} affiliates
                                    </span>
                                ) : null}
                            </footer>
                        </div>
                    </section>
                    <p className="sr-only" aria-live="polite">
                        {refreshing ? 'Refreshing leaderboard' : ''}
                    </p>
                </main>
            </div>
        </>
    );
}

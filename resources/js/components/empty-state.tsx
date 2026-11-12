import { Trophy } from 'lucide-react';

export function EmptyState({ onRefresh }: { onRefresh: () => void }) {
    return (
        <div className="flex flex-col items-center rounded-2xl border border-dashed border-border bg-[#FBFCF8] px-6 py-14 text-center">
            <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-cream text-[#8A5A00]">
                <Trophy className="h-7 w-7" />
            </span>
            <h3 className="mt-5 font-display text-lg font-semibold text-foreground">
                No eligible affiliates yet
            </h3>
            <p className="mt-2 max-w-md text-sm leading-6 text-muted-foreground">
                Set the <span className="font-semibold text-foreground">Display in leaderboard</span>{' '}
                custom field to{' '}
                <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs text-foreground">
                    TRUE
                </code>{' '}
                to include an affiliate.
            </p>
            <button
                type="button"
                onClick={onRefresh}
                className="mt-6 inline-flex items-center justify-center rounded-full bg-brand-ink px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-[#302B6B] focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                Refresh data
            </button>
        </div>
    );
}

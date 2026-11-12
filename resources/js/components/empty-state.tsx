import { Trophy } from 'lucide-react';

export function EmptyState({ onRefresh }: { onRefresh: () => void }) {
    return (
        <div className="border-border flex flex-col items-center rounded-2xl border border-dashed bg-[#FBFCF8] px-6 py-14 text-center">
            <span className="bg-brand-cream flex h-14 w-14 items-center justify-center rounded-2xl text-[#8A5A00]">
                <Trophy className="h-7 w-7" />
            </span>
            <h3 className="font-display text-foreground mt-5 text-lg font-semibold">No eligible affiliates yet</h3>
            <p className="text-muted-foreground mt-2 max-w-md text-sm leading-6">
                Set the <span className="text-foreground font-semibold">Display in leaderboard</span> custom field to{' '}
                <code className="bg-muted text-foreground rounded px-1.5 py-0.5 font-mono text-xs">TRUE</code> to include an affiliate.
            </p>
            <button
                type="button"
                onClick={onRefresh}
                className="bg-brand-ink focus-visible:ring-brand-blue mt-6 inline-flex items-center justify-center rounded-full px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-[#302B6B] focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                Refresh data
            </button>
        </div>
    );
}

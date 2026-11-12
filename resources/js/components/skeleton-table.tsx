export function SkeletonTable() {
    return (
        <div role="status" aria-label="Loading leaderboard" className="space-y-3">
            <span className="sr-only">Loading leaderboard</span>
            {Array.from({ length: 5 }, (_, index) => (
                <div key={index} className="border-border/60 grid grid-cols-[40px_1fr_120px] items-center gap-3 rounded-xl border px-3 py-4">
                    <div className="skeleton-shimmer h-9 w-9 rounded-full" />
                    <div className="space-y-2">
                        <div className="skeleton-shimmer h-3 w-1/3 rounded-full" />
                        <div className="skeleton-shimmer h-2.5 w-1/5 rounded-full" />
                    </div>
                    <div className="skeleton-shimmer h-3 w-20 justify-self-end rounded-full" />
                </div>
            ))}
        </div>
    );
}

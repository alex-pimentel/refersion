import { formatCurrency } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { LeaderboardEntry } from '@/types/leaderboard';
import { AffiliateAvatar } from './affiliate-avatar';
import { RankBadge } from './rank-badge';

const rowStyles: Record<number, string> = {
    1: 'bg-[#FFFBF0]',
    2: 'bg-[#F8FAFC]',
    3: 'bg-[#FDF7F2]',
};

export function LeaderboardTable({ entries }: { entries: LeaderboardEntry[] }) {
    return (
        <div className="-mx-5 overflow-x-auto sm:mx-0">
            <table className="w-full min-w-[680px] border-collapse text-left">
                <caption className="sr-only">
                    Top eligible affiliates ranked by lifetime total commission
                </caption>
                <thead>
                    <tr className="border-b border-border/80 text-[11px] font-semibold tracking-[0.18em] text-muted-foreground uppercase">
                        <th scope="col" className="py-3 pr-2 pl-5 sm:pl-0">
                            Rank
                        </th>
                        <th scope="col" className="px-2 py-3">
                            Affiliate
                        </th>
                        <th scope="col" className="px-2 py-3">
                            Email
                        </th>
                        <th scope="col" className="py-3 pr-5 pl-2 text-right sm:pr-0">
                            Lifetime commission
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {entries.map((entry) => (
                        <tr
                            key={entry.affiliateId}
                            className={cn(
                                'border-b border-border/60 last:border-0',
                                rowStyles[entry.rank],
                            )}
                        >
                            <td className="py-4 pr-2 pl-5 sm:pl-0">
                                <RankBadge rank={entry.rank} />
                            </td>
                            <td className="px-2 py-4">
                                <div className="flex items-center gap-3">
                                    <AffiliateAvatar name={entry.name} />
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-foreground">
                                            {entry.name}
                                        </p>
                                        <p className="mt-0.5 font-mono text-[11px] text-muted-foreground">
                                            #{entry.affiliateId}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td className="px-2 py-4 text-sm text-muted-foreground">
                                {entry.email || '—'}
                            </td>
                            <td className="py-4 pr-5 pl-2 text-right sm:pr-0">
                                <span className="font-display text-base font-semibold text-foreground tabular-nums">
                                    {formatCurrency(entry.commissionCents, entry.currency)}
                                </span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

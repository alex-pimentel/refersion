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
                <caption className="sr-only">Top eligible affiliates ranked by lifetime total commission</caption>
                <thead>
                    <tr className="border-border/80 text-muted-foreground border-b text-[11px] font-semibold tracking-[0.18em] uppercase">
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
                        <tr key={entry.affiliateId} className={cn('border-border/60 border-b last:border-0', rowStyles[entry.rank])}>
                            <td className="py-4 pr-2 pl-5 sm:pl-0">
                                <RankBadge rank={entry.rank} />
                            </td>
                            <td className="px-2 py-4">
                                <div className="flex items-center gap-3">
                                    <AffiliateAvatar name={entry.name} />
                                    <div className="min-w-0">
                                        <p className="text-foreground truncate text-sm font-semibold">{entry.name}</p>
                                        <p className="text-muted-foreground mt-0.5 font-mono text-[11px]">#{entry.affiliateId}</p>
                                    </div>
                                </div>
                            </td>
                            <td className="text-muted-foreground px-2 py-4 text-sm">{entry.email || '—'}</td>
                            <td className="py-4 pr-5 pl-2 text-right sm:pr-0">
                                <span className="font-display text-foreground text-base font-semibold tabular-nums">
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

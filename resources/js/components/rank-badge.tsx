import { cn } from '@/lib/utils';

const styles: Record<number, string> = {
    1: 'bg-[#FFF5E2] text-[#8A5A00] ring-[#EACB8A]',
    2: 'bg-[#F1F5F9] text-[#475569] ring-[#CBD5E1]',
    3: 'bg-[#FDF3EC] text-[#9A3412] ring-[#F2C6A8]',
};

const neutralStyle = 'bg-[#EDF2FF] text-[#221F4E] ring-[#D4E2F2]';

export function RankBadge({ rank }: { rank: number }) {
    return (
        <span
            aria-label={`Rank ${rank}`}
            className={cn(
                'inline-flex h-9 w-9 items-center justify-center rounded-full text-sm font-semibold ring-1 ring-inset',
                styles[rank] ?? neutralStyle,
            )}
        >
            {rank}
        </span>
    );
}

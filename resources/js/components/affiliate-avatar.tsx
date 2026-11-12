import { cn } from '@/lib/utils';

const palettes = [
    { background: '#221F4E', foreground: '#FFFFFF' },
    { background: '#3A86FF', foreground: '#FFFFFF' },
    { background: '#54DE62', foreground: '#14532D' },
    { background: '#FF6B35', foreground: '#FFFFFF' },
    { background: '#5C3C74', foreground: '#FFFFFF' },
    { background: '#1D4D42', foreground: '#FFFFFF' },
];

function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
        return '?';
    }

    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }

    return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
}

function paletteFor(name: string) {
    const hash = Array.from(name).reduce((sum, character) => sum + character.charCodeAt(0), 0);

    return palettes[hash % palettes.length];
}

export function AffiliateAvatar({ name, className }: { name: string; className?: string }) {
    const palette = paletteFor(name);

    return (
        <span
            aria-hidden="true"
            className={cn(
                'flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-semibold tracking-wide ring-1 ring-black/5',
                className,
            )}
            style={{ backgroundColor: palette.background, color: palette.foreground }}
        >
            {initials(name)}
        </span>
    );
}

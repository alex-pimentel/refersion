import { AlertTriangle } from 'lucide-react';

export function ErrorState({
    message,
    reference,
    onRetry,
}: {
    message: string;
    reference?: string | null;
    onRetry: () => void;
}) {
    return (
        <div
            role="alert"
            className="flex flex-col items-center rounded-2xl border border-[#F2C6C2] bg-[#FEF3F2] px-6 py-14 text-center"
        >
            <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-[#B42318] ring-1 ring-[#F2C6C2]">
                <AlertTriangle className="h-7 w-7" />
            </span>
            <h3 className="mt-5 font-display text-lg font-semibold text-[#7A271A]">
                We couldn't load the leaderboard
            </h3>
            <p className="mt-2 max-w-md text-sm leading-6 text-[#B42318]">{message}</p>
            {reference ? (
                <p className="mt-3 font-mono text-[11px] text-[#B42318]/80">Reference: {reference}</p>
            ) : null}
            <button
                type="button"
                onClick={onRetry}
                className="mt-6 inline-flex items-center justify-center rounded-full bg-[#7A271A] px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-[#5F1D14] focus-visible:ring-2 focus-visible:ring-[#B42318] focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                Try again
            </button>
        </div>
    );
}

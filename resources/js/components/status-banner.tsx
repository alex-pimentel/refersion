import { AlertTriangle } from 'lucide-react';

export function StatusBanner({
    message,
    reference,
    onRetry,
    loading,
}: {
    message: string;
    reference?: string | null;
    onRetry: () => void;
    loading?: boolean;
}) {
    return (
        <div
            role="alert"
            className="flex flex-col gap-3 rounded-2xl border border-[#F2C6C2] bg-[#FEF3F2] px-4 py-3 text-sm text-[#B42318] sm:flex-row sm:items-center sm:justify-between"
        >
            <div className="flex items-start gap-3">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                <div>
                    <p className="font-medium">{message}</p>
                    {reference ? <p className="mt-1 font-mono text-[11px] text-[#B42318]/75">Reference: {reference}</p> : null}
                </div>
            </div>
            <button
                type="button"
                onClick={onRetry}
                disabled={loading}
                className="self-start rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-[#B42318] ring-1 ring-[#F2C6C2] transition hover:bg-[#FFF5F5] disabled:opacity-60 sm:self-auto"
            >
                {loading ? 'Retrying…' : 'Try again'}
            </button>
        </div>
    );
}

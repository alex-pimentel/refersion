const currencyFormatter = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const relativeTimeFormatter = new Intl.RelativeTimeFormat('en', {
    numeric: 'auto',
});

export function formatCurrency(cents: number, currency = 'USD'): string {
    if (currency === 'USD') {
        return currencyFormatter.format(cents / 100);
    }

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(cents / 100);
}

export function formatRelativeTime(iso: string, now = new Date()): string {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return 'just now';
    }

    const diffSeconds = Math.round((date.getTime() - now.getTime()) / 1000);
    const absoluteSeconds = Math.abs(diffSeconds);

    if (absoluteSeconds < 60) {
        return relativeTimeFormatter.format(diffSeconds, 'second');
    }

    const diffMinutes = Math.round(diffSeconds / 60);

    if (Math.abs(diffMinutes) < 60) {
        return relativeTimeFormatter.format(diffMinutes, 'minute');
    }

    const diffHours = Math.round(diffMinutes / 60);

    if (Math.abs(diffHours) < 24) {
        return relativeTimeFormatter.format(diffHours, 'hour');
    }

    return relativeTimeFormatter.format(Math.round(diffHours / 24), 'day');
}

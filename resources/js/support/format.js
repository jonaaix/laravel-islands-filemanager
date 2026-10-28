const locale = () => document.documentElement.lang || 'en';

export function formatDate(iso, options = {}) {
    if (!iso) {
        return '';
    }

    return new Intl.DateTimeFormat(locale(), { dateStyle: 'medium', hourCycle: 'h23', ...options }).format(new Date(iso));
}

export function formatRelative(iso) {
    if (!iso) {
        return '';
    }

    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const units = [
        ['year', 31536000],
        ['month', 2592000],
        ['week', 604800],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];
    const rtf = new Intl.RelativeTimeFormat(locale(), { numeric: 'auto' });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return rtf.format(Math.round(seconds / size), unit);
        }
    }

    return rtf.format(seconds, 'second');
}

export function formatFileSize(bytes) {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${new Intl.NumberFormat(locale(), { maximumFractionDigits: 1 }).format(value)} ${units[unit]}`;
}

export function formatCurrency(amount, currency = 'EUR') {
    return new Intl.NumberFormat(locale(), { style: 'currency', currency, maximumFractionDigits: amount % 1 === 0 ? 0 : 2 }).format(amount);
}

export function formatNumber(value) {
    return new Intl.NumberFormat(locale()).format(value);
}

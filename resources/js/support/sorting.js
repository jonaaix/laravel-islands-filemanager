const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

const VALUES = {
    name: (item) => item.name,
    owner: (item) => item.owner ?? '',
    modified: (item) => item.updatedAt ?? '',
    added: (item) => item.createdAt ?? '',
    size: (item) => (item.type === 'folder' ? -1 : item.size),
    kind: (item) => (item.type === 'folder' ? '' : item.extension),
};

function compare(a, b, sortBy) {
    const left = VALUES[sortBy](a);
    const right = VALUES[sortBy](b);
    const result = typeof left === 'number' ? left - right : collator.compare(left, right);

    return result || collator.compare(a.name, b.name);
}

export function sortItems(items, sortBy, direction) {
    const factor = direction === 'desc' ? -1 : 1;

    return [...items].sort((a, b) => factor * compare(a, b, sortBy));
}

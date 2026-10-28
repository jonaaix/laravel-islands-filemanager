export function highlightParts(text, query) {
    const terms = query.toLowerCase().split(/\s+/).filter(Boolean);
    const haystack = text.toLowerCase();
    const parts = [];
    let cursor = 0;

    while (terms.length) {
        const next = terms
            .map((term) => ({ term, index: haystack.indexOf(term, cursor) }))
            .filter((hit) => hit.index !== -1)
            .sort((a, b) => a.index - b.index || b.term.length - a.term.length)[0];

        if (!next) {
            break;
        }

        if (next.index > cursor) {
            parts.push({ text: text.slice(cursor, next.index), match: false });
        }

        parts.push({ text: text.slice(next.index, next.index + next.term.length), match: true });
        cursor = next.index + next.term.length;
    }

    if (cursor < text.length) {
        parts.push({ text: text.slice(cursor), match: false });
    }

    return parts;
}

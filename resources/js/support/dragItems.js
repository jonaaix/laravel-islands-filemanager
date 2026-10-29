export const ITEMS_MIME = 'application/x-files-items';

export const itemKey = (item) => `${item.type}-${item.id}`;

export function startItemsDrag(event, items) {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData(ITEMS_MIME, JSON.stringify(toPayload(items)));

    const preview = dragPreview(items);
    document.body.appendChild(preview);
    event.dataTransfer.setDragImage(preview, 14, 14);
    setTimeout(() => preview.remove(), 0);
}

function dragPreview(items) {
    const preview = document.createElement('div');
    preview.className = 'fixed -left-[9999px] top-0 flex max-w-72 items-center gap-2 rounded-md bg-primary-600 px-2.5 py-1 text-sm font-medium text-white shadow-lg';

    if (items.length > 1) {
        preview.style.boxShadow = '4px 4px 0 -1px var(--color-primary-300), 0 10px 15px -3px rgb(0 0 0 / 0.1)';
    }

    const name = document.createElement('span');
    name.className = 'truncate';
    name.textContent = items[0]?.name ?? '';
    preview.appendChild(name);

    if (items.length > 1) {
        const count = document.createElement('span');
        count.className = 'shrink-0 rounded-full bg-white px-1.5 text-xs font-semibold tabular-nums text-primary-700';
        count.textContent = String(items.length);
        preview.appendChild(count);
    }

    return preview;
}

export function readDraggedItems(event) {
    const raw = event.dataTransfer?.getData(ITEMS_MIME);

    return raw ? JSON.parse(raw) : null;
}

export function carriesItems(event) {
    return Array.from(event.dataTransfer?.types ?? []).includes(ITEMS_MIME);
}

export function carriesFiles(event) {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files');
}

export function toPayload(items) {
    return {
        folders: items.filter((item) => item.type === 'folder').map((item) => item.id),
        files: items.filter((item) => item.type === 'file').map((item) => item.id),
    };
}

export function fileIcon(item) {
    if (item.type === 'folder') {
        return 'o-folder';
    }

    if (item.isImage) {
        return 'o-photo';
    }

    return item.isPdf ? 'o-document-text' : 'o-document';
}

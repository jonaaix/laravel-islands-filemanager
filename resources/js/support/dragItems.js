export const ITEMS_MIME = 'application/x-files-items';

export const itemKey = (item) => `${item.type}-${item.id}`;

const PREVIEW_ROWS = 5;

export function startItemsDrag(event, items) {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData(ITEMS_MIME, JSON.stringify(toPayload(items)));

    const preview = dragPreview(items);
    document.body.appendChild(preview);
    event.dataTransfer.setDragImage(preview, items.length > 1 ? 11 : 14, items.length > 1 ? 11 : 14);
    setTimeout(() => preview.remove(), 0);
}

function dragPreview(items) {
    const preview = document.createElement('div');
    preview.className = items.length > 1 ? 'fixed -left-[9999px] top-0 flex flex-col items-start gap-1 pl-3 pt-3' : 'fixed -left-[9999px] top-0 flex';

    items.slice(0, PREVIEW_ROWS).forEach((item) => {
        const row = document.createElement('span');
        row.className = 'max-w-72 truncate rounded-md bg-primary-600 px-2.5 py-1 text-sm font-medium text-white shadow-md';
        row.textContent = item.name ?? '';
        preview.appendChild(row);
    });

    if (items.length > 1) {
        const count = document.createElement('span');
        count.className = 'absolute left-0 top-0 flex h-[22px] min-w-[22px] items-center justify-center rounded-full bg-gray-900 px-1.5 text-xs font-semibold tabular-nums text-white ring-2 ring-white';
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

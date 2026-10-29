export const ITEMS_MIME = 'application/x-files-items';

export const itemKey = (item) => `${item.type}-${item.id}`;

const PREVIEW_ROWS = 5;

export function startItemsDrag(event, items) {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData(ITEMS_MIME, JSON.stringify(toPayload(items)));

    const preview = dragPreview(items);
    document.body.appendChild(preview);
    event.dataTransfer.setDragImage(preview, items.length > 1 ? 10 : 12, items.length > 1 ? 10 : 12);
    setTimeout(() => preview.remove(), 0);
}

function dragPreview(items) {
    const preview = document.createElement('div');
    preview.className = items.length > 1 ? 'fixed -left-[9999px] top-0 pl-3 pt-3' : 'fixed -left-[9999px] top-0';

    const list = document.createElement('div');
    list.className = 'flex max-w-64 flex-col rounded-md bg-primary-600 py-0.5 text-[13px] font-medium leading-5 text-white shadow-md';
    preview.appendChild(list);

    items.slice(0, PREVIEW_ROWS).forEach((item) => {
        const row = document.createElement('span');
        row.className = 'truncate px-2';
        row.textContent = item.name ?? '';
        list.appendChild(row);
    });

    if (items.length > 1) {
        const count = document.createElement('span');
        count.className = 'absolute left-0 top-0 flex h-5 min-w-5 items-center justify-center rounded-full bg-gray-900 px-1 text-[11px] font-semibold tabular-nums text-white ring-2 ring-white';
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

export const ITEMS_MIME = 'application/x-files-items';

export const itemKey = (item) => `${item.type}-${item.id}`;

export function startItemsDrag(event, items) {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData(ITEMS_MIME, JSON.stringify(toPayload(items)));
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

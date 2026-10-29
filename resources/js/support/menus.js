import { KEYS } from './shortcuts.js';

export function createMenus(ctx) {
    const { t } = ctx;

    function pasteEntries(folderId, volume) {
        const count = ctx.clipboard.value?.items.length ?? 0;

        if (!count) {
            return [];
        }

        return [
            { label: count === 1 ? t('Paste 1 item') : t('Paste :count items', { count }), icon: 'o-clipboard-document', shortcut: KEYS.paste, disabled: !ctx.mayPasteInto(volume), action: () => ctx.paste(folderId, volume) },
            { label: count === 1 ? t('Move 1 item here') : t('Move :count items here', { count }), icon: 'o-arrows-right-left', shortcut: KEYS.moveHere, disabled: !ctx.mayPasteInto(volume, { moving: true }), action: () => ctx.paste(folderId, volume, { moving: true }) },
        ];
    }

    function withPasteGroup(folderId, volume, entries) {
        const pasting = pasteEntries(folderId, volume);

        return pasting.length ? [...pasting, { divider: true }, ...entries] : entries;
    }

    function itemEntries(chosen) {
        const single = chosen.length === 1 ? chosen[0] : null;
        const files = chosen.filter((item) => item.type === 'file');
        const entries = [];

        if (single?.type === 'folder') {
            entries.push({ label: t('Open'), icon: 'o-folder-open', shortcut: KEYS.open, action: () => ctx.openItem(single) });
            entries.push({ label: t('Open in new tab'), icon: 'o-square-2-stack', action: () => ctx.openTab({ volume: ctx.currentVolume.value, folderId: single.id }) });
        } else if (single) {
            entries.push({ label: t('Preview'), icon: 'o-eye', shortcut: KEYS.quickLook, action: () => ctx.openItem(single) });
            entries.push({ label: t('Download'), icon: 'o-arrow-down-tray', action: () => ctx.downloadEach([single]) });
        }

        if (!single || single.type === 'folder') {
            entries.push({ label: t('Download as ZIP'), icon: 'o-archive-box-arrow-down', action: () => ctx.downloadZip(chosen) });
        }

        if (!single && files.length) {
            entries.push({ label: t('Download :count files individually', { count: files.length }), icon: 'o-arrow-down-tray', action: () => ctx.downloadEach(files) });
        }

        entries.push({ divider: true });
        entries.push({ label: t('Copy'), icon: 'o-document-duplicate', shortcut: KEYS.copy, action: () => ctx.copyToClipboard(chosen) });

        const finish = (list) => (single?.type === 'folder' ? withPasteGroup(single.id, ctx.currentVolume.value, list) : list);

        if (!ctx.canWrite.value) {
            return finish(entries);
        }

        entries.push({ divider: true });

        if (single) {
            entries.push({ label: t('Rename'), icon: 'o-pencil-square', shortcut: KEYS.rename, action: () => ctx.startRename(single) });
        }

        entries.push({ label: t('Move to…'), icon: 'o-arrows-right-left', action: () => ctx.startMove(chosen) });
        entries.push({ label: chosen.length === 1 ? t('New folder with 1 item') : t('New folder with :count items', { count: chosen.length }), icon: 'o-folder-plus', shortcut: KEYS.newFolderWithItems, action: () => ctx.createFolderWithItems(chosen) });
        entries.push({ divider: true });
        entries.push({ label: t('Move to trash'), icon: 'o-trash', tone: 'danger', shortcut: KEYS.remove, action: () => ctx.remove(chosen) });

        return finish(entries);
    }

    function backgroundEntries() {
        const view = ctx.view.value;
        const sortEntry = (column, label) => ({ label, checked: view.sortBy === column, action: () => ctx.sortBy(column) });

        const writing = ctx.canWrite.value
            ? [
                { label: t('New folder'), icon: 'o-folder-plus', shortcut: KEYS.newFolder, action: ctx.createFolder },
                { label: t('Upload files…'), icon: 'o-arrow-up-tray', shortcut: KEYS.upload, action: () => ctx.chooseUpload() },
                { label: t('Upload folder…'), icon: 'o-folder-arrow-down', action: () => ctx.chooseFolderUpload() },
            ]
            : [];

        return withPasteGroup(ctx.contents.value.folderId, ctx.currentVolume.value, [
            ...writing,
            { label: t('New tab'), icon: 'o-square-2-stack', shortcut: KEYS.newTab, action: () => ctx.openTab() },
            { divider: true },
            { label: t('View as list'), checked: view.mode === 'list', shortcut: KEYS.list, action: () => ctx.setMode('list') },
            { label: t('View as thumbnails'), checked: view.mode === 'grid', shortcut: KEYS.grid, action: () => ctx.setMode('grid') },
            { divider: true },
            sortEntry('name', t('Sort by name')),
            sortEntry('owner', t('Sort by owner')),
            sortEntry('modified', t('Sort by date modified')),
            sortEntry('added', t('Sort by date added')),
            sortEntry('size', t('Sort by size')),
        ]);
    }

    function treeEntries(folder) {
        const item = { type: 'folder', id: folder.id, name: folder.name };
        const volume = ctx.volumeOf(folder.id);

        return withPasteGroup(folder.id, volume, [
            { label: t('Open'), icon: 'o-folder-open', action: () => ctx.openFolder(folder.id) },
            { label: t('Open in new tab'), icon: 'o-square-2-stack', action: () => ctx.openTab({ volume, folderId: folder.id }) },
            { label: t('Download as ZIP'), icon: 'o-archive-box-arrow-down', action: () => ctx.downloadZip([item]) },
            { divider: true },
            { label: t('Copy'), icon: 'o-document-duplicate', action: () => ctx.copyToClipboard([item]) },
            ...(ctx.canWriteTo(volume)
                ? [
                    { divider: true },
                    { label: t('Rename'), icon: 'o-pencil-square', action: () => ctx.startTreeRename(folder.id) },
                    { label: t('Move to…'), icon: 'o-arrows-right-left', action: () => ctx.startMove([item]) },
                    { divider: true },
                    { label: t('Move to trash'), icon: 'o-trash', tone: 'danger', action: () => ctx.remove([item]) },
                ]
                : []),
        ]);
    }

    return { itemEntries, backgroundEntries, treeEntries };
}

const isHidden = (name) => name.startsWith('.');

function readEntries(reader) {
    return new Promise((resolve, reject) => reader.readEntries(resolve, reject));
}

function fileOf(entry) {
    return new Promise((resolve, reject) => entry.file(resolve, reject));
}

async function walk(entry, directory, collected) {
    if (isHidden(entry.name)) {
        return;
    }

    if (entry.isFile) {
        collected.push({ file: await fileOf(entry), path: directory });

        return;
    }

    const path = directory ? `${directory}/${entry.name}` : entry.name;
    const reader = entry.createReader();

    for (let batch = await readEntries(reader); batch.length; batch = await readEntries(reader)) {
        for (const child of batch) {
            await walk(child, path, collected);
        }
    }
}

// The entries must be taken while the drop event is still running; the browser empties the list afterwards.
export function takeDroppedEntries(dataTransfer) {
    return Array.from(dataTransfer.items ?? [])
        .map((item) => item.webkitGetAsEntry?.())
        .filter(Boolean);
}

export async function collectDroppedFiles(entries, fallbackFiles) {
    if (!entries.length) {
        return Array.from(fallbackFiles).map((file) => ({ file, path: '' }));
    }

    const collected = [];

    for (const entry of entries) {
        await walk(entry, '', collected);
    }

    return collected;
}

export function fromFileInput(files) {
    return Array.from(files)
        .filter((file) => !isHidden(file.name))
        .map((file) => ({ file, path: (file.webkitRelativePath || '').split('/').slice(0, -1).join('/') }));
}

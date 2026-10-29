import { ref } from 'vue';
import { sendJson } from '@aaix/laravel-islands-datagrid/vue';

const DEPTH = 20;

export function useUndo({ onUndo, onFailure }) {
    const steps = [];
    const notice = ref(null);
    let sequence = 0;

    function offer(message, undo) {
        steps.push(undo);
        steps.splice(0, Math.max(0, steps.length - DEPTH));
        notice.value = { id: ++sequence, message };
    }

    async function undoLast() {
        const undo = steps.pop();
        notice.value = null;

        if (!undo) {
            return;
        }

        try {
            await undo();
            await onUndo();
        } catch (error) {
            onFailure(error);
        }
    }

    return { notice, offer, undoLast };
}

export async function moveBack(moveUrl, origins) {
    const groups = new Map();

    origins.forEach(({ type, id, volume, folderId }) => {
        const key = `${volume}:${folderId ?? ''}`;
        const group = groups.get(key) ?? { volume, folderId, folders: [], files: [] };
        group[type === 'folder' ? 'folders' : 'files'].push(id);
        groups.set(key, group);
    });

    for (const group of groups.values()) {
        await sendJson(moveUrl, 'PUT', { folders: group.folders, files: group.files, target_volume: group.volume, target_id: group.folderId });
    }
}

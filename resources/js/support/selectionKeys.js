import { itemKey } from './dragItems.js';

const TYPE_AHEAD_PAUSE = 800;

export function useSelectionKeys(ctx) {
    let typed = '';
    let typedTimer = null;

    function scrollToKey(key) {
        ctx.pane.value?.querySelector(`[data-item-key="${key}"]`)?.scrollIntoView({ block: 'nearest' });
    }

    function gridColumns() {
        const tiles = Array.from(ctx.pane.value?.querySelectorAll('[data-item-key]') ?? []);

        return Math.max(1, tiles.filter((tile) => tile.offsetTop === tiles[0]?.offsetTop).length);
    }

    function moveSelection(key, extend) {
        const keys = ctx.items.value.map(itemKey);

        if (!keys.length) {
            return;
        }

        const vertical = key === 'ArrowUp' || key === 'ArrowDown';
        const mode = ctx.view.value.mode;

        if (mode === 'list' && !vertical) {
            expandWithArrow(key);

            return;
        }

        const direction = key === 'ArrowUp' || key === 'ArrowLeft' ? -1 : 1;
        const step = direction * (mode === 'grid' && vertical ? gridColumns() : 1);
        const current = keys.indexOf([...ctx.selected.value].at(-1));
        const next = current === -1 ? (step > 0 ? 0 : keys.length - 1) : Math.min(keys.length - 1, Math.max(0, current + step));
        const nextKey = keys[next];

        if (extend && ctx.anchorKey.value) {
            ctx.selectRange(ctx.anchorKey.value, nextKey);
            ctx.selected.value = new Set([...[...ctx.selected.value].filter((selectedKey) => selectedKey !== nextKey), nextKey]);
        } else {
            ctx.select([nextKey], nextKey);
        }

        scrollToKey(nextKey);
    }

    function expandWithArrow(key) {
        const current = ctx.selectedItems.value.length === 1 ? ctx.selectedItems.value[0] : null;

        if (!current) {
            return;
        }

        if (key === 'ArrowRight' && current.type === 'folder') {
            ctx.expand(current);
        } else if (key === 'ArrowLeft' && current.type === 'folder' && ctx.expanded.value.has(current.id)) {
            ctx.collapse(current);
        } else if (key === 'ArrowLeft') {
            const parentKey = `folder-${ctx.rowByKey.value.get(itemKey(current))?.parentId}`;

            if (ctx.rowByKey.value.has(parentKey)) {
                ctx.select([parentKey], parentKey);
                scrollToKey(parentKey);
            }
        }
    }

    function jumpToName(character) {
        clearTimeout(typedTimer);
        typed += character.toLowerCase();
        typedTimer = setTimeout(() => (typed = ''), TYPE_AHEAD_PAUSE);

        const match = ctx.items.value.find((item) => item.name.toLowerCase().startsWith(typed));

        if (match) {
            ctx.select([itemKey(match)], itemKey(match));
            scrollToKey(itemKey(match));
        }
    }

    return { scrollToKey, moveSelection, jumpToName };
}

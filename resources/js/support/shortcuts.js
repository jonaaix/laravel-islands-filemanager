export const isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

export const MOD = isMac ? '⌘' : 'Ctrl+';
export const ALT = isMac ? '⌥' : 'Alt+';

export const KEYS = {
    open: `${MOD}↓`,
    enclosing: `${MOD}↑`,
    quickLook: 'Space',
    rename: 'Enter',
    remove: isMac ? '⌘⌫' : 'Delete',
    undo: `${MOD}Z`,
    selectAll: `${MOD}A`,
    newFolder: isMac ? '⌘⇧N' : 'Ctrl+Shift+N',
    newFolderFallback: `${MOD}${ALT}N`,
    newFolderWithItems: isMac ? '⌃⌘N' : 'Ctrl+Alt+Shift+N',
    upload: `${MOD}${ALT}U`,
    newTab: `${ALT}T`,
    closeTab: `${ALT}W`,
    copy: `${MOD}C`,
    paste: `${MOD}V`,
    moveHere: `${MOD}${ALT}V`,
    list: `${ALT}1`,
    grid: `${ALT}2`,
    search: '/',
    help: '?',
};

export function isTyping(event) {
    const target = event.target;

    return target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
}

export const hasMod = (event) => (isMac ? event.metaKey : event.ctrlKey);

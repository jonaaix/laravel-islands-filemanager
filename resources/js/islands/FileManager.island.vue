<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useIsland, useTranslations, useViewWidth } from '@aaix/laravel-islands/vue';
import { ConfirmHost, Icon, IconButton, OptionStrip, Skeleton, Slider, ToastHost, provideConfirm, provideIcons, provideToasts, useConfirm, useToast } from '@aaix/laravel-islands/vue/helpers';
import { IconModeCards, IconModeList, httpClient, sendJson } from '@aaix/laravel-islands-datagrid/vue';
import { formatFileSize } from '../support/format.js';
import { firstError, sendForm } from '../support/http.js';
import ContextMenu from '../components/ContextMenu.vue';
import FolderTree from '../components/FolderTree.vue';
import InfoPanel from '../components/InfoPanel.vue';
import ItemGrid from '../components/ItemGrid.vue';
import ItemList from '../components/ItemList.vue';
import MoveModal from '../components/MoveModal.vue';
import PaneResizer from '../components/PaneResizer.vue';
import QuickLook from '../components/QuickLook.vue';
import ShortcutsHelp from '../components/ShortcutsHelp.vue';
import TabBar from '../components/TabBar.vue';
import { itemCount } from '../support/counts.js';
import { carriesFiles, carriesItems, itemKey, readDraggedItems, startItemsDrag, toPayload } from '../support/dragItems.js';
import { collectDroppedFiles, fromFileInput, takeDroppedEntries } from '../support/droppedFiles.js';
import { KEYS, hasMod, isMac, isTyping } from '../support/shortcuts.js';
import { sortItems } from '../support/sorting.js';

// The Files window fills the whole content area, however wide the screen.
const EDGE_TO_EDGE = 10000;
const MIN_CONTENT_WIDTH = 560;

const { props } = useIsland();
const { t } = useTranslations();
const { root, rootStyle } = useViewWidth({ baseWidth: EDGE_TO_EDGE });

provideIcons(props.icons);
provideToasts();
provideConfirm();

const toast = useToast();
const confirm = useConfirm();
const endpoints = props.endpoints;
const withId = (url, id) => url.replace('__ID__', id);

const contents = ref(props.initial.contents);
const volumes = ref(props.initial.volumes);
const allFolders = computed(() => volumes.value.flatMap((volume) => volume.folders.map((folder) => ({ ...folder, volume: volume.key }))));
const currentVolume = computed(() => contents.value.volume);
const volumeOf = (folderId) => (folderId ? (allFolders.value.find((folder) => folder.id === folderId)?.volume ?? currentVolume.value) : currentVolume.value);
const volumeName = (key) => volumes.value.find((volume) => volume.key === key)?.name ?? key;
const canWriteTo = (key) => volumes.value.find((volume) => volume.key === key)?.canWrite ?? false;
const canWrite = computed(() => contents.value.canWrite);
const clipboard = ref(null);

// ----- Tabs -----

const startLocation = { volume: props.initial.contents.volume, folderId: props.initial.contents.folderId };
const savedTabs = props.preferences.tabs;
const tabs = ref(savedTabs.length ? savedTabs.map((tab) => ({ ...tab })) : [startLocation]);
const activeTab = ref(savedTabs.length ? props.preferences.activeTab : 0);
let tabsTimer = null;

tabs.value[activeTab.value] = { ...startLocation };

const tabLabel = (tab) => (tab.folderId ? (allFolders.value.find((folder) => folder.id === tab.folderId)?.name ?? '…') : volumeName(tab.volume));

function saveTabs() {
    clearTimeout(tabsTimer);
    tabsTimer = setTimeout(() => {
        sendJson(endpoints.preferences, 'PUT', {
            tabs: tabs.value.map((tab) => ({ volume: tab.volume, folder_id: tab.folderId })),
            active_tab: activeTab.value,
        }).catch(() => null);
    }, 400);
}

function rememberActiveTab() {
    const current = tabs.value[activeTab.value];

    if (current && (current.volume !== currentVolume.value || current.folderId !== contents.value.folderId)) {
        tabs.value[activeTab.value] = { volume: currentVolume.value, folderId: contents.value.folderId };
        saveTabs();
    }
}

function openTab(location = { volume: currentVolume.value, folderId: contents.value.folderId }) {
    tabs.value.push({ ...location });
    activeTab.value = tabs.value.length - 1;
    saveTabs();
    openFolder(location.folderId, null, location.volume);
}

function switchTab(index) {
    if (index === activeTab.value) {
        return;
    }

    activeTab.value = index;
    saveTabs();
    openFolder(tabs.value[index].folderId, null, tabs.value[index].volume);
}

function closeTab(index) {
    if (tabs.value.length === 1) {
        return;
    }

    const wasActive = index === activeTab.value;
    tabs.value.splice(index, 1);

    if (wasActive) {
        activeTab.value = Math.min(index, tabs.value.length - 1);
        openFolder(tabs.value[activeTab.value].folderId, null, tabs.value[activeTab.value].volume);
    } else if (index < activeTab.value) {
        activeTab.value--;
    }

    saveTabs();
}

function dropOnTab(event, index) {
    const tab = tabs.value[index];
    dropOn(event, tab.folderId, tab.volume);
}
const view = ref({ ...props.initial.contents.view });
const loading = ref(false);
const uploading = ref(false);
const uploadProgress = ref(null);
const folderInput = ref(null);
const query = ref('');
const searchField = ref(null);
const contentPane = ref(null);
const fileInput = ref(null);

const selected = ref(new Set());
const anchorKey = ref(null);
const renamingKey = ref(null);
const renameError = ref('');
const quickLookOpen = ref(false);
const helpOpen = ref(false);
const menu = ref(null);
const moving = ref([]);
const moveError = ref('');
const busy = ref(false);
const filesOver = ref(false);
const windowHeight = ref('calc(100vh - 3.5rem)');
const limits = props.paneWidths;
const infoWidth = ref(props.preferences.infoWidth);
const treeWidth = ref(props.preferences.treeWidth);
const windowWidth = ref(window.innerWidth);
const roomForPanes = computed(() => windowWidth.value - MIN_CONTENT_WIDTH);
const clampWidth = (width, min, max) => Math.max(min, Math.min(max, width));
const shownTreeWidth = computed(() => clampWidth(treeWidth.value, limits.tree.min, Math.min(limits.tree.max, roomForPanes.value - limits.info.min)));
const infoMax = computed(() => clampWidth(roomForPanes.value - shownTreeWidth.value, limits.info.min, limits.info.max));
const shownInfoWidth = computed(() => Math.min(infoWidth.value, infoMax.value));
const treeMax = computed(() => clampWidth(roomForPanes.value - shownInfoWidth.value, limits.tree.min, limits.tree.max));
const treeRenamingId = ref(null);
const infoVersion = ref(0);
const expanded = ref(new Set());
const children = ref(new Map());
let uploadTargetId;
let filesDragDepth = 0;

// ----- Items and selection -----

function ordered(level, filterByName = true) {
    const needle = query.value.trim().toLowerCase();
    const matches = (item) => !filterByName || !needle || item.name.toLowerCase().includes(needle);
    const { sortBy: column, sortDirection } = view.value;

    return [...sortItems(level.folders.filter(matches), column, sortDirection), ...sortItems(level.files.filter(matches), column, sortDirection)];
}

const searchResults = ref(null);
const searching = computed(() => query.value.trim() !== '' && searchResults.value !== null);
let searchTimer = null;
let searchRequest = 0;

async function runSearch() {
    const term = query.value.trim();
    const request = ++searchRequest;

    if (!term) {
        searchResults.value = null;

        return;
    }

    try {
        const { data } = await httpClient.get(endpoints.search, { params: { q: term, volume: currentVolume.value, folder: contents.value.folderId } });

        if (request === searchRequest) {
            searchResults.value = data.data;
        }
    } catch (error) {
        toast.danger(firstError(error, t('Could not search')));
    }
}

watch(query, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(runSearch, 200);
});

const rows = computed(() => {
    if (searching.value) {
        const toRow = (item, group, index) => ({ item, depth: 0, parentId: item.parentId, path: item.path, group, groupStart: index === 0 });
        const byName = ordered(searchResults.value, false).map((item, index) => toRow(item, 'names', index));
        const byContent = ordered({ folders: [], files: searchResults.value.contentFiles }, false).map((item, index) => toRow(item, 'content', index));

        return [...byName, ...byContent];
    }

    const result = [];

    const walk = (level, depth, parentId, path) => {
        for (const item of ordered(level)) {
            result.push({ item, depth, parentId, path });

            if (view.value.mode === 'list' && item.type === 'folder' && expanded.value.has(item.id) && children.value.has(item.id)) {
                walk(children.value.get(item.id), depth + 1, item.id, [...path, item.name]);
            }
        }
    };

    walk(contents.value, 0, contents.value.folderId, contents.value.path.map((step) => step.name));

    return result;
});

const items = computed(() => rows.value.map((row) => row.item));
const rowByKey = computed(() => new Map(rows.value.map((row) => [itemKey(row.item), row])));

const PREVIEW_PREFETCH_DELAY = 150;
const PREVIEW_PREFETCH_MAX_BYTES = 30 * 1024 * 1024;
const prefetchedPreviews = new Set();
let previewTimer = null;

function prefetchPreview(file) {
    if (!file || (!file.isImage && !file.isPdf) || file.size > PREVIEW_PREFETCH_MAX_BYTES || prefetchedPreviews.has(file.url)) {
        return;
    }

    prefetchedPreviews.add(file.url);

    if (file.isImage) {
        new Image().src = file.url;
    } else {
        fetch(file.url, { credentials: 'same-origin' }).catch(() => prefetchedPreviews.delete(file.url));
    }
}

const selectedItems = computed(() => items.value.filter((item) => selected.value.has(itemKey(item))));
const selectedSize = computed(() => selectedItems.value.reduce((sum, item) => sum + (item.type === 'file' ? item.size : 0), 0));
const singleFile = computed(() => (selectedItems.value.length === 1 && selectedItems.value[0].type === 'file' ? selectedItems.value[0] : null));

watch(() => [contents.value.volume, contents.value.folderId], rememberActiveTab);

watch(singleFile, (file) => {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(() => prefetchPreview(file), PREVIEW_PREFETCH_DELAY);
});
const folderName = computed(() => contents.value.path.at(-1)?.name ?? volumeName(currentVolume.value));
const location = computed(() => {
    const row = selectedItems.value.length === 1 ? rowByKey.value.get(itemKey(selectedItems.value[0])) : null;

    return [volumeName(currentVolume.value), ...(row ? row.path : contents.value.path.map((step) => step.name))].join(' › ');
});
const totalCount = computed(() => contents.value.folders.length + contents.value.files.length);

function select(keys, anchor = null) {
    selected.value = new Set(keys);
    anchorKey.value = anchor;
    syncFileInUrl();
}

function onItemClick(event, item) {
    const key = itemKey(item);
    contentPane.value?.focus({ preventScroll: true });

    if (hasMod(event)) {
        const next = new Set(selected.value);
        next.has(key) ? next.delete(key) : next.add(key);
        selected.value = next;
        anchorKey.value = key;
        syncFileInUrl();
    } else if (event.shiftKey && anchorKey.value) {
        selectRange(anchorKey.value, key);
    } else {
        select([key], key);
    }
}

function selectRange(fromKey, toKey) {
    const keys = items.value.map(itemKey);
    const [start, end] = [keys.indexOf(fromKey), keys.indexOf(toKey)].sort((a, b) => a - b);
    selected.value = new Set(keys.slice(start, end + 1));
    syncFileInUrl();
}

function clearSelection() {
    select([]);
}

function syncFileInUrl() {
    history.replaceState(history.state, '', pageUrl(currentVolume.value, contents.value.folderId, singleFile.value?.id ?? null));
}

// ----- Navigation -----

let historyIndex = 0;
let historyMax = 0;
const canGoBack = ref(false);
const canGoForward = ref(false);

function pageUrl(volume, folderId, fileId = null) {
    const url = new URL(props.pageUrl, window.location.origin);

    if (volume !== volumes.value[0]?.key) {
        url.searchParams.set('volume', volume);
    }

    if (folderId) {
        url.searchParams.set('folder', folderId);
    }

    if (fileId) {
        url.searchParams.set('file', fileId);
    }

    return url.toString();
}

function updateHistoryButtons() {
    canGoBack.value = historyIndex > 0;
    canGoForward.value = historyIndex < historyMax;
}

const goBack = () => history.back();
const goForward = () => history.forward();

const PREFETCH_LIMIT = 30;
const folderCache = new Map();
const inflight = new Map();
const cacheKey = (volume, folderId) => `${volume}:${folderId ?? 'root'}`;
let requestedKey = cacheKey(props.initial.contents.volume, props.initial.contents.folderId);

folderCache.set(requestedKey, props.initial.contents);

function fetchContents(volume, folderId, { fresh = false } = {}) {
    const key = cacheKey(volume, folderId);

    if (fresh) {
        folderCache.delete(key);
    }

    if (!inflight.has(key)) {
        const request = httpClient
            .get(endpoints.contents, { params: { volume, folder: folderId ?? undefined } })
            .then(({ data }) => {
                folderCache.set(key, data.data);

                return data.data;
            })
            .finally(() => inflight.delete(key));

        inflight.set(key, request);
    }

    return inflight.get(key);
}

function prefetchSubfolders(level) {
    level.folders
        .filter((folder) => !folderCache.has(cacheKey(level.volume, folder.id)))
        .slice(0, PREFETCH_LIMIT)
        .forEach((folder) => fetchContents(level.volume, folder.id).catch(() => null));
}

function showContents(payload) {
    contents.value = payload;
    view.value = { ...payload.view };
}

async function load(volume, folderId, { quiet = false } = {}) {
    const key = cacheKey(volume, folderId);
    const cached = folderCache.get(key);
    requestedKey = key;

    if (cached && !quiet) {
        showContents(cached);
    }

    loading.value = !quiet && !cached;

    try {
        const payload = await fetchContents(volume, folderId, { fresh: true });

        if (requestedKey === key) {
            showContents(payload);
        }

        prefetchSubfolders(payload);
    } catch (error) {
        toast.danger(firstError(error, t('Could not load the folder')));
    } finally {
        loading.value = false;
    }
}

async function loadTree() {
    try {
        const { data } = await httpClient.get(endpoints.folders);
        volumes.value = data.data.volumes;
    } catch {
        // The tree is a shortcut; the folder view stays usable with the copy it has.
    }
}

async function fetchChildren(folderId, options = {}) {
    const payload = await fetchContents(volumeOf(folderId), folderId, options);

    return { folders: payload.folders, files: payload.files };
}

function showChildren(folderId, level) {
    children.value = new Map(children.value).set(folderId, level);
}

async function expand(folder) {
    if (expanded.value.has(folder.id)) {
        return;
    }

    const cached = folderCache.get(cacheKey(volumeOf(folder.id), folder.id));

    if (cached) {
        showChildren(folder.id, { folders: cached.folders, files: cached.files });
        expanded.value = new Set(expanded.value).add(folder.id);
    }

    try {
        const level = await fetchChildren(folder.id, { fresh: Boolean(cached) });
        showChildren(folder.id, level);
        expanded.value = new Set(expanded.value).add(folder.id);
        prefetchSubfolders(level);
    } catch (error) {
        if (!cached) {
            toast.danger(firstError(error, t('Could not load the folder')));
        }
    }
}

function collapse(folder) {
    const next = new Set(expanded.value);
    next.delete(folder.id);
    expanded.value = next;
}

const toggleExpand = (folder) => (expanded.value.has(folder.id) ? collapse(folder) : expand(folder));

async function reloadExpanded() {
    const ids = [...expanded.value];
    const levels = await Promise.all(ids.map((id) => fetchChildren(id, { fresh: true }).catch(() => null)));
    const nextChildren = new Map();
    const nextExpanded = new Set();

    ids.forEach((id, index) => {
        if (levels[index]) {
            nextChildren.set(id, levels[index]);
            nextExpanded.add(id);
        }
    });

    children.value = nextChildren;
    expanded.value = nextExpanded;
}

function refresh() {
    folderCache.clear();

    return Promise.all([load(currentVolume.value, contents.value.folderId, { quiet: true }), loadTree(), reloadExpanded(), runSearch()]);
}

function openFolder(folderId, selectKey = null, volume = volumeOf(folderId)) {
    if (folderId === contents.value.folderId && volume === currentVolume.value) {
        return Promise.resolve();
    }

    query.value = '';
    expanded.value = new Set();
    historyIndex++;
    historyMax = historyIndex;
    history.pushState({ volume, folder: folderId, index: historyIndex }, '', pageUrl(volume, folderId));
    updateHistoryButtons();
    selected.value = new Set(selectKey ? [selectKey] : []);
    anchorKey.value = selectKey;

    return load(volume, folderId);
}

function openItem(item) {
    if (item.type === 'folder') {
        openFolder(item.id);
    } else {
        select([itemKey(item)], itemKey(item));
        quickLookOpen.value = true;
    }
}

function openEnclosing() {
    const path = contents.value.path;

    if (path.length) {
        openFolder(path.at(-2)?.id ?? null, `folder-${path.at(-1).id}`);
    }
}

function onPopState(event) {
    const state = event.state ?? {};
    historyIndex = state.index ?? 0;
    updateHistoryButtons();
    query.value = '';
    expanded.value = new Set();
    selected.value = new Set();
    load(state.volume ?? volumes.value[0]?.key, state.folder ?? null);
}

// ----- View -----

function saveView() {
    sendJson(endpoints.view, 'PUT', {
        volume: currentVolume.value,
        folder_id: contents.value.folderId,
        mode: view.value.mode,
        tile_size: view.value.tileSize,
        sort_by: view.value.sortBy,
        sort_direction: view.value.sortDirection,
    }).catch(() => toast.warning(t('Could not save the view')));
}

function savePaneWidth(pane) {
    const payload = pane === 'info' ? { info_width: shownInfoWidth.value } : { tree_width: shownTreeWidth.value };
    sendJson(endpoints.preferences, 'PUT', payload).catch(() => toast.warning(t('Could not save the column width')));
}

function setMode(mode) {
    view.value.mode = mode;
    saveView();
}

function sortBy(column) {
    if (view.value.sortBy === column) {
        view.value.sortDirection = view.value.sortDirection === 'asc' ? 'desc' : 'asc';
    } else {
        view.value.sortBy = column;
        view.value.sortDirection = ['modified', 'added', 'size'].includes(column) ? 'desc' : 'asc';
    }

    saveView();
}

const modeOptions = computed(() => [
    { value: 'list', label: t('List'), icon: IconModeList, hideLabel: true },
    { value: 'grid', label: t('Thumbnails'), icon: IconModeCards, hideLabel: true },
]);

// ----- Actions -----

async function createFolder() {
    try {
        const { data } = await sendJson(endpoints.foldersStore, 'POST', { volume: currentVolume.value, parent_id: contents.value.folderId });
        await refresh();
        const key = `folder-${data.data.folder.id}`;
        select([key], key);
        renameError.value = '';
        renamingKey.value = key;
        await nextTick();
        scrollToKey(key);
    } catch (error) {
        toast.danger(firstError(error, t('Could not create the folder')));
    }
}

async function createFolderWithItems(chosen) {
    if (!chosen.length) {
        return;
    }

    const parents = new Set(chosen.map((item) => rowByKey.value.get(itemKey(item))?.parentId ?? contents.value.folderId));
    const parentId = parents.size === 1 ? [...parents][0] : contents.value.folderId;

    try {
        const { data } = await sendJson(endpoints.foldersStoreWithItems, 'POST', { ...toPayload(chosen), volume: currentVolume.value, parent_id: parentId });
        const key = `folder-${data.data.folder.id}`;
        query.value = '';

        if (parentId === contents.value.folderId || expanded.value.has(parentId)) {
            await refresh();
        } else {
            await Promise.all([openFolder(parentId, key), loadTree()]);
        }

        select([key], key);
        renameError.value = '';
        renamingKey.value = key;
        await nextTick();
        scrollToKey(key);
    } catch (error) {
        toast.danger(firstError(error, t('Could not create the folder')));
    }
}

function startRename(item) {
    renameError.value = '';
    renamingKey.value = itemKey(item);
}

async function saveRename(item, name) {
    renamingKey.value = null;
    contentPane.value?.focus({ preventScroll: true });

    try {
        const url = item.type === 'folder' ? withId(endpoints.foldersRename, item.id) : withId(endpoints.filesUpdate, item.id);
        await sendJson(url, 'PUT', { name });
        infoVersion.value++;
        await refresh();
    } catch (error) {
        toast.danger(firstError(error, t('Could not rename :name', { name: item.name })));
    }
}

function cancelRename() {
    renamingKey.value = null;
    contentPane.value?.focus({ preventScroll: true });
}

async function moveTo(movedItems, targetId, targetVolume = volumeOf(targetId)) {
    const payload = toPayload(movedItems);
    payload.folders = payload.folders.filter((id) => id !== targetId);

    if (!payload.folders.length && !payload.files.length) {
        return;
    }

    await sendJson(endpoints.itemsMove, 'PUT', { ...payload, target_volume: targetVolume, target_id: targetId });
    clearSelection();
    toast.success(t('Moved'));
    await refresh();
}

function startMove(movedItems) {
    moveError.value = '';
    moving.value = movedItems;
}

async function submitMove({ volume, folderId }) {
    busy.value = true;
    moveError.value = '';

    try {
        await moveTo(moving.value, folderId, volume);
        moving.value = [];
    } catch (error) {
        moveError.value = firstError(error, t('Could not move the items'));
    } finally {
        busy.value = false;
    }
}

async function remove(removedItems) {
    if (!removedItems.length) {
        return;
    }

    const single = removedItems.length === 1 ? removedItems[0] : null;
    const ok = await confirm({
        title: single ? t('Delete :name?', { name: single.name }) : t('Delete :count items?', { count: removedItems.length }),
        message: removedItems.some((item) => item.type === 'folder') ? t('Everything inside the folders is deleted as well. This cannot be undone.') : t('This cannot be undone.'),
        tone: 'danger',
        confirmLabel: t('Delete'),
        cancelLabel: t('Cancel'),
    });

    if (!ok) {
        return;
    }

    try {
        await sendJson(endpoints.itemsDestroy, 'DELETE', toPayload(removedItems));
        quickLookOpen.value = false;
        clearSelection();
        toast.success(t('Deleted'));

        const removedFolders = new Set(removedItems.filter((item) => item.type === 'folder').map((item) => item.id));
        const removedStep = contents.value.path.findIndex((step) => removedFolders.has(step.id));

        if (removedStep !== -1) {
            openFolder(contents.value.path[removedStep - 1]?.id ?? null, null, currentVolume.value);
            await loadTree();
        } else {
            await refresh();
        }
    } catch (error) {
        toast.danger(firstError(error, t('Could not delete the items')));
    }
}

function downloadZip(zippedItems) {
    const params = new URLSearchParams();
    const payload = toPayload(zippedItems);
    payload.folders.forEach((id) => params.append('folders[]', id));
    payload.files.forEach((id) => params.append('files[]', id));
    window.location.href = `${endpoints.itemsZip}?${params}`;
}

function downloadEach(downloadedItems) {
    downloadedItems.filter((item) => item.type === 'file').forEach((file, index) => {
        setTimeout(() => {
            const link = document.createElement('a');
            link.href = file.downloadUrl;
            link.download = file.name;
            document.body.appendChild(link);
            link.click();
            link.remove();
        }, index * 400);
    });
}

// ----- Upload -----

function chooseUpload(targetId = contents.value.folderId) {
    uploadTargetId = targetId;
    fileInput.value.click();
}

function chooseFolderUpload(targetId = contents.value.folderId) {
    uploadTargetId = targetId;
    folderInput.value.click();
}

function onFilesChosen(event) {
    upload(fromFileInput(event.target.files ?? []), uploadTargetId === undefined ? contents.value.folderId : uploadTargetId, currentVolume.value);
    uploadTargetId = undefined;
    event.target.value = '';
}

const UPLOAD_BATCH = 20;

async function upload(entries, targetId, targetVolume = volumeOf(targetId)) {
    if (!entries.length) {
        return;
    }

    uploading.value = true;
    uploadProgress.value = { done: 0, total: entries.length };

    try {
        for (let start = 0; start < entries.length; start += UPLOAD_BATCH) {
            const batch = entries.slice(start, start + UPLOAD_BATCH);
            const form = new FormData();
            batch.forEach(({ file, path }) => {
                form.append('files[]', file);
                form.append('paths[]', path);
            });

            form.append('volume', targetVolume);

            if (targetId) {
                form.append('folder_id', targetId);
            }

            await sendForm(endpoints.filesStore, form);
            uploadProgress.value = { done: start + batch.length, total: entries.length };
        }

        const isHere = targetId === contents.value.folderId && targetVolume === currentVolume.value;
        const into = isHere ? null : (allFolders.value.find((folder) => folder.id === targetId)?.name ?? volumeName(targetVolume));
        toast.success(into ? t(':count files uploaded into :folder', { count: entries.length, folder: into }) : t(':count files uploaded', { count: entries.length }));
    } catch (error) {
        toast.danger(firstError(error, t('Could not upload the files')));
    } finally {
        uploading.value = false;
        uploadProgress.value = null;
        await refresh();
    }
}

// ----- Drag and drop -----

function onItemDragStart(event, item) {
    if (!canWrite.value) {
        event.preventDefault();

        return;
    }

    const key = itemKey(item);

    if (!selected.value.has(key)) {
        select([key], key);
    }

    startItemsDrag(event, selectedItems.value);
}

async function dropOn(event, folderId, volume = volumeOf(folderId)) {
    filesOver.value = false;
    filesDragDepth = 0;

    if (!canWriteTo(volume)) {
        toast.warning(t(':drive is read-only', { drive: volumeName(volume) }));

        return;
    }

    if (carriesItems(event) && volume !== currentVolume.value) {
        toast.info(t('To move items to another drive, copy them and use “Move here” there.'));

        return;
    }

    if (carriesItems(event)) {
        const payload = readDraggedItems(event);
        const dragged = [...payload.folders.map((id) => ({ type: 'folder', id })), ...payload.files.map((id) => ({ type: 'file', id }))];

        try {
            await moveTo(dragged, folderId, volume);
        } catch (error) {
            toast.danger(firstError(error, t('Could not move the items')));
        }
    } else if (carriesFiles(event)) {
        const entries = takeDroppedEntries(event.dataTransfer);
        const files = Array.from(event.dataTransfer.files);
        upload(await collectDroppedFiles(entries, files), folderId, volume);
    }
}

function allowDrop(event) {
    if (carriesItems(event) || carriesFiles(event)) {
        event.preventDefault();
    }
}

function onPaneDragEnter(event) {
    if (carriesFiles(event)) {
        filesDragDepth++;
        filesOver.value = true;
    }
}

function onPaneDragLeave(event) {
    if (carriesFiles(event) && --filesDragDepth <= 0) {
        filesDragDepth = 0;
        filesOver.value = false;
    }
}

function onPaneDragOver(event) {
    if (carriesFiles(event)) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
    }
}

function onPaneDrop(event) {
    if (carriesFiles(event)) {
        event.preventDefault();
        dropOn(event, contents.value.folderId, currentVolume.value);
    }
}

// ----- Context menu -----

function copyToClipboard(chosen) {
    if (!chosen.length) {
        return;
    }

    clipboard.value = { volume: currentVolume.value, items: chosen.map(({ type, id, name }) => ({ type, id, name })) };
    toast.info(chosen.length === 1 ? t(':name copied', { name: chosen[0].name }) : t(':count items copied', { count: chosen.length }));
}

function mayPasteInto(volume, { moving = false } = {}) {
    const source = clipboard.value;

    if (!source || !canWriteTo(volume) || (moving && !canWriteTo(source.volume))) {
        return false;
    }

    return source.volume === volume || props.mayTransfer;
}

async function paste(folderId, volume, { moving = false } = {}) {
    const source = clipboard.value;

    if (!mayPasteInto(volume, { moving })) {
        return;
    }

    const payload = { ...toPayload(source.items), target_volume: volume, target_id: folderId };

    try {
        if (moving) {
            payload.folders = payload.folders.filter((id) => id !== folderId);
            await sendJson(endpoints.itemsMove, 'PUT', payload);
            clipboard.value = null;
            toast.success(t('Moved'));
        } else {
            await sendJson(endpoints.itemsCopy, 'POST', payload);
            toast.success(t('Copied'));
        }

        await refresh();
    } catch (error) {
        toast.danger(firstError(error, moving ? t('Could not move the items') : t('Could not copy the items')));
    }
}

function withPasteGroup(folderId, volume, entries) {
    const pasting = pasteEntries(folderId, volume);

    return pasting.length ? [...pasting, { divider: true }, ...entries] : entries;
}

function pasteEntries(folderId, volume) {
    const count = clipboard.value?.items.length ?? 0;

    if (!count) {
        return [];
    }

    return [
        { label: count === 1 ? t('Paste 1 item') : t('Paste :count items', { count }), icon: 'o-clipboard-document', shortcut: KEYS.paste, disabled: !mayPasteInto(volume), action: () => paste(folderId, volume) },
        { label: count === 1 ? t('Move 1 item here') : t('Move :count items here', { count }), icon: 'o-arrows-right-left', shortcut: KEYS.moveHere, disabled: !mayPasteInto(volume, { moving: true }), action: () => paste(folderId, volume, { moving: true }) },
    ];
}

function itemEntries(chosen) {
    const single = chosen.length === 1 ? chosen[0] : null;
    const files = chosen.filter((item) => item.type === 'file');
    const entries = [];

    if (single?.type === 'folder') {
        entries.push({ label: t('Open'), icon: 'o-folder-open', shortcut: KEYS.open, action: () => openItem(single) });
        entries.push({ label: t('Open in new tab'), icon: 'o-square-2-stack', action: () => openTab({ volume: currentVolume.value, folderId: single.id }) });
    } else if (single) {
        entries.push({ label: t('Preview'), icon: 'o-eye', shortcut: KEYS.quickLook, action: () => openItem(single) });
        entries.push({ label: t('Download'), icon: 'o-arrow-down-tray', action: () => downloadEach([single]) });
    }

    if (!single || single.type === 'folder') {
        entries.push({ label: t('Download as ZIP'), icon: 'o-archive-box-arrow-down', action: () => downloadZip(chosen) });
    }

    if (!single && files.length) {
        entries.push({ label: t('Download :count files individually', { count: files.length }), icon: 'o-arrow-down-tray', action: () => downloadEach(files) });
    }

    entries.push({ divider: true });
    entries.push({ label: t('Copy'), icon: 'o-document-duplicate', shortcut: KEYS.copy, action: () => copyToClipboard(chosen) });

    const finish = (list) => (single?.type === 'folder' ? withPasteGroup(single.id, currentVolume.value, list) : list);

    if (!canWrite.value) {
        return finish(entries);
    }

    entries.push({ divider: true });

    if (single) {
        entries.push({ label: t('Rename'), icon: 'o-pencil-square', shortcut: KEYS.rename, action: () => startRename(single) });
    }

    entries.push({ label: t('Move to…'), icon: 'o-arrows-right-left', action: () => startMove(chosen) });
    entries.push({ label: chosen.length === 1 ? t('New folder with 1 item') : t('New folder with :count items', { count: chosen.length }), icon: 'o-folder-plus', shortcut: KEYS.newFolderWithItems, action: () => createFolderWithItems(chosen) });
    entries.push({ divider: true });
    entries.push({ label: single ? t('Delete') : t('Delete :count items', { count: chosen.length }), icon: 'o-trash', tone: 'danger', shortcut: KEYS.remove, action: () => remove(chosen) });

    return finish(entries);
}

function backgroundEntries() {
    const sortEntry = (column, label) => ({ label, checked: view.value.sortBy === column, action: () => sortBy(column) });

    const writing = canWrite.value
        ? [
            { label: t('New folder'), icon: 'o-folder-plus', shortcut: KEYS.newFolder, action: createFolder },
            { label: t('Upload files…'), icon: 'o-arrow-up-tray', shortcut: KEYS.upload, action: () => chooseUpload() },
            { label: t('Upload folder…'), icon: 'o-folder-arrow-down', action: () => chooseFolderUpload() },
        ]
        : [];
    return withPasteGroup(contents.value.folderId, currentVolume.value, [
        ...writing,
        { label: t('New tab'), icon: 'o-square-2-stack', shortcut: KEYS.newTab, action: () => openTab() },
        { divider: true },
        { label: t('View as list'), checked: view.value.mode === 'list', shortcut: KEYS.list, action: () => setMode('list') },
        { label: t('View as thumbnails'), checked: view.value.mode === 'grid', shortcut: KEYS.grid, action: () => setMode('grid') },
        { divider: true },
        sortEntry('name', t('Sort by name')),
        sortEntry('owner', t('Sort by owner')),
        sortEntry('modified', t('Sort by date modified')),
        sortEntry('added', t('Sort by date added')),
        sortEntry('size', t('Sort by size')),
    ]);
}

function onItemContextMenu(event, item) {
    const key = itemKey(item);

    if (!selected.value.has(key)) {
        select([key], key);
    }

    menu.value = { position: { x: event.clientX, y: event.clientY }, entries: itemEntries(selectedItems.value) };
}

function onItemActions(event, item) {
    const key = itemKey(item);

    if (!selected.value.has(key)) {
        select([key], key);
    }

    const button = event.currentTarget.getBoundingClientRect();
    menu.value = { position: { x: button.right, y: button.bottom, alignRight: true }, entries: itemEntries(selectedItems.value) };
}

function onTreeContextMenu(event, folder) {
    const item = { type: 'folder', id: folder.id, name: folder.name };

    menu.value = {
        position: { x: event.clientX, y: event.clientY },
        entries: withPasteGroup(folder.id, volumeOf(folder.id), [
            { label: t('Open'), icon: 'o-folder-open', action: () => openFolder(folder.id) },
            { label: t('Open in new tab'), icon: 'o-square-2-stack', action: () => openTab({ volume: volumeOf(folder.id), folderId: folder.id }) },
            { label: t('Download as ZIP'), icon: 'o-archive-box-arrow-down', action: () => downloadZip([item]) },
            { divider: true },
            { label: t('Copy'), icon: 'o-document-duplicate', action: () => copyToClipboard([item]) },
            ...(canWriteTo(volumeOf(folder.id))
                ? [
                    { divider: true },
                    { label: t('Rename'), icon: 'o-pencil-square', action: () => (treeRenamingId.value = folder.id) },
                    { label: t('Move to…'), icon: 'o-arrows-right-left', action: () => startMove([item]) },
                    { divider: true },
                    { label: t('Delete'), icon: 'o-trash', tone: 'danger', action: () => remove([item]) },
                ]
                : []),
        ]),
    };
}

function saveTreeRename(folder, name) {
    treeRenamingId.value = null;
    saveRename({ type: 'folder', id: folder.id, name: folder.name }, name);
}

function onBackgroundContextMenu(event) {
    clearSelection();
    menu.value = { position: { x: event.clientX, y: event.clientY }, entries: backgroundEntries() };
}

// ----- Keyboard -----

let typeBuffer = '';
let typeTimer = null;

function scrollToKey(key) {
    contentPane.value?.querySelector(`[data-item-key="${key}"]`)?.scrollIntoView({ block: 'nearest' });
}

function gridColumns() {
    const tiles = Array.from(contentPane.value?.querySelectorAll('[data-item-key]') ?? []);

    return Math.max(1, tiles.filter((tile) => tile.offsetTop === tiles[0]?.offsetTop).length);
}

function moveSelection(key, extend) {
    const keys = items.value.map(itemKey);

    if (!keys.length) {
        return;
    }

    const vertical = key === 'ArrowUp' || key === 'ArrowDown';

    if (view.value.mode === 'list' && !vertical) {
        expandWithArrow(key);

        return;
    }

    const direction = key === 'ArrowUp' || key === 'ArrowLeft' ? -1 : 1;
    const step = direction * (view.value.mode === 'grid' && vertical ? gridColumns() : 1);
    const current = keys.indexOf([...selected.value].at(-1));
    const next = current === -1 ? (step > 0 ? 0 : keys.length - 1) : Math.min(keys.length - 1, Math.max(0, current + step));
    const nextKey = keys[next];

    if (extend && anchorKey.value) {
        selectRange(anchorKey.value, nextKey);
        selected.value = new Set([...[...selected.value].filter((selectedKey) => selectedKey !== nextKey), nextKey]);
    } else {
        select([nextKey], nextKey);
    }

    scrollToKey(nextKey);
}

function expandWithArrow(key) {
    const current = selectedItems.value.length === 1 ? selectedItems.value[0] : null;

    if (!current) {
        return;
    }

    if (key === 'ArrowRight' && current.type === 'folder') {
        expand(current);
    } else if (key === 'ArrowLeft' && current.type === 'folder' && expanded.value.has(current.id)) {
        collapse(current);
    } else if (key === 'ArrowLeft') {
        const parentKey = `folder-${rowByKey.value.get(itemKey(current))?.parentId}`;

        if (rowByKey.value.has(parentKey)) {
            select([parentKey], parentKey);
            scrollToKey(parentKey);
        }
    }
}

function jumpToName(character) {
    clearTimeout(typeTimer);
    typeBuffer += character.toLowerCase();
    typeTimer = setTimeout(() => (typeBuffer = ''), 800);

    const match = items.value.find((item) => item.name.toLowerCase().startsWith(typeBuffer));

    if (match) {
        select([itemKey(match)], itemKey(match));
        scrollToKey(itemKey(match));
    }
}

function onKeydown(event) {
    if (menu.value || helpOpen.value || moving.value.length || renamingKey.value || treeRenamingId.value) {
        return;
    }

    if (quickLookOpen.value) {
        if (event.key === ' ') {
            event.preventDefault();
            quickLookOpen.value = false;
        } else if (event.key.startsWith('Arrow')) {
            event.preventDefault();
            moveSelection(event.key, false);
        }

        return;
    }

    if (isTyping(event) || document.querySelector('[role="dialog"]')) {
        return;
    }

    const mod = hasMod(event);
    const handled = () => event.preventDefault();

    const newFolderWithItems = isMac ? event.metaKey && event.ctrlKey && event.code === 'KeyN' : event.ctrlKey && event.altKey && event.shiftKey && event.code === 'KeyN';

    const hasTextSelection = Boolean(window.getSelection()?.toString());

    if (event.altKey && !mod && event.code === 'KeyT') {
        handled();
        openTab();

        return;
    }

    if (event.altKey && !mod && event.code === 'KeyW') {
        handled();
        closeTab(activeTab.value);

        return;
    }

    if (mod && event.code === 'KeyC' && !event.altKey && !event.shiftKey && !hasTextSelection) {
        handled();
        copyToClipboard(selectedItems.value);
    } else if (mod && event.code === 'KeyV') {
        handled();
        paste(contents.value.folderId, currentVolume.value, { moving: event.altKey });
    } else if (!canWrite.value && (newFolderWithItems || (mod && event.code === 'KeyN') || (mod && event.altKey && event.code === 'KeyU') || (mod && event.key === 'Backspace') || event.key === 'Delete' || event.key === 'Enter')) {
        return;
    } else if (newFolderWithItems) {
        handled();
        createFolderWithItems(selectedItems.value);
    } else if (mod && (event.shiftKey || event.altKey) && event.code === 'KeyN') {
        handled();
        createFolder();
    } else if (mod && event.altKey && event.code === 'KeyU') {
        handled();
        chooseUpload();
    } else if (event.altKey && !mod && (event.code === 'Digit1' || event.code === 'Digit2')) {
        handled();
        setMode(event.code === 'Digit1' ? 'list' : 'grid');
    } else if (mod && event.code === 'KeyA') {
        handled();
        select(items.value.map(itemKey), items.value[0] ? itemKey(items.value[0]) : null);
    } else if (mod && event.key === 'ArrowDown') {
        handled();

        if (selectedItems.value.length === 1) {
            openItem(selectedItems.value[0]);
        }
    } else if (mod && event.key === 'ArrowUp') {
        handled();
        openEnclosing();
    } else if ((mod && event.key === 'Backspace') || event.key === 'Delete') {
        handled();
        remove(selectedItems.value);
    } else if (mod || event.altKey) {
        return;
    } else if (event.key.startsWith('Arrow')) {
        handled();
        moveSelection(event.key, event.shiftKey);
    } else if (event.key === 'Enter' && selectedItems.value.length === 1) {
        handled();
        startRename(selectedItems.value[0]);
    } else if (event.key === ' ' && singleFile.value) {
        handled();
        quickLookOpen.value = true;
    } else if (event.key === 'Escape') {
        clearSelection();
    } else if (event.key === '/') {
        handled();
        searchField.value?.focus();
    } else if (event.key === '?') {
        handled();
        helpOpen.value = true;
    } else if (event.key.length === 1 && /\S/.test(event.key)) {
        jumpToName(event.key);
    }
}

function onSearchKey(event) {
    if (event.key === 'Escape') {
        query.value = '';
        event.target.blur();
    } else if (event.key === 'Enter' || event.key === 'ArrowDown') {
        event.preventDefault();
        event.target.blur();

        if (items.value[0]) {
            select([itemKey(items.value[0])], itemKey(items.value[0]));
        }
    }
}

// ----- Lifecycle -----

function fitToViewport() {
    windowWidth.value = root.value?.clientWidth ?? 0;
    windowHeight.value = `${window.innerHeight - ((root.value?.getBoundingClientRect().top ?? 0) + window.scrollY)}px`;
}

onMounted(() => {
    prefetchSubfolders(contents.value);
    fitToViewport();
    window.addEventListener('resize', fitToViewport);
    history.replaceState({ volume: currentVolume.value, folder: contents.value.folderId, index: 0 }, '', window.location.href);
    window.addEventListener('popstate', onPopState);
    window.addEventListener('keydown', onKeydown);

    if (props.initial.fileId) {
        const key = `file-${props.initial.fileId}`;
        select([key], key);
        nextTick(() => scrollToKey(key));
        quickLookOpen.value = props.initial.preview;
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', fitToViewport);
    window.removeEventListener('popstate', onPopState);
    window.removeEventListener('keydown', onKeydown);
});

function onFileChanged(file) {
    const row = contents.value.files.find((candidate) => candidate.id === file.id);

    if (row) {
        Object.assign(row, { name: file.name, hasNotes: file.hasNotes, commentCount: file.commentCount, updatedAt: file.updatedAt });
    }
}
</script>

<template>
    <div ref="root" class="island-view files w-full" :style="rootStyle">
        <section class="flex flex-col overflow-hidden bg-white dark:bg-zinc-800" :style="{ height: windowHeight, minHeight: '480px' }">
            <TabBar v-if="tabs.length > 1" :tabs="tabs" :active="activeTab" :label-of="tabLabel" @select="switchTab" @close="closeTab" @add="openTab()" @drop="dropOnTab" />

            <div class="flex h-12 shrink-0 items-center gap-2 border-b border-gray-200 px-3 dark:border-white/10">
                <div class="flex items-center">
                    <IconButton :label="t('Back')" size="sm" :disabled="!canGoBack" @click="goBack"><Icon name="o-chevron-left" /></IconButton>
                    <IconButton :label="t('Forward')" size="sm" :disabled="!canGoForward" @click="goForward"><Icon name="o-chevron-right" /></IconButton>
                </div>

                <nav class="flex min-w-0 flex-1 items-center gap-0.5 overflow-x-auto text-sm" :aria-label="t('Folder path')">
                    <button
                        type="button"
                        class="inline-flex h-7 shrink-0 items-center rounded-md px-2 hover:bg-gray-100 dark:hover:bg-white/5"
                        :class="contents.path.length ? 'text-gray-600 dark:text-gray-300' : 'font-semibold text-gray-900 dark:text-white'"
                        @click="openFolder(null, null, currentVolume)"
                        @dragover="allowDrop"
                        @drop.prevent="dropOn($event, null, currentVolume)"
                    >
                        {{ volumeName(currentVolume) }}
                    </button>
                    <template v-for="(step, index) in contents.path" :key="step.id">
                        <Icon name="o-chevron-right" class="h-3.5 w-3.5 shrink-0 text-gray-400" />
                        <button
                            type="button"
                            class="inline-flex h-7 shrink-0 items-center rounded-md px-2 hover:bg-gray-100 dark:hover:bg-white/5"
                            :class="index === contents.path.length - 1 ? 'font-semibold text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-300'"
                            @click="openFolder(step.id)"
                            @dragover="allowDrop"
                            @drop.prevent="dropOn($event, step.id)"
                        >
                            {{ step.name }}
                        </button>
                    </template>
                </nav>

                <div class="hidden w-28 md:block" :class="view.mode === 'grid' ? '' : 'invisible'">
                    <Slider v-model="view.tileSize" :min="props.tileSize.min" :max="props.tileSize.max" :step="8" :aria-label="t('Thumbnail size')" @commit="saveView" />
                </div>
                <OptionStrip :model-value="view.mode" :options="modeOptions" variant="segmented" size="sm" :marker="false" :aria-label="t('View')" @update:model-value="setMode" />
                <label class="relative hidden sm:block">
                    <span class="sr-only">{{ t('Search in this folder') }}</span>
                    <Icon name="o-magnifying-glass" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                    <input
                        ref="searchField"
                        v-model="query"
                        type="search"
                        :placeholder="t('Search in :folder', { folder: folderName })"
                        class="h-8 w-52 rounded-full border border-gray-200 bg-white pl-8 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/30 dark:border-white/10 dark:bg-white/5 dark:text-white"
                        @keydown="onSearchKey"
                    />
                </label>
            </div>

            <div class="flex min-h-0 flex-1">
                <aside class="relative hidden shrink-0 border-r border-gray-200 bg-gray-50/70 lg:block dark:border-white/10 dark:bg-white/[0.02]" :style="{ width: `${shownTreeWidth}px` }">
                    <div class="h-full overflow-y-auto">
                        <FolderTree
                            :volumes="volumes"
                            :current-volume="currentVolume"
                            :current-id="contents.folderId"
                            :path="contents.path"
                            :renaming-id="treeRenamingId"
                            @open="(folderId, volume) => openFolder(folderId, null, volume)"
                            @open-tab="(folderId, volume) => openTab({ volume, folderId })"
                            @drop="dropOn"
                            @contextmenu="onTreeContextMenu"
                            @rename-save="saveTreeRename"
                            @rename-cancel="treeRenamingId = null"
                        />
                    </div>
                    <PaneResizer
                        :model-value="shownTreeWidth"
                        :min="limits.tree.min"
                        :max="treeMax"
                        :reset="limits.tree.default"
                        :label="t('Resize the folder column')"
                        edge="right"
                        @update:model-value="treeWidth = $event"
                        @commit="savePaneWidth('tree')"
                    />
                </aside>

                <div
                    ref="contentPane"
                    tabindex="0"
                    class="relative min-w-0 flex-1 overflow-y-auto outline-none"
                    :class="filesOver ? 'bg-primary-50/50 ring-2 ring-inset ring-primary-500 dark:bg-primary-500/10' : ''"
                    @click="clearSelection"
                    @contextmenu.prevent="onBackgroundContextMenu"
                    @dragenter="onPaneDragEnter"
                    @dragleave="onPaneDragLeave"
                    @dragover="onPaneDragOver"
                    @drop="onPaneDrop"
                >
                    <div v-if="loading" class="space-y-1 p-3">
                        <Skeleton v-for="row in 8" :key="row" variant="block" height="28px" />
                    </div>

                    <div v-else-if="!totalCount" class="flex h-full flex-col items-center justify-center gap-1.5 p-6 text-center">
                        <Icon name="o-folder-open" class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                        <p class="font-medium text-gray-900 dark:text-white">{{ t('This folder is empty') }}</p>
                        <p v-if="canWrite" class="text-gray-500 dark:text-gray-400">{{ t('Drop files here, or right-click for more.') }}</p>
                    </div>

                    <div v-else-if="!items.length" class="flex h-full flex-col items-center justify-center gap-1.5 p-6 text-center">
                        <p class="font-medium text-gray-900 dark:text-white">{{ t('Nothing in :folder matches “:query”', { folder: folderName, query }) }}</p>
                        <button type="button" class="text-sm text-primary-600 hover:underline dark:text-primary-400" @click.stop="query = ''">{{ t('Clear search') }}</button>
                    </div>

                    <ItemList
                        v-else-if="view.mode === 'list'"
                        :rows="rows"
                        :show-location="searching"
                        :search-term="searching ? query.trim() : ''"
                        :selected="selected"
                        :expanded="expanded"
                        :renaming-key="renamingKey"
                        :rename-error="renameError"
                        :sort-by="view.sortBy"
                        :sort-direction="view.sortDirection"
                        @select="onItemClick"
                        @open="openItem"
                        @open-tab="(item) => openTab({ volume: currentVolume, folderId: item.id })"
                        @toggle="toggleExpand"
                        @contextmenu="onItemContextMenu"
                        @actions="onItemActions"
                        @dragstart="onItemDragStart"
                        @drop="dropOn"
                        @rename-save="saveRename"
                        @rename-cancel="cancelRename"
                        @sort="sortBy"
                    />

                    <ItemGrid
                        v-else
                        :rows="rows"
                        :selected="selected"
                        :renaming-key="renamingKey"
                        :rename-error="renameError"
                        :tile-size="view.tileSize"
                        :search-term="searching ? query.trim() : ''"
                        @select="onItemClick"
                        @open="openItem"
                        @open-tab="(item) => openTab({ volume: currentVolume, folderId: item.id })"
                        @contextmenu="onItemContextMenu"
                        @dragstart="onItemDragStart"
                        @drop="dropOn"
                        @rename-save="saveRename"
                        @rename-cancel="cancelRename"
                    />
                </div>

                <aside class="relative hidden shrink-0 border-l border-gray-200 xl:block dark:border-white/10" :style="{ width: `${shownInfoWidth}px` }">
                    <PaneResizer
                        :model-value="shownInfoWidth"
                        :min="limits.info.min"
                        :max="infoMax"
                        :reset="limits.info.default"
                        :label="t('Resize the info column')"
                        @update:model-value="infoWidth = $event"
                        @commit="savePaneWidth('info')"
                    />
                    <div class="h-full overflow-y-auto">
                        <InfoPanel
                            :can-write="canWrite"
                            :items="selectedItems"
                            :folder-name="folderName"
                            :folder-item-count="totalCount"
                            :location="location"
                            :version="infoVersion"
                            :endpoints="endpoints"
                            @changed="onFileChanged"
                            @quick-look="quickLookOpen = true"
                        />
                    </div>
                </aside>
            </div>

            <div class="flex h-7 shrink-0 items-center justify-between gap-3 border-t border-gray-200 px-3 text-xs tabular-nums text-gray-500 dark:border-white/10 dark:text-gray-400">
                <span>
                    <template v-if="selectedItems.length">{{ t(':selected of :count selected', { selected: selectedItems.length, count: items.length }) }}</template>
                    <template v-else>{{ itemCount(t, items.length) }}</template>
                    <template v-if="selectedSize"> · {{ formatFileSize(selectedSize) }}</template>
                </span>
                <span class="flex items-center gap-3">
                    <span v-if="!canWrite">{{ t('Read-only') }}</span>
                    <span v-if="clipboard">{{ t(':count copied', { count: clipboard.items.length }) }}</span>
                    <span v-if="uploading" class="tabular-nums">{{ uploadProgress ? t('Uploading :done of :total', uploadProgress) : t('Uploading…') }}</span>
                    <button type="button" class="inline-flex items-center gap-1 hover:text-gray-900 dark:hover:text-white" @click="helpOpen = true">
                        <Icon name="o-question-mark-circle" class="h-3.5 w-3.5" />
                        {{ t('Keyboard shortcuts') }}
                    </button>
                </span>
            </div>
        </section>

        <input ref="fileInput" type="file" multiple class="hidden" @change="onFilesChosen" />
        <input ref="folderInput" type="file" webkitdirectory class="hidden" @change="onFilesChosen" />

        <ContextMenu :position="menu?.position ?? null" :entries="menu?.entries ?? []" @close="menu = null" />
        <MoveModal :open="moving.length > 0" :items="moving" :folders-url="endpoints.folders" :current-volume="currentVolume" :error="moveError" :busy="busy" @close="moving = []" @submit="submitMove" />
        <QuickLook :file="quickLookOpen ? singleFile : null" @close="quickLookOpen = false" />
        <ShortcutsHelp :open="helpOpen" @close="helpOpen = false" />

        <ConfirmHost />
        <ToastHost />
    </div>
</template>

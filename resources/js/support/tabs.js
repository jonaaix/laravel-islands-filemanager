import { ref } from 'vue';
import { sendJson } from '@aaix/laravel-islands-datagrid/vue';

const SAVE_DELAY = 400;

export function useTabs({ saved, activeIndex, start, saveUrl, here, open }) {
    const tabs = ref(saved.length ? saved.map((tab) => ({ ...tab })) : [start]);
    const activeTab = ref(saved.length ? activeIndex : 0);
    let saveTimer = null;

    tabs.value[activeTab.value] = { ...start };

    function saveTabs() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            sendJson(saveUrl, 'PUT', {
                tabs: tabs.value.map((tab) => ({ volume: tab.volume, folder_id: tab.folderId })),
                active_tab: activeTab.value,
            }).catch(() => null);
        }, SAVE_DELAY);
    }

    function rememberActiveTab() {
        const current = tabs.value[activeTab.value];
        const location = here();

        if (current && (current.volume !== location.volume || current.folderId !== location.folderId)) {
            tabs.value[activeTab.value] = location;
            saveTabs();
        }
    }

    function openTab(location = here()) {
        tabs.value.push({ ...location });
        activeTab.value = tabs.value.length - 1;
        saveTabs();
        open(location);
    }

    function switchTab(index) {
        if (index === activeTab.value) {
            return;
        }

        activeTab.value = index;
        saveTabs();
        open(tabs.value[index]);
    }

    function closeTab(index) {
        if (tabs.value.length === 1) {
            return;
        }

        const wasActive = index === activeTab.value;
        tabs.value.splice(index, 1);

        if (wasActive) {
            activeTab.value = Math.min(index, tabs.value.length - 1);
            open(tabs.value[activeTab.value]);
        } else if (index < activeTab.value) {
            activeTab.value--;
        }

        saveTabs();
    }

    return { tabs, activeTab, rememberActiveTab, openTab, switchTab, closeTab };
}

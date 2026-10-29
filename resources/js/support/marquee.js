import { ref } from 'vue';
import { hasMod } from './shortcuts.js';

const THRESHOLD = 4;
const EDGE = 32;
const SCROLL_STEP = 14;

export function useMarquee(pane, { canStart, currentKeys, onChange }) {
    const box = ref(null);

    let origin = null;
    let pointer = null;
    let baseKeys = [];
    let frame = null;
    let swallowNextClick = false;

    function toContent(clientX, clientY) {
        const rect = pane.value.getBoundingClientRect();

        return { x: clientX - rect.left + pane.value.scrollLeft, y: clientY - rect.top + pane.value.scrollTop };
    }

    function start(event) {
        if (event.button !== 0 || !pane.value || !canStart() || event.target.closest('[data-item-key], button, input, a, thead')) {
            return;
        }

        event.preventDefault();
        origin = toContent(event.clientX, event.clientY);
        pointer = { x: event.clientX, y: event.clientY };
        baseKeys = event.shiftKey || hasMod(event) ? currentKeys() : [];
        window.addEventListener('mousemove', move);
        window.addEventListener('mouseup', end);
    }

    function move(event) {
        pointer = { x: event.clientX, y: event.clientY };
        update();
    }

    function update() {
        const point = toContent(pointer.x, pointer.y);

        if (!box.value && Math.hypot(point.x - origin.x, point.y - origin.y) < THRESHOLD) {
            return;
        }

        const left = Math.min(origin.x, point.x);
        const top = Math.min(origin.y, point.y);
        box.value = { left, top, width: Math.abs(point.x - origin.x), height: Math.abs(point.y - origin.y) };

        const hits = Array.from(pane.value.querySelectorAll('[data-item-key]')).filter((element) => {
            const rect = element.getBoundingClientRect();
            const corner = toContent(rect.left, rect.top);

            return corner.x < left + box.value.width && corner.x + rect.width > left && corner.y < top + box.value.height && corner.y + rect.height > top;
        }).map((element) => element.dataset.itemKey);

        onChange([...new Set([...baseKeys, ...hits])], hits.at(-1) ?? null);
        scrollNearEdge();
    }

    function scrollNearEdge() {
        cancelAnimationFrame(frame);
        const rect = pane.value.getBoundingClientRect();
        const step = pointer.y > rect.bottom - EDGE ? SCROLL_STEP : pointer.y < rect.top + EDGE ? -SCROLL_STEP : 0;

        if (step !== 0) {
            frame = requestAnimationFrame(() => {
                pane.value.scrollTop += step;
                update();
            });
        }
    }

    function end() {
        window.removeEventListener('mousemove', move);
        window.removeEventListener('mouseup', end);
        cancelAnimationFrame(frame);
        swallowNextClick = box.value !== null;
        box.value = null;
        origin = null;
    }

    function consumeClick() {
        const swallowed = swallowNextClick;
        swallowNextClick = false;

        return swallowed;
    }

    return { box, start, consumeClick };
}

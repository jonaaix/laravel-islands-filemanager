export async function sendForm(url, formData) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': token,
        },
        body: formData,
    });

    if (!response.ok) {
        const error = new Error(`Request to ${url} failed with status ${response.status}`);
        error.status = response.status;
        error.payload = await response.json().catch(() => null);

        throw error;
    }

    return { data: await response.json() };
}

export function firstError(error, fallback) {
    const errors = error?.payload?.errors;

    if (errors) {
        return Object.values(errors).flat()[0] ?? fallback;
    }

    return error?.payload?.message ?? fallback;
}

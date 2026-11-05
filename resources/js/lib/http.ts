// Pedidos JSON ao Laravel fora das visitas Inertia (por exemplo o catálogo,
// que se actualiza dentro do cartão). O token CSRF vem do cookie XSRF-TOKEN
// que o Laravel envia em cada resposta.

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export async function requestJson<T>(method: 'GET' | 'PUT' | 'POST' | 'PATCH' | 'DELETE', url: string, body?: unknown): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const errors = (data as { errors?: Record<string, string[]> }).errors;
        const first = errors ? Object.values(errors)[0]?.[0] : undefined;
        throw new Error(first ?? (response.status === 403 ? 'Sem permissão para esta ação.' : 'Não foi possível concluir o pedido.'));
    }
    return data as T;
}

// Mensagem de um erro apanhado num catch, para mostrar ao utilizador.
export function errorText(err: unknown, fallback = 'Não foi possível concluir o pedido.'): string {
    return err instanceof Error && err.message ? err.message : fallback;
}

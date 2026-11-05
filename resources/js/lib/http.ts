// Pedidos JSON ao Laravel fora das visitas Inertia (por exemplo o catálogo,
// que se actualiza dentro do cartão). O token CSRF vem do cookie XSRF-TOKEN
// que o Laravel envia em cada resposta.

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

// Erro devolvido pelo servidor: a mensagem já vem em português; "code" e
// "details" vêm das regras de negócio (App\Exceptions\DomainRuleException).
export class RequestError extends Error {
    constructor(message: string, public readonly code: string | null = null, public readonly details: string | null = null) {
        super(message);
    }
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
        const body = data as { errors?: Record<string, string[]>; message?: string; code?: string; details?: string | null };
        const first = body.errors ? Object.values(body.errors)[0]?.[0] : undefined;
        const fallback = response.status === 403 ? 'Sem permissão para esta ação.' : 'Não foi possível concluir o pedido.';
        throw new RequestError(first ?? (body.code ? body.message : undefined) ?? fallback, body.code ?? null, body.details ?? null);
    }
    return data as T;
}

// Mensagem de um erro apanhado num catch, para mostrar ao utilizador.
export function errorText(err: unknown, fallback = 'Não foi possível concluir o pedido.'): string {
    if (err instanceof TypeError) return 'Sem ligação ao servidor. Verifique a internet e tente de novo.';
    return err instanceof Error && err.message ? err.message : fallback;
}

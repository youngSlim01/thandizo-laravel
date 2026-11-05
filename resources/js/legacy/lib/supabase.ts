// Camada de compatibilidade da migração: callRpc(nome, parâmetros) chama o
// endpoint Laravel /rpc/<nome>, com os mesmos parâmetros sem o prefixo p_.
// Os erros chegam como o código antigo (ex.: PREVIEW_OUTDATED), que
// lib/errors.ts traduz, e "details" quando a regra o envia.

export const isSupabaseConfigured = true;

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export async function callRpc<T>(name: string, params: Record<string, unknown>): Promise<T> {
    const body = Object.fromEntries(Object.entries(params).map(([key, value]) => [key.replace(/^p_/, ''), value]));
    let response: Response;
    try {
        response = await fetch(`/rpc/${name}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(body),
        });
    } catch {
        throw new Error('Failed to fetch');
    }
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const payload = data as { code?: string; message?: string; details?: string; errors?: Record<string, string[]> };
        const first = payload.errors ? Object.values(payload.errors)[0]?.[0] : undefined;
        const error = new Error(payload.code ?? first ?? payload.message ?? (response.status === 403 ? 'ROLE_NOT_ALLOWED' : 'UNKNOWN_ERROR')) as Error & { details?: string };
        error.details = payload.details;
        throw error;
    }
    return data as T;
}

export function getSupabase(): never {
    throw new Error('Supabase já não é usado: use callRpc.');
}

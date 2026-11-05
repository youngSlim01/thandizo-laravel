import { router, usePage } from '@inertiajs/react';

// Camada de compatibilidade da migração: next/navigation → router do Inertia.

export function useRouter() {
    return {
        push: (url: string) => router.visit(url),
        replace: (url: string) => router.visit(url, { replace: true }),
        back: () => window.history.back(),
        refresh: () => router.reload(),
    };
}

export function usePathname(): string {
    return usePage().url.split('?')[0];
}

export function useSearchParams(): URLSearchParams {
    const url = usePage().url;
    return new URLSearchParams(url.includes('?') ? url.slice(url.indexOf('?')) : '');
}

// Parâmetros da rota (ex.: /receipt/{id}) chegam como props da página.
export function useParams<T extends Record<string, string>>(): T {
    return (usePage().props as { params?: T }).params ?? ({} as T);
}

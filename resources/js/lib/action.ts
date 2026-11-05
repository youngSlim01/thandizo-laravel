import { router } from '@inertiajs/react';
import type { RequestPayload } from '@inertiajs/core';
import { useState } from 'react';

type Method = 'post' | 'put' | 'patch' | 'delete';

// Primeira mensagem de erro de uma visita Inertia: a regra de negócio vem em
// "rule" (App\Exceptions\DomainRuleException), a validação no nome do campo.
export function firstError(errors: Record<string, string>): string {
    return errors.rule ?? Object.values(errors)[0] ?? 'Não foi possível concluir o pedido.';
}

// Acção que muda dados e volta à mesma página (o servidor responde com
// back() e a mensagem em flash.success). Guarda o estado "a enviar" e o erro
// de cada botão ou formulário.
export function useAction() {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    function run(method: Method, url: string, data: RequestPayload = {}, onSuccess?: () => void) {
        setError(null);
        // A página fica como estava (painéis abertos, filtros); só os dados mudam.
        const options = {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onError: (errors: Record<string, string>) => setError(firstError(errors)),
            onSuccess: () => onSuccess?.(),
        };
        if (method === 'delete') {
            router.delete(url, { ...options, data });
        } else {
            router[method](url, data, options);
        }
    }

    return { busy, error, setError, run };
}

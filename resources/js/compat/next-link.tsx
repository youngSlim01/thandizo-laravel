import { Link as InertiaLink } from '@inertiajs/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';

// Camada de compatibilidade da migração: next/link → Link do Inertia.
type Props = AnchorHTMLAttributes<HTMLAnchorElement> & { href: string; children?: ReactNode; prefetch?: boolean; replace?: boolean };

export default function Link({ href, prefetch: _prefetch, replace, ...rest }: Props) {
    // As props de <a> passam tal e qual; o Link do Inertia tipa os eventos de forma mais larga.
    const anchorProps = rest as Record<string, unknown>;
    return <InertiaLink href={href} replace={replace} {...anchorProps} />;
}

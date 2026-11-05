<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Uma regra de negócio recusou a operação. O código (ex.: PREVIEW_OUTDATED)
 * é o mesmo das antigas funções SQL e a mensagem em português está em
 * lang/pt/rules.php; "details" leva informação extra quando a regra a dá.
 */
class DomainRuleException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly ?string $details = null)
    {
        parent::__construct($errorCode);
    }

    /**
     * Mensagem para o utilizador. Um código sem tradução mostra-se tal e qual.
     */
    public function userMessage(): string
    {
        $key = "rules.{$this->errorCode}";
        $message = __($key, ['details' => (string) $this->details]);

        return $message === $key ? $this->errorCode : $message;
    }

    /**
     * Num pedido JSON responde 422 com o código, a mensagem e os detalhes.
     * Ao abrir uma página que a regra recusa (ex.: o recibo de outro
     * agrodealer) responde 403; numa acção volta à página com o erro em
     * "errors.rule".
     */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $isJson = $request->expectsJson() && ! $request->header('X-Inertia');
        if (! $isJson && $request->isMethod('GET')) {
            throw new AccessDeniedHttpException($this->userMessage());
        }
        if (! $isJson) {
            return back()->withErrors(['rule' => $this->userMessage()]);
        }

        return response()->json([
            'code' => $this->errorCode,
            'message' => $this->userMessage(),
            'details' => $this->details,
        ], 422);
    }
}

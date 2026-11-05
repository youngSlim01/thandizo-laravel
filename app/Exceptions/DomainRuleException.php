<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Uma regra de negócio recusou a operação. O código é o mesmo das antigas
 * funções SQL (ex.: PREVIEW_OUTDATED), que o frontend traduz em
 * lib/errors.ts; "details" leva informação extra quando a regra a dá.
 */
class DomainRuleException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly ?string $details = null)
    {
        parent::__construct($errorCode);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => $this->errorCode,
            'message' => $this->errorCode,
            'details' => $this->details,
        ], 422);
    }
}

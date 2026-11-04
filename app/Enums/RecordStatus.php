<?php

namespace App\Enums;

/**
 * Situação de um registo: utilizadores, produtos, agrodealers…
 * (antigo enum public.record_status).
 */
enum RecordStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}

<?php

namespace App\Enums;

// Antigo enum public.record_status aplicado a utilizadores.
enum UserStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}

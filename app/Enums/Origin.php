<?php

namespace App\Enums;

enum Origin: string
{
    case User = 'user';
    case AI = 'ai';
    case Inferred = 'inferred';
    case System = 'system';
}

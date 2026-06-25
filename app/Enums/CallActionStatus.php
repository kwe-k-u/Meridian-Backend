<?php

namespace App\Enums;

enum CallActionStatus: string
{
    case PENDING = 'pending';
    case CHECKED = 'checked';
    case ARCHIVED = 'archived';
}

<?php

namespace App\Enums;

enum CallActionItemStatus: string
{
    case PENDING = 'pending';
    case CHECKED = 'checked';
    case ARCHIVED = 'archived';
}

<?php

namespace App\Enums;

/**
 * Status of a single action item extracted from a call (App\Models\CallActionItem).
 * PENDING = not yet actioned, CHECKED = marked done by an agent, ARCHIVED = dismissed.
 * DashboardController counts CHECKED vs PENDING to report "AI handled" vs "pending review" tasks.
 */
enum CallActionItemStatus: string
{
    case PENDING = 'pending';
    case CHECKED = 'checked';
    case ARCHIVED = 'archived';
}

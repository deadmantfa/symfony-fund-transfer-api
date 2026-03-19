<?php

declare(strict_types=1);

namespace App\Domain\LedgerEntry;

enum EntryType: string
{
    case Debit  = 'debit';
    case Credit = 'credit';
}

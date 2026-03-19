<?php

declare(strict_types=1);

namespace App\Domain\AuditLog;

interface AuditLogRepository
{
    public function append(AuditLog $log): void;

    public function nextIdentity(): AuditLogId;
}

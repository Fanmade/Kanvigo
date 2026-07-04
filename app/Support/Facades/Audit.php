<?php

namespace App\Support\Facades;

use App\Audit\AuditManager;
use Illuminate\Support\Facades\Facade;
use Kanvigo\Audit\Contracts\AuditEvent;
use Kanvigo\Audit\Contracts\AuditSink;

/**
 * @method static void record(AuditEvent $event)
 * @method static list<AuditSink> sinks()
 * @method static list<AuditSink> queuedSinks()
 * @method static void flushSinks()
 *
 * @see AuditManager
 */
class Audit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuditManager::class;
    }
}

<?php

namespace App\Audit\Pii;

/**
 * What the redaction transform does to a field of a given {@see DataClass}
 * before the event crosses an external boundary.
 */
enum RedactionStrategy: string
{
    /** Pass the value through untouched. */
    case Keep = 'keep';

    /** Replace the value with a stable pseudonymous token. */
    case Tokenize = 'tokenize';

    /** Remove the value entirely. */
    case Drop = 'drop';
}

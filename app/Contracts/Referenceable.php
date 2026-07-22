<?php

namespace App\Contracts;

use App\Concerns\HasReferences;

/**
 * An item that can take part in cross-references (a Task or a Doc). A marker
 * interface: it carries no methods, but constrains what {@see HasReferences}
 * will link, so a reference can never point at an unrelated model (a User, say).
 */
interface Referenceable
{
    //
}

<?php

namespace App\Services\Degs;

use App\Services\CreditRegistrySoapClient;

/**
 * DegsClient — thin alias for CreditRegistrySoapClient.
 * Exists so controllers can type-hint DegsClient while the
 * underlying implementation lives in CreditRegistrySoapClient.
 */
class DegsClient extends CreditRegistrySoapClient
{
}

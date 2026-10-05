<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Model;

enum RateLimitResetCode: string
{
    case Reset = 'reset';
    case NothingToReset = 'nothing_to_reset';
    case NoCredit = 'no_credit';
    case AlreadyRedeemed = 'already_redeemed';
}

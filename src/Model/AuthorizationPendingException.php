<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Model;

final class AuthorizationPendingException extends OpenAiDeviceAuthException
{
    public function __construct()
    {
        parent::__construct('Authorization still pending.');
    }
}

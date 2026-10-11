<?php

declare(strict_types=1);

namespace App\Service\Gmail;

/** The last registration attempt in this request, with no provider payload. */
final class GmailPushAttempt
{
    public ?string $reason = null;
}

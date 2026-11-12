<?php

namespace App\Services\Integrations\BoxNow;

use RuntimeException;

class BoxNowApiException extends RuntimeException
{
    public function __construct(string $message, public readonly string $apiCode = '', public readonly bool $rejected = false)
    {
        parent::__construct($message);
    }
}

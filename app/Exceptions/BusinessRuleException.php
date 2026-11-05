<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A request that is well-formed but breaks a business rule, e.g. "slot already taken",
 * "not enough stock". Rendered as HTTP 422:
 *   { "message": "Human readable text", "code": "slot_taken", "errors": {} }
 * Apps should branch on `code` (stable) and show `message` (may change wording).
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'business_rule',
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function make(string $code, string $message, array $details = [], int $status = 422): self
    {
        return new self($message, $code, $status, $details);
    }
}

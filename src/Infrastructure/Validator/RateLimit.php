<?php

declare(strict_types=1);

namespace App\Infrastructure\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PROPERTY)]
class RateLimit extends Constraint
{
    public string $message = 'Trop de requêtes. Réessayez plus tard.';
    public string $limiter = 'contact_submit';
    public ?string $key = null;

    public function __construct(
        ?string $message = null,
        ?string $limiter = null,
        ?string $key = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);

        if ($message) {
            $this->message = $message;
        }

        if ($limiter) {
            $this->limiter = $limiter;
        }

        if ($key) {
            $this->key = $key;
        }
    }
}

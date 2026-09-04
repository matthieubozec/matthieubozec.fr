<?php

declare(strict_types=1);

namespace App\Infrastructure\Validator;

use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class RateLimitValidator extends ConstraintValidator
{
    public function __construct(
        /**
         * @var ServiceLocator<RateLimiterFactoryInterface> $limiterProvider
         */
        #[AutowireLocator('rate_limiter')]
        private ServiceLocator $limiterProvider,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof RateLimit) {
            throw new UnexpectedTypeException($constraint, RateLimit::class);
        }

        if (!\is_scalar($value)) {
            return;
        }

        $limiterName = 'limiter.'.$constraint->limiter;
        if (!$this->limiterProvider->has($limiterName)) {
            throw new \RuntimeException(sprintf('Le rate limiter "%s" est introuvable.', $constraint->limiter));
        }

        /** @var RateLimiterFactoryInterface $factory */
        $factory = $this->limiterProvider->get($limiterName);
        $key = $constraint->key ?? (string) $value;

        $limiter = $factory->create($key);
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $this->context
                ->buildViolation($constraint->message)
                ->addViolation();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\CacheWarmer;

use App\Infrastructure\Database\ContactMessagesTableInitializer;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

class ContactMessagesTableWarmer implements CacheWarmerInterface
{
    public function __construct(
        private readonly ContactMessagesTableInitializer $initializer,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isOptional(): bool
    {
        return true;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->logger?->info('[ContactMessagesTableWarmer] Vérification de la table contact_messages...');
        $this->initializer->initialize();

        return [];
    }
}

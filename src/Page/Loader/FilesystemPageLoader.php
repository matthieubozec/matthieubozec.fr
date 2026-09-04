<?php

declare(strict_types=1);

namespace App\Page\Loader;

use App\Page\Page;
use App\Page\PageLoaderInterface;
use App\Page\TemplateData;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

readonly class FilesystemPageLoader implements PageLoaderInterface
{
    public function __construct(
        #[Autowire(value: '%kernel.project_dir%/templates/page')]
        private string $basePath,
    ) {
    }

    /**
     * @return list<Page>
     */
    public function load(): array
    {
        $finder = new Finder();
        $finder->files()->in($this->basePath)->name('*.twig');

        $pages = [];

        foreach ($finder as $file) {
            [$frontMatter, $content] = $this->parseFile($file->getContents());
            $meta = Yaml::parse((string) $frontMatter);

            if (!$meta) {
                continue;
            }

            $pages[] = new Page(
                key: $this->resolveKey($file),
                templatePath: $file->getRealPath(),
                templateContent: (string) $content,
                templateData: new TemplateData((array) $meta),
                lastModified: \DateTimeImmutable::createFromFormat('U', (string) $file->getMTime(), new \DateTimeZone('UTC')) ?: null,
            );
        }

        return $pages;
    }

    /**
     * @return array{string, string}
     */
    private function parseFile(string $contents): array
    {
        if (preg_match('/^---\s*(.*?)\s*---\s*(.*)$/s', $contents, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return ['', $contents];
    }

    private function resolveKey(SplFileInfo $file): string
    {
        $name = $file->getRelativePathname();

        // On enlève tout ce qu’il y a après le premier point (toutes extensions)
        $name = preg_replace('/\..*$/', '', $name);

        // On remplace les séparateurs de dossier par des underscores
        $name = str_replace(['/', '\\'], '_', (string) $name);

        // on ne garde que lettres, chiffres et underscores
        $name = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string) $name);

        return (string) $name;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Page\Repository;

use App\Page\Page;
use App\Page\PageLoaderInterface;
use App\Page\Repository\PageRepository;
use App\Page\TemplateData;
use PHPUnit\Framework\TestCase;

final class PageRepositoryTest extends TestCase
{
    public function testAllLoadsPagesOnce(): void
    {
        $page1 = $this->makePage('home', 'home');
        $page2 = $this->makePage('about', 'about');

        $loader = $this->createMock(PageLoaderInterface::class);
        $loader->expects($this->once())
            ->method('load')
            ->willReturn([$page1, $page2]);

        $repo = new PageRepository($loader);

        $pages1 = $repo->all();
        $this->assertSame([$page1, $page2], $pages1);

        $pages2 = $repo->all();
        $this->assertSame($pages1, $pages2);
    }

    public function testFindReturnsPageByKey(): void
    {
        $page1 = $this->makePage('home', 'home');
        $page2 = $this->makePage('about', 'about');

        $loader = $this->createMock(PageLoaderInterface::class);
        $loader->expects($this->once())
            ->method('load')
            ->willReturn([$page1, $page2]);

        $repo = new PageRepository($loader);

        $found = $repo->find('about');
        $this->assertSame($page2, $found);
        $this->assertNull($repo->find('missing'));
    }

    public function testFindByPermalinkMatchesNormalizedPath(): void
    {
        $page1 = $this->makePage('home', '');
        $page2 = $this->makePage('contact', 'contact');

        $loader = $this->createMock(PageLoaderInterface::class);
        $loader->expects($this->once())
            ->method('load')
            ->willReturn([$page1, $page2]);

        $repo = new PageRepository($loader);

        $this->assertSame($page1, $repo->findByPermalink('/'));
        $this->assertSame($page2, $repo->findByPermalink('/contact/'));
        $this->assertNull($repo->findByPermalink('unknown'));
    }

    /** ---------------- Helpers ---------------- */
    private function makePage(string $key, string $permalink): Page
    {
        return new Page(
            key: $key,
            templatePath: "/fake/$key.twig",
            templateContent: "<div>$key</div>",
            templateData: new TemplateData(['permalink' => $permalink]),
            lastModified: new \DateTimeImmutable()
        );
    }
}

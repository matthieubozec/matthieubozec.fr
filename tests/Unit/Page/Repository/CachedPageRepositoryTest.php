<?php

declare(strict_types=1);

namespace App\Tests\Unit\Page\Repository;

use App\Page\Page;
use App\Page\PageRepositoryInterface;
use App\Page\Repository\CachedPageRepository;
use App\Page\TemplateData;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class CachedPageRepositoryTest extends TestCase
{
    public function testAllFetchesFromDecoratedAndStoresInCache(): void
    {
        $page = $this->makePage('home', '');

        $decorated = $this->createMock(PageRepositoryInterface::class);
        $decorated->expects($this->once())
            ->method('all')
            ->willReturn([$page]);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->once())->method('isHit')->willReturn(false);
        $cacheItem->expects($this->once())->method('set')->with([$page]);
        $cacheItem->expects($this->once())->method('get')->willReturn([$page]);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->expects($this->once())
            ->method('getItem')
            ->with('pages')
            ->willReturn($cacheItem);
        $pool->expects($this->once())->method('save')->with($cacheItem);

        $repo = new CachedPageRepository($decorated, $pool, debug: false);

        $pages = $repo->all();

        $this->assertSame([$page], $pages);
    }

    public function testAllUsesCacheWhenAvailable(): void
    {
        $page = $this->makePage('home', '');

        $decorated = $this->createMock(PageRepositoryInterface::class);
        $decorated->expects($this->never())->method('all');

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->once())->method('isHit')->willReturn(true);
        $cacheItem->expects($this->never())->method('set');
        $cacheItem->expects($this->once())->method('get')->willReturn([$page]);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->expects($this->once())->method('getItem')->with('pages')->willReturn($cacheItem);
        $pool->expects($this->never())->method('save');

        $repo = new CachedPageRepository($decorated, $pool);

        $pages = $repo->all();

        $this->assertSame([$page], $pages);
    }

    public function testAllExpiresCacheInDebugMode(): void
    {
        $page = $this->makePage('home', '');

        $decorated = $this->createMock(PageRepositoryInterface::class);
        $decorated->expects($this->once())->method('all')->willReturn([$page]);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->once())->method('expiresAfter')->with(-1);
        $cacheItem->expects($this->once())->method('isHit')->willReturn(false);
        $cacheItem->expects($this->once())->method('set')->with([$page]);
        $cacheItem->expects($this->once())->method('get')->willReturn([$page]);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->expects($this->once())->method('getItem')->willReturn($cacheItem);
        $pool->expects($this->once())->method('save');

        $repo = new CachedPageRepository($decorated, $pool, debug: true);

        $pages = $repo->all();

        $this->assertSame([$page], $pages);
    }

    public function testFindAndFindByPermalinkUseCachedPages(): void
    {
        $page1 = $this->makePage('home', '');
        $page2 = $this->makePage('about', 'about');

        $decorated = $this->createMock(PageRepositoryInterface::class);
        $decorated->expects($this->never())->method('all');

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([$page1, $page2]);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($cacheItem);

        $repo = new CachedPageRepository($decorated, $pool);

        $this->assertSame($page2, $repo->find('about'));
        $this->assertSame($page1, $repo->findByPermalink(''));
        $this->assertNull($repo->find('missing'));
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

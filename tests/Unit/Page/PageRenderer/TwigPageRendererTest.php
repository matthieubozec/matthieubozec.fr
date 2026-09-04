<?php

declare(strict_types=1);

namespace App\Tests\Unit\Page\PageRenderer;

use App\Page\Page;
use App\Page\PageRenderer\TwigPageRenderer;
use App\Page\TemplateData;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

final class TwigPageRendererTest extends TestCase
{
    public function testRenderReturnsResponseWithRenderedContentAndLastModified(): void
    {
        $twig = $this->createMock(Environment::class);
        $request = $this->createMock(Request::class);
        $cache = $this->createMock(CacheManager::class);
        $renderer = new TwigPageRenderer($twig, $cache);

        $page = new Page(
            key: 'home',
            templatePath: '/fake/path/home.twig',
            templateContent: '<h1>Home</h1>',
            templateData: new TemplateData(['title' => 'Accueil']),
            lastModified: new \DateTimeImmutable('2024-10-10 10:10:10 UTC')
        );

        $twig
            ->expects($this->once())
            ->method('render')
            ->with('home', ['page' => $page])
            ->willReturn('<html><body>OK</body></html>');

        $response = $renderer->render($page, $request);

        $this->assertSame('<html><body>OK</body></html>', $response->getContent());
        $this->assertSame(
            $page->lastModified()?->setTimezone(new \DateTimeZone('UTC'))->format('D, d M Y H:i:s').' GMT',
            $response->headers->get('Last-Modified')
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Page\Navigation;

use App\Page\Navigation\NavigationBuilder;
use App\Page\Navigation\NavigationNode;
use App\Page\Page;
use App\Page\TemplateData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class NavigationBuilderTest extends KernelTestCase
{
    private UrlGeneratorInterface $urlGenerator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);
    }

    public function testItBuildsHierarchicalNavigationUsingRealRouter(): void
    {
        $builder = new NavigationBuilder($this->urlGenerator);

        $pages = [
            $this->makePage('index', '/', [
                'label' => 'Accueil',
                'order' => 1,
            ]),
            $this->makePage('blog', '/blog', [
                'label' => 'Blog',
                'order' => 2,
            ]),
            $this->makePage('post-1', '/blog/post-1', [
                'label' => 'Article 1',
                'parent' => 'blog',
                'order' => 1,
            ]),
            $this->makePage('post-2', '/blog/post-2', [
                'label' => 'Article 2',
                'parent' => 'blog',
                'order' => 2,
            ]),
        ];

        $tree = $builder->build($pages);

        $this->assertCount(2, $tree);
        $this->assertSame(['index', 'blog'], array_map(fn (NavigationNode $n) => $n->key, $tree));

        foreach ($tree as $node) {
            $this->assertStringStartsWith('http', (string) $node->url);
            $this->assertStringContainsString($node->title, $node->title);
        }

        $blog = $tree[1];
        $this->assertCount(2, $blog->children);
        $this->assertSame(['post-1', 'post-2'], array_map(fn (NavigationNode $n) => $n->key, $blog->children));

        // ✅ Vérifie que le tri fonctionne
        $this->assertSame('Article 1', $blog->children[0]->title);
        $this->assertSame('Article 2', $blog->children[1]->title);
    }

    /** ---------------- Helpers ---------------- */
    private function makePage(string $key, string $permalink, array $navigation /* @phpstan-ignore missingType.iterableValue */): Page
    {
        $data = [
            'permalink' => ltrim($permalink, '/'), // lu par Page::permalink()
            'navigation' => $navigation,
        ];

        return new Page(
            key: $key,
            templatePath: '/fake/path/'.$key.'.twig',
            templateContent: '<div>'.$key.'</div>',
            templateData: new TemplateData($data),
            lastModified: new \DateTimeImmutable()
        );
    }
}

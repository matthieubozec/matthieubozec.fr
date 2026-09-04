<?php

declare(strict_types=1);

namespace App\Tests\Unit\Page\Loader;

use App\Page\Loader\FilesystemPageLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class FilesystemPageLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/fs_page_loader_'.bin2hex(random_bytes(6));
        $this->mkdir($this->tmpDir);
        $this->mkdir($this->tmpDir.'/blog');
        $this->mkdir($this->tmpDir.'/landing');

        // 1️⃣ Fichier valide
        $this->createTwig(
            'home.twig',
            [
                'layout' => 'base',
                'permalink' => '/home',
                'title' => 'Accueil',
                'nested' => ['foo' => 'bar'],
            ],
            "<h1>Hello</h1>\n<p>Welcome</p>\n",
            1710000000
        );

        // 2️⃣ Fichier valide dans sous-dossier
        $this->createTwig(
            'blog/post-1.detail.twig',
            [
                'layout' => 'article',
                'permalink' => '/blog/post-1',
            ],
            "<article>Post 1</article>\n",
            1710000500
        );

        // 3️⃣ Fichier sans front-matter → ignoré
        file_put_contents($this->tmpDir.'/landing/landing.twig', "<div>no front matter</div>\n");
        touch($this->tmpDir.'/landing/landing.twig', 1710000600);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testLoadParsesFrontMatterAndBuildsPages(): void
    {
        $loader = new FilesystemPageLoader($this->tmpDir);
        $pages = $loader->load();

        // On attend 2 pages (le fichier sans front-matter est ignoré)
        $this->assertCount(2, $pages);

        // Indexation pour tests plus lisibles
        $byKey = [];
        foreach ($pages as $page) {
            $byKey[$page->key()] = $page;
        }

        $this->assertArrayHasKey('home', $byKey);
        $this->assertArrayHasKey('blog_post_1', $byKey);

        // ---- Page "home"
        $home = $byKey['home'];
        $this->assertSame('home', $home->key());
        $this->assertSame(realpath($this->tmpDir.'/home.twig'), $home->templatePath());
        $this->assertStringContainsString('<h1>Hello</h1>', $home->content());
        $this->assertSame('base', $home->layout());
        $this->assertSame('/home', $home->permalink());
        $this->assertSame('Accueil', $home->get('title'));
        $this->assertSame('bar', $home->get('nested.foo'));
        $this->assertSame(1710000000, $home->lastModified()?->getTimestamp());

        // ---- Page "blog_post_1"
        $post = $byKey['blog_post_1'];
        $this->assertSame('blog_post_1', $post->key());
        $this->assertSame(realpath($this->tmpDir.'/blog/post-1.detail.twig'), $post->templatePath());
        $this->assertStringContainsString('<article>Post 1</article>', $post->content());
        $this->assertSame('article', $post->layout());
        $this->assertSame('/blog/post-1', $post->permalink());
        $this->assertSame(1710000500, $post->lastModified()?->getTimestamp());
    }

    /** ---------------- Helpers ---------------- */

    /**
     * Crée un .twig avec front-matter YAML et fixe le mtime.
     *
     * @param array<string,mixed> $frontMatter
     */
    private function createTwig(string $relativePath, array $frontMatter, string $content, int $mtime): void
    {
        $full = $this->tmpDir.'/'.$relativePath;

        $dir = \dirname($full);
        if (!is_dir($dir)) {
            $this->mkdir($dir);
        }

        $yaml = Yaml::dump($frontMatter, 2, 2);
        $file = "---\n{$yaml}---\n".$content;

        file_put_contents($full, $file);
        touch($full, $mtime);
    }

    private function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create directory: %s', $dir));
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}

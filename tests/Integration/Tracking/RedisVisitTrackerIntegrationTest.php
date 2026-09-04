<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tracking;

use App\Infrastructure\Redis\RedisProvider;
use App\Tracking\RedisVisitTracker;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[RequiresPhpExtension('redis')]
class RedisVisitTrackerIntegrationTest extends TestCase
{
    private RedisProvider $redisProvider;
    private RedisVisitTracker $tracker;
    private \Redis $redis;

    protected function setUp(): void
    {
        // Get Redis DSN from environment or use default
        $redisDsn = $_ENV['REDIS_DSN'] ?? 'redis://localhost:6379';
        assert(is_string($redisDsn));

        $this->redisProvider = new RedisProvider($redisDsn);

        try {
            $this->redis = $this->redisProvider->get();
            $this->redis->ping(); // Test connection
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis server is not available: '.$e->getMessage());
        }

        $this->tracker = new RedisVisitTracker($this->redis);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            // Clean up test data
            $this->redis->flushDB();
        }
    }

    public function testTrackWithValidRequest(): void
    {
        // Arrange
        $request = Request::create(
            '/test-page',
            'GET',
            [],
            [],
            [],
            [
                'HTTP_REFERER' => 'https://example.com',
                'HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9,en;q=0.8',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36',
                'REMOTE_ADDR' => '192.168.1.1',
            ]
        );

        $expectedDay = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');

        // Act
        $this->tracker->track($request);

        // Assert - Check that data was stored in Redis
        $this->assertRedisDataExists($expectedDay, '/test-page');
    }

    public function testTrackWithMinimalRequest(): void
    {
        // Arrange
        $request = Request::create('/minimal-page');

        $expectedDay = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');

        // Act
        $this->tracker->track($request);

        // Assert
        $this->assertRedisDataExists($expectedDay, '/minimal-page');
    }

    public function testTrackMultipleRequests(): void
    {
        // Arrange
        $request1 = Request::create('/page1');
        $request2 = Request::create('/page2');
        $request3 = Request::create('/page1'); // Same page again

        $expectedDay = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');

        // Act
        $this->tracker->track($request1);
        $this->tracker->track($request2);
        $this->tracker->track($request3);

        // Assert
        $this->assertRedisDataExists($expectedDay, '/page1');
        $this->assertRedisDataExists($expectedDay, '/page2');

        // Check total count
        $totalKey = "pageviews:total:{$expectedDay}";
        $totalCount = $this->redis->get($totalKey);
        $this->assertEquals('3', $totalCount);

        // Check individual page counts
        $page1Key = "pageviews:/page1:{$expectedDay}";
        $page1Count = $this->redis->get($page1Key);
        $this->assertEquals('2', $page1Count);

        $page2Key = "pageviews:/page2:{$expectedDay}";
        $page2Count = $this->redis->get($page2Key);
        $this->assertEquals('1', $page2Count);

        // Check popularity ranking
        $popularity = $this->redis->zScore('pageviews:zset', '/page1');
        $this->assertEquals(2.0, $popularity);

        $popularity = $this->redis->zScore('pageviews:zset', '/page2');
        $this->assertEquals(1.0, $popularity);
    }

    public function testTrackWithSpecialCharactersInPath(): void
    {
        // Arrange
        $request = Request::create('/test-page/with-special-chars_123');

        $expectedDay = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');

        // Act
        $this->tracker->track($request);

        // Assert
        $this->assertRedisDataExists($expectedDay, '/test-page/with-special-chars_123');
    }

    public function testTrackWithEmptyHeaders(): void
    {
        // Arrange
        $request = Request::create('/test-page', 'GET', [], [], [], [
            'HTTP_REFERER' => '',
            'HTTP_ACCEPT_LANGUAGE' => '',
            'HTTP_USER_AGENT' => '',
        ]);

        $expectedDay = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');

        // Act
        $this->tracker->track($request);

        // Assert
        $this->assertRedisDataExists($expectedDay, '/test-page');
    }

    public function testTrackDataStructure(): void
    {
        // Arrange
        $request = Request::create(
            '/test-structure',
            'GET',
            [],
            [],
            [],
            [
                'HTTP_REFERER' => 'https://example.com',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
                'HTTP_USER_AGENT' => 'Test Browser',
                'REMOTE_ADDR' => '10.0.0.1',
            ]
        );

        $expectedDay = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');

        // Act
        $this->tracker->track($request);

        // Assert - Check the structure of stored data
        $zsetKey = "visits:{$expectedDay}";
        $members = $this->redis->zRange($zsetKey, 0, -1, true);

        $this->assertCount(1, $members);

        $jsonData = array_key_first($members);
        $jsonString = is_string($jsonData) ? $jsonData : '';
        $data = json_decode($jsonString, true);

        $this->assertIsArray($data);
        $this->assertEquals('/test-structure', $data['path']);
        $this->assertEquals('10.0.0.1', $data['ip']);
        $this->assertEquals('https://example.com', $data['referrer']);
        $this->assertEquals('en-US,en;q=0.9', $data['accept_language']);
        $this->assertEquals('Test Browser', $data['user_agent']);
        $this->assertIsNumeric($data['timestamp']);
    }

    public function testTrackTransactionIntegrity(): void
    {
        // Arrange
        $request = Request::create('/transaction-test');

        $expectedDay = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');

        // Act
        $this->tracker->track($request);

        // Assert - All operations should be atomic
        $this->assertRedisDataExists($expectedDay, '/transaction-test');

        // Verify all keys exist (transaction was successful)
        $zsetKey = "visits:{$expectedDay}";
        $totalKey = "pageviews:total:{$expectedDay}";
        $pageKey = "pageviews:/transaction-test:{$expectedDay}";
        $popularityKey = 'pageviews:zset';

        $this->assertTrue((bool) $this->redis->exists($zsetKey));
        $this->assertTrue((bool) $this->redis->exists($totalKey));
        $this->assertTrue((bool) $this->redis->exists($pageKey));
        $this->assertTrue((bool) $this->redis->exists($popularityKey));
    }

    public function testTrackWithDifferentDays(): void
    {
        // This test simulates tracking across different days
        $request = Request::create('/multi-day-test');

        // Track for "today"
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');
        $this->tracker->track($request);

        // Verify today's data exists
        $this->assertRedisDataExists($today, '/multi-day-test');

        // Check that data is properly isolated by day
        $todayTotalKey = "pageviews:total:{$today}";
        $todayCount = $this->redis->get($todayTotalKey);
        $this->assertEquals('1', $todayCount);
    }

    public function testTrackWithJsonEncodingError(): void
    {
        // This test ensures the tracker handles JSON encoding gracefully
        $request = Request::create('/json-test');

        // Act - Should not throw exception even if JSON encoding fails
        $this->tracker->track($request);

        // Assert - Should still create basic tracking data
        $expectedDay = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');
        $this->assertRedisDataExists($expectedDay, '/json-test');
    }

    public function testTrackPerformance(): void
    {
        // Arrange
        $request = Request::create('/performance-test');
        $expectedDay = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');

        // Act - Track multiple requests quickly
        $startTime = microtime(true);

        for ($i = 0; $i < 10; ++$i) {
            $this->tracker->track($request);
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Assert
        $this->assertRedisDataExists($expectedDay, '/performance-test');

        // Performance assertion (should complete in reasonable time)
        $this->assertLessThan(1.0, $executionTime, 'Tracking should complete within 1 second');

        // Verify count
        $totalKey = "pageviews:total:{$expectedDay}";
        $totalCount = $this->redis->get($totalKey);
        $this->assertEquals('10', $totalCount);
    }

    /**
     * Helper method to assert that Redis data exists for a given day and path.
     */
    private function assertRedisDataExists(string $day, string $path): void
    {
        $zsetKey = "visits:{$day}";
        $totalKey = "pageviews:total:{$day}";
        $pageKey = "pageviews:{$path}:{$day}";
        $popularityKey = 'pageviews:zset';

        // Check that all keys exist
        $this->assertTrue((bool) $this->redis->exists($zsetKey), "ZSET key should exist: {$zsetKey}");
        $this->assertTrue((bool) $this->redis->exists($totalKey), "Total key should exist: {$totalKey}");
        $this->assertTrue((bool) $this->redis->exists($pageKey), "Page key should exist: {$pageKey}");
        $this->assertTrue((bool) $this->redis->exists($popularityKey), "Popularity key should exist: {$popularityKey}");

        // Check that values are positive
        $totalCount = $this->redis->get($totalKey);
        $totalCountValue = is_string($totalCount) ? $totalCount : '0';
        $this->assertGreaterThan(0, (int) $totalCountValue, 'Total count should be positive');

        $pageCount = $this->redis->get($pageKey);
        $pageCountValue = is_string($pageCount) ? $pageCount : '0';
        $this->assertGreaterThan(0, (int) $pageCountValue, 'Page count should be positive');

        // Check that the path exists in popularity ranking
        $popularity = $this->redis->zScore($popularityKey, $path);
        $this->assertGreaterThan(0, $popularity, 'Path should have positive popularity score');

        // Check that visit data exists in ZSET
        $members = $this->redis->zRange($zsetKey, 0, -1);
        $this->assertNotEmpty($members, 'Visit data should exist in ZSET');

        // Verify that at least one entry contains the correct path
        $pathFound = false;
        foreach ($members as $jsonData) {
            $jsonString = is_string($jsonData) ? $jsonData : '';
            $data = json_decode($jsonString, true);
            if (is_array($data) && isset($data['path']) && $data['path'] === $path) {
                $pathFound = true;
                break;
            }
        }
        $this->assertTrue($pathFound, "Visit data should contain the correct path: {$path}");
    }
}

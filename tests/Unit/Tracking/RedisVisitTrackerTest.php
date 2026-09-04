<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tracking;

use App\Tracking\RedisVisitTracker;
use App\Tracking\VisitTrackerInterface;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Redis;
use Symfony\Component\HttpFoundation\Request;

#[RequiresPhpExtension('redis')]
class RedisVisitTrackerTest extends TestCase
{
    private \Redis&MockObject $redis;
    private RedisVisitTracker $tracker;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(\Redis::class);
        $this->tracker = new RedisVisitTracker($this->redis);
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
        $expectedTimestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp();
        $expectedTtl = VisitTrackerInterface::TTL_DAYS * 86400;

        $expectedData = [
            'path' => '/test-page',
            'ip' => '192.168.1.1',
            'referrer' => 'https://example.com',
            'accept_language' => 'fr-FR,fr;q=0.9,en;q=0.8',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36',
            'timestamp' => $expectedTimestamp,
        ];

        $expectedJson = json_encode($expectedData, JSON_THROW_ON_ERROR);

        // Mock Redis operations
        $this->redis->expects($this->once())
            ->method('multi');

        $this->redis->expects($this->once())
            ->method('zAdd')
            ->with("visits:{$expectedDay}", $this->isFloat(), $expectedJson);

        $this->redis->expects($this->exactly(4))
            ->method('expire')
            ->with($this->logicalOr(
                $this->identicalTo("visits:{$expectedDay}"),
                $this->identicalTo("pageviews:total:{$expectedDay}"),
                $this->identicalTo("pageviews:/test-page:{$expectedDay}"),
                $this->identicalTo("visitors:unique:{$expectedDay}")
            ), $expectedTtl);

        $this->redis->expects($this->exactly(2))
            ->method('incr')
            ->with($this->logicalOr(
                $this->identicalTo("pageviews:total:{$expectedDay}"),
                $this->identicalTo("pageviews:/test-page:{$expectedDay}")
            ));

        $this->redis->expects($this->once())
            ->method('zIncrBy')
            ->with('pageviews:zset', 1, '/test-page');

        $this->redis->expects($this->once())
            ->method('sAdd')
            ->with("visitors:unique:{$expectedDay}", $this->isString());

        $this->redis->expects($this->once())
            ->method('exec');

        // Act
        $this->tracker->track($request);
    }

    public function testTrackWithMinimalRequest(): void
    {
        // Arrange
        $request = Request::create('/minimal-page');

        $expectedDay = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');
        $expectedTimestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp();
        $expectedTtl = VisitTrackerInterface::TTL_DAYS * 86400;

        $expectedData = [
            'path' => '/minimal-page',
            'ip' => '127.0.0.1', // Default IP for Request::create
            'referrer' => '',
            'accept_language' => 'en-us,en;q=0.5', // Default from Request::create
            'user_agent' => 'Symfony', // Default from Request::create
            'timestamp' => $expectedTimestamp,
        ];

        $expectedJson = json_encode($expectedData, JSON_THROW_ON_ERROR);

        // Mock Redis operations
        $this->redis->expects($this->once())
            ->method('multi');

        $this->redis->expects($this->once())
            ->method('zAdd')
            ->with("visits:{$expectedDay}", $this->isFloat(), $expectedJson);

        $this->redis->expects($this->exactly(4))
            ->method('expire')
            ->with($this->logicalOr(
                $this->identicalTo("visits:{$expectedDay}"),
                $this->identicalTo("pageviews:total:{$expectedDay}"),
                $this->identicalTo("pageviews:/minimal-page:{$expectedDay}"),
                $this->identicalTo("visitors:unique:{$expectedDay}")
            ), $expectedTtl);

        $this->redis->expects($this->exactly(2))
            ->method('incr')
            ->with($this->logicalOr(
                $this->identicalTo("pageviews:total:{$expectedDay}"),
                $this->identicalTo("pageviews:/minimal-page:{$expectedDay}")
            ));

        $this->redis->expects($this->once())
            ->method('zIncrBy')
            ->with('pageviews:zset', 1, '/minimal-page');

        $this->redis->expects($this->once())
            ->method('sAdd')
            ->with("visitors:unique:{$expectedDay}", $this->isString());

        $this->redis->expects($this->once())
            ->method('exec');

        // Act
        $this->tracker->track($request);
    }

    public function testTrackWithJsonEncodingError(): void
    {
        // Arrange
        $request = Request::create('/test-page');

        // Mock Redis operations
        $this->redis->expects($this->once())
            ->method('multi');

        $this->redis->expects($this->once())
            ->method('zAdd')
            ->with($this->stringContains('visits:'), $this->isFloat(), $this->isString());

        $this->redis->expects($this->exactly(4))
            ->method('expire');

        $this->redis->expects($this->exactly(2))
            ->method('incr');

        $this->redis->expects($this->once())
            ->method('zIncrBy');

        $this->redis->expects($this->once())
            ->method('sAdd')
            ->with($this->stringContains('visitors:unique:'), $this->isString());

        $this->redis->expects($this->once())
            ->method('exec');

        // Act & Assert - This should not throw an exception
        $this->tracker->track($request);
    }

    public function testTrackWithSpecialCharactersInPath(): void
    {
        // Arrange
        $request = Request::create('/test-page/with-special-chars_123');

        $expectedDay = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');
        $expectedTtl = VisitTrackerInterface::TTL_DAYS * 86400;

        // Mock Redis operations
        $this->redis->expects($this->once())
            ->method('multi');

        $this->redis->expects($this->once())
            ->method('zAdd')
            ->with("visits:{$expectedDay}", $this->isFloat(), $this->isString());

        $this->redis->expects($this->exactly(4))
            ->method('expire')
            ->with($this->logicalOr(
                $this->identicalTo("visits:{$expectedDay}"),
                $this->identicalTo("pageviews:total:{$expectedDay}"),
                $this->identicalTo("pageviews:/test-page/with-special-chars_123:{$expectedDay}"),
                $this->identicalTo("visitors:unique:{$expectedDay}")
            ), $expectedTtl);

        $this->redis->expects($this->exactly(2))
            ->method('incr')
            ->with($this->logicalOr(
                $this->identicalTo("pageviews:total:{$expectedDay}"),
                $this->identicalTo("pageviews:/test-page/with-special-chars_123:{$expectedDay}")
            ));

        $this->redis->expects($this->once())
            ->method('zIncrBy')
            ->with('pageviews:zset', 1, '/test-page/with-special-chars_123');

        $this->redis->expects($this->once())
            ->method('sAdd')
            ->with("visitors:unique:{$expectedDay}", $this->isString());

        $this->redis->expects($this->once())
            ->method('exec');

        // Act
        $this->tracker->track($request);
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
        $expectedTtl = VisitTrackerInterface::TTL_DAYS * 86400;

        $expectedData = [
            'path' => '/test-page',
            'ip' => '127.0.0.1',
            'referrer' => '',
            'accept_language' => '',
            'user_agent' => '',
            'timestamp' => $this->isFloat(),
        ];

        // Mock Redis operations
        $this->redis->expects($this->once())
            ->method('multi');

        $this->redis->expects($this->once())
            ->method('zAdd')
            ->with("visits:{$expectedDay}", $this->isFloat(), $this->callback(function (mixed $json) use ($expectedData): bool {
                $jsonString = is_string($json) ? $json : '';
                $data = json_decode($jsonString, true);

                return is_array($data)
                    && isset($data['path'], $data['ip'], $data['referrer'], $data['accept_language'], $data['user_agent'], $data['timestamp'])
                    && $data['path'] === $expectedData['path']
                    && $data['ip'] === $expectedData['ip']
                    && $data['referrer'] === $expectedData['referrer']
                    && $data['accept_language'] === $expectedData['accept_language']
                    && $data['user_agent'] === $expectedData['user_agent']
                    && is_numeric($data['timestamp']);
            }));

        $this->redis->expects($this->exactly(4))
            ->method('expire')
            ->with($this->logicalOr(
                $this->identicalTo("visits:{$expectedDay}"),
                $this->identicalTo("pageviews:total:{$expectedDay}"),
                $this->identicalTo("pageviews:/test-page:{$expectedDay}"),
                $this->identicalTo("visitors:unique:{$expectedDay}")
            ), $expectedTtl);

        $this->redis->expects($this->exactly(2))
            ->method('incr')
            ->with($this->logicalOr(
                $this->identicalTo("pageviews:total:{$expectedDay}"),
                $this->identicalTo("pageviews:/test-page:{$expectedDay}")
            ));

        $this->redis->expects($this->once())
            ->method('zIncrBy')
            ->with('pageviews:zset', 1, '/test-page');

        $this->redis->expects($this->once())
            ->method('sAdd')
            ->with("visitors:unique:{$expectedDay}", $this->isString());

        $this->redis->expects($this->once())
            ->method('exec');

        // Act
        $this->tracker->track($request);
    }

    public function testTrackTransactionOrder(): void
    {
        // Arrange
        $request = Request::create('/test-page');

        $expectedDay = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');
        $expectedTtl = VisitTrackerInterface::TTL_DAYS * 86400;

        // Mock Redis operations
        $this->redis->expects($this->once())
            ->method('multi');

        $this->redis->expects($this->once())
            ->method('zAdd')
            ->with("visits:{$expectedDay}", $this->isFloat(), $this->isString());

        $this->redis->expects($this->exactly(4))
            ->method('expire')
            ->with($this->logicalOr(
                $this->identicalTo("visits:{$expectedDay}"),
                $this->identicalTo("pageviews:total:{$expectedDay}"),
                $this->identicalTo("pageviews:/test-page:{$expectedDay}"),
                $this->identicalTo("visitors:unique:{$expectedDay}")
            ), $expectedTtl);

        $this->redis->expects($this->exactly(2))
            ->method('incr')
            ->with($this->logicalOr(
                $this->identicalTo("pageviews:total:{$expectedDay}"),
                $this->identicalTo("pageviews:/test-page:{$expectedDay}")
            ));

        $this->redis->expects($this->once())
            ->method('zIncrBy')
            ->with('pageviews:zset', 1, '/test-page');

        $this->redis->expects($this->once())
            ->method('sAdd')
            ->with("visitors:unique:{$expectedDay}", $this->isString());

        $this->redis->expects($this->once())
            ->method('exec');

        // Act
        $this->tracker->track($request);
    }

    public function testTrackWithRealDateTime(): void
    {
        // Arrange
        $request = Request::create('/test-page');

        $expectedDay = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');
        $expectedTtl = VisitTrackerInterface::TTL_DAYS * 86400;

        // Mock Redis operations
        $this->redis->expects($this->once())
            ->method('multi');

        $this->redis->expects($this->once())
            ->method('zAdd')
            ->with("visits:{$expectedDay}", $this->isFloat(), $this->isString());

        $this->redis->expects($this->exactly(4))
            ->method('expire')
            ->with($this->logicalOr(
                $this->identicalTo("visits:{$expectedDay}"),
                $this->identicalTo("pageviews:total:{$expectedDay}"),
                $this->identicalTo("pageviews:/test-page:{$expectedDay}"),
                $this->identicalTo("visitors:unique:{$expectedDay}")
            ), $expectedTtl);

        $this->redis->expects($this->exactly(2))
            ->method('incr')
            ->with($this->logicalOr(
                $this->identicalTo("pageviews:total:{$expectedDay}"),
                $this->identicalTo("pageviews:/test-page:{$expectedDay}")
            ));

        $this->redis->expects($this->once())
            ->method('zIncrBy')
            ->with('pageviews:zset', 1, '/test-page');

        $this->redis->expects($this->once())
            ->method('sAdd')
            ->with("visitors:unique:{$expectedDay}", $this->isString());

        $this->redis->expects($this->once())
            ->method('exec');

        // Act
        $this->tracker->track($request);
    }
}

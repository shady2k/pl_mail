<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Entity\Mail\Account;
use App\Service\Gmail\GmailQuotaPacer;
use App\Service\Mail\GmailApiClient;
use App\Service\OAuth\OAuthTokenManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GmailSafeBatchLoggingTest extends TestCase
{
    public function testLocalCooldownIsLoggedWithoutAnHttpRequest(): void
    {
        $account = new Account();
        $pacer = new GmailQuotaPacer(new MockClock());
        $pacer->throttle($account, 60);
        $tokens = $this->createStub(OAuthTokenManager::class);
        $http = new MockHttpClient(static function (): never { self::fail('Local cooldown must not call Google'); });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('notice')->with('Gmail request held locally', self::callback(static function (array $context): bool {
            self::assertSame('local', $context['source']);
            self::assertSame('localCooldown', $context['reason']);
            self::assertSame('users.getProfile', $context['method']);
            return true;
        }));
        $this->expectException(\App\Domain\Exception\GmailThrottledException::class);
        (new GmailApiClient($http, $tokens, $pacer, $logger))->getProfile($account);
    }

    public function testInnerFailuresLogOnlyAggregateStatusAndAllowlistedReason(): void
    {
        $token = $this->createStub(OAuthTokenManager::class);
        $token->method('getValidAccessToken')->willReturn('synthetic-token');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Gmail remote batch failures', self::callback(static function (array $context): bool {
            self::assertSame(['403:userRateLimitExceeded' => 1, '429:other' => 1], $context['failures']);
            self::assertSame('remote', $context['source']);
            self::assertSame('messages.get', $context['method']);
            $encoded = json_encode($context);
            self::assertStringNotContainsString('private-id', $encoded);
            self::assertStringNotContainsString('private-reason', $encoded);
            self::assertStringNotContainsString('synthetic-token', $encoded);
            return true;
        }));
        $body = "--response\r\nContent-ID: <response-private-id-a>\r\n\r\nHTTP/1.1 403 Forbidden\r\n\r\n{\"error\":{\"errors\":[{\"reason\":\"userRateLimitExceeded\"}]}}\r\n"
            . "--response\r\nContent-ID: <response-private-id-b>\r\n\r\nHTTP/1.1 429 Too Many Requests\r\n\r\n{\"error\":{\"errors\":[{\"reason\":\"private-reason\"}]}}\r\n--response--\r\n";
        $client = new GmailApiClient(new MockHttpClient([new MockResponse($body)]), $token, new GmailQuotaPacer(new MockClock()), $logger);
        $client->getMessages(new Account(), ['private-id-a', 'private-id-b']);
    }
}

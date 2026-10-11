<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\Ai\ConnectionHeaders;
use App\Domain\DTO\Ai\AiConnection;
use App\Infrastructure\Messaging\Message\ClassifyMailMessage;
use App\Infrastructure\Messaging\Middleware\AiTaskMiddleware;
use App\Infrastructure\Messaging\Stamp\AiTaskStamp;
use App\Service\Ai\AiTaskContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class TaskSessionHeadersTest extends TestCase
{
    public function testLegacyBackfillEnvelopeWithoutNewPropertiesReceivesAStableStamp(): void
    {
        $class = \App\Infrastructure\Messaging\Message\BackfillEmbeddingsMessage::class;
        $message = unserialize('O:' . strlen($class) . ':"' . $class . '":2:{s:6:"userId";i:123;s:14:"afterMessageId";N;}');
        $context = new AiTaskContext('synthetic installation key');
        $next = $this->createStub(MiddlewareInterface::class);
        $next->method('handle')->willReturnCallback(static fn (Envelope $envelope): Envelope => $envelope);
        $stack = $this->createStub(StackInterface::class);
        $stack->method('next')->willReturn($next);
        $middleware = new AiTaskMiddleware($context);
        $envelope = new Envelope($message, [new \Symfony\Component\Messenger\Stamp\TransportMessageIdStamp('123')]);
        $first = $middleware->handle($envelope, $stack);
        $second = $middleware->handle($envelope, $stack);
        self::assertSame($first->last(AiTaskStamp::class)->id, $second->last(AiTaskStamp::class)->id);
    }

    public function testForkSessionHeaderFormatSurvivesAndResolvesPerTask(): void
    {
        $headers = ConnectionHeaders::decode('{"X-Task":{"mode":"session"},"X-Tenant":"synthetic"}');
        $connection = new AiConnection('https://synthetic.invalid/v1', headers: $headers);
        self::assertSame('task-a', $connection->requestHeaders('task-a')['X-Task']);
        self::assertSame('task-a', $connection->requestHeaders('task-a')['X-Task']);
        self::assertSame('task-b', $connection->requestHeaders('task-b')['X-Task']);
        self::assertSame('synthetic', $connection->requestHeaders('task-a')['X-Tenant']);
    }

    public function testSerializedRetryKeepsTheTaskStampAndRestoresOuterContext(): void
    {
        $context = new AiTaskContext('synthetic installation key');
        $context->current = 'outer';
        $seen = [];
        $next = $this->createStub(MiddlewareInterface::class);
        $next->method('handle')->willReturnCallback(static function (Envelope $envelope) use ($context, &$seen): Envelope {
            $seen[] = $context->current;
            return $envelope;
        });
        $stack = $this->createStub(StackInterface::class);
        $stack->method('next')->willReturn($next);
        $middleware = new AiTaskMiddleware($context);
        $first = $middleware->handle(new Envelope(new ClassifyMailMessage([123])), $stack);
        self::assertInstanceOf(AiTaskStamp::class, $first->last(AiTaskStamp::class));
        $retry = unserialize(serialize($first));
        $middleware->handle($retry, $stack);
        self::assertSame($seen[0], $seen[1]);
        self::assertNotSame('outer', $seen[0]);
        self::assertSame('outer', $context->current);
    }
}

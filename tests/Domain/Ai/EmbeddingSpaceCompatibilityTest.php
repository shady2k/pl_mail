<?php

declare(strict_types=1);

namespace App\Tests\Domain\Ai;

use App\Entity\Ai\AiSettings;
use PHPUnit\Framework\TestCase;

final class EmbeddingSpaceCompatibilityTest extends TestCase
{
    public function testSummaryKeyKeepsForkIdentityAndChangesWithEndpoint(): void
    {
        $settings = new AiSettings();
        $settings->baseUrl = 'http://synthetic.invalid/';
        $settings->chatModel = 'synthetic-model';
        $old = $settings->generationIdentity();
        self::assertSame(hash('sha256', "ollama\0http://synthetic.invalid\0synthetic-model"), $old);
        $settings->apiToken = 'synthetic-rotated-token';
        self::assertSame($old, $settings->generationIdentity());
        $settings->baseUrl = 'http://different.invalid';
        self::assertNotSame($old, $settings->generationIdentity());
    }

    public function testEndpointChangesRequireApprovalButTokenRotationKeepsTheSpace(): void
    {
        $settings = new AiSettings();
        $settings->baseUrl = 'http://synthetic.invalid';
        $settings->embeddingModel = 'synthetic-model';
        $settings->embeddingApprovedSpace = $settings->embeddingSpace();
        $old = $settings->embeddingSpace();
        $settings->apiToken = 'synthetic rotated token';
        self::assertSame($old, $settings->embeddingSpace());
        self::assertTrue($settings->embeddingSpaceApproved());
        $settings->baseUrl = 'http://different.invalid';
        self::assertNotSame($old, $settings->embeddingSpace());
        self::assertFalse($settings->embeddingSpaceApproved());
    }

    public function testChangedWidthIsolatesTheOldVectorsAndRequiresApproval(): void
    {
        $settings = new AiSettings();
        $settings->embeddingDimensions = 768;
        $old = $settings->embeddingSpace();
        self::assertTrue($settings->acceptsEmbeddingWidth(768));
        self::assertFalse($settings->acceptsEmbeddingWidth(1024));
        self::assertTrue($settings->embeddingReindexRequired);
        self::assertNotSame($old, $settings->embeddingSpace());
    }
}

<?php

declare(strict_types=1);

namespace LuziApi\Tests\OrderTracking;

use LuziApi\OrderTracking\Infrastructure\WordPress\RandomTokenGenerator;
use LuziApi\OrderTracking\Infrastructure\WordPress\WordPressAccessFingerprint;
use PHPUnit\Framework\TestCase;

final class OrderTrackingInfrastructureTest extends TestCase
{
    public function testGeneratedTokensContain256BitsAndAreUrlSafe(): void
    {
        $generator = new RandomTokenGenerator();
        $first = $generator->generate();
        $second = $generator->generate();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $first);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $second);
        self::assertNotSame($first, $second);
    }

    public function testFingerprintsAreDeterministicAndNeverContainThePersonalValue(): void
    {
        $fingerprints = new WordPressAccessFingerprint('test-secret');
        $email = 'Client@Example.com';

        self::assertSame($fingerprints->subject($email), $fingerprints->subject(' client@example.COM '));
        self::assertStringNotContainsString('client', $fingerprints->subject($email));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprints->token('secret-token'));
    }
}

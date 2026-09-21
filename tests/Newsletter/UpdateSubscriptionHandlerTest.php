<?php

declare(strict_types=1);

namespace LuziApi\Tests\Newsletter;

use InvalidArgumentException;
use LuziApi\Newsletter\Application\Command\UpdateSubscription\UpdateSubscriptionCommand;
use LuziApi\Newsletter\Application\Command\UpdateSubscription\UpdateSubscriptionHandler;
use LuziApi\Newsletter\Domain\Gateway\SubscriberWriter;
use LuziApi\Newsletter\Domain\SubscriptionStatus;
use PHPUnit\Framework\TestCase;

final class UpdateSubscriptionHandlerTest extends TestCase
{
    public function testItSubscribesBothChannels(): void
    {
        $writer = new RecordingSubscriberWriter();
        $confirmed = (new UpdateSubscriptionHandler($writer))->handle(
            new UpdateSubscriptionCommand('helene@example.test', '+33631437046', true, true, 'Hélène', 'Dupont'),
        );

        self::assertSame(1, $writer->calls);
        self::assertSame('helene@example.test', $writer->email);
        self::assertSame('+33631437046', $writer->phone);
        self::assertTrue($writer->emailSubscribed);
        self::assertTrue($writer->smsSubscribed);
        self::assertSame('Hélène', $writer->firstName);
        self::assertSame('Dupont', $writer->lastName);
        // Le handler retourne l'état confirmé (relecture) renvoyé par le writer.
        self::assertTrue($confirmed->emailSubscribed);
        self::assertTrue($confirmed->smsSubscribed);
    }

    public function testUncheckingUnsubscribes(): void
    {
        $writer = new RecordingSubscriberWriter();
        (new UpdateSubscriptionHandler($writer))->handle(
            new UpdateSubscriptionCommand('helene@example.test', '', false, false),
        );

        self::assertSame(1, $writer->calls);
        self::assertFalse($writer->emailSubscribed);
        self::assertFalse($writer->smsSubscribed);
    }

    public function testItRejectsAnInvalidEmailAndDoesNotWrite(): void
    {
        $writer = new RecordingSubscriberWriter();
        try {
            (new UpdateSubscriptionHandler($writer))->handle(
                new UpdateSubscriptionCommand('not-an-email', '', true, false),
            );
            self::fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $writer->calls, 'Aucune écriture ne doit partir avec un e-mail invalide.');
        }
    }

    public function testItRejectsSmsWithoutAPhone(): void
    {
        $writer = new RecordingSubscriberWriter();
        $this->expectException(InvalidArgumentException::class);
        try {
            (new UpdateSubscriptionHandler($writer))->handle(
                new UpdateSubscriptionCommand('helene@example.test', '', true, true),
            );
        } finally {
            self::assertSame(0, $writer->calls);
        }
    }
}

final class RecordingSubscriberWriter implements SubscriberWriter
{
    public int $calls = 0;
    public string $email = '';
    public string $phone = '';
    public bool $emailSubscribed = false;
    public bool $smsSubscribed = false;
    public string $firstName = '';
    public string $lastName = '';

    public function isConfigured(): bool
    {
        return true;
    }

    public function setSubscription(string $email, string $phone, bool $emailSubscribed, bool $smsSubscribed, string $firstName = '', string $lastName = ''): SubscriptionStatus
    {
        ++$this->calls;
        $this->email = $email;
        $this->phone = $phone;
        $this->emailSubscribed = $emailSubscribed;
        $this->smsSubscribed = $smsSubscribed;
        $this->firstName = $firstName;
        $this->lastName = $lastName;

        return new SubscriptionStatus($emailSubscribed, $smsSubscribed);
    }
}

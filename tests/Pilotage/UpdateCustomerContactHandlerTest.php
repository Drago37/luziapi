<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use InvalidArgumentException;
use LuziApi\Pilotage\Application\Command\UpdateCustomerContact\UpdateCustomerContactCommand;
use LuziApi\Pilotage\Application\Command\UpdateCustomerContact\UpdateCustomerContactHandler;
use LuziApi\Pilotage\Domain\Customer\CustomerContactWriter;
use PHPUnit\Framework\TestCase;

final class UpdateCustomerContactHandlerTest extends TestCase
{
    public function testItWritesTheContactToTheCustomerOrders(): void
    {
        $writer = new RecordingContactWriter();
        $handler = new UpdateCustomerContactHandler($writer);

        $updated = $handler->handle(new UpdateCustomerContactCommand(
            [11, 12, 13],
            '',
            '',
            'helene@example.test',
            '06 31 43 70 46',
            'Luzillé',
        ));

        self::assertSame(3, $updated);
        self::assertSame([11, 12, 13], $writer->orderIds);
        self::assertSame('helene@example.test', $writer->email);
        self::assertSame('06 31 43 70 46', $writer->phone);
        self::assertSame('Luzillé', $writer->city);
    }

    public function testItRejectsAnEmptyOrderSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UpdateCustomerContactHandler(new RecordingContactWriter()))
            ->handle(new UpdateCustomerContactCommand([], '', '', 'x@example.test', '', ''));
    }

    public function testItRejectsAnInvalidEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UpdateCustomerContactHandler(new RecordingContactWriter()))
            ->handle(new UpdateCustomerContactCommand([1], '', '', 'not-an-email', '', ''));
    }

    public function testItAllowsAnEmptyEmail(): void
    {
        // e-mail vide = champ non modifié (le writer conserve l'existant).
        $writer = new RecordingContactWriter();
        (new UpdateCustomerContactHandler($writer))
            ->handle(new UpdateCustomerContactCommand([1], '', '', '', '0600000000', ''));

        self::assertSame('', $writer->email);
        self::assertSame('0600000000', $writer->phone);
    }
}

final class RecordingContactWriter implements CustomerContactWriter
{
    /** @var list<int> */
    public array $orderIds = [];
    public string $email = '';
    public string $phone = '';
    public string $city = '';

    public function update(array $orderIds, string $firstName, string $lastName, string $email, string $phone, string $city): int
    {
        $this->orderIds = $orderIds;
        $this->email = $email;
        $this->phone = $phone;
        $this->city = $city;

        return count($orderIds);
    }
}

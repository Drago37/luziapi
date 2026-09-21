<?php

declare(strict_types=1);

namespace LuziApi\Tests\Shop;

use LuziApi\Shop\Domain\Legal\WithdrawalRequest;
use PHPUnit\Framework\TestCase;

final class WithdrawalRequestTest extends TestCase
{
    public function testValidRequestHasNoFieldErrors(): void
    {
        $request = new WithdrawalRequest('1636', 'client@example.test', 'all', '');

        self::assertSame([], $request->fieldErrors(true));
    }

    public function testMissingOrderNumberOrEmailIsReported(): void
    {
        $request = new WithdrawalRequest('', '', 'all', '');

        $errors = $request->fieldErrors(false);
        self::assertContains('Renseignez le numéro de commande et l’adresse e-mail utilisée lors de l’achat.', $errors);
    }

    public function testInvalidEmailIsReportedOnlyWhenPresent(): void
    {
        $withEmail = new WithdrawalRequest('1636', 'not-an-email', 'all', '');
        self::assertContains('L’adresse e-mail renseignée n’est pas valide.', $withEmail->fieldErrors(false));

        // E-mail vide : c'est l'erreur « champ requis » qui s'applique, pas « format invalide ».
        $withoutEmail = new WithdrawalRequest('1636', '', 'all', '');
        self::assertNotContains('L’adresse e-mail renseignée n’est pas valide.', $withoutEmail->fieldErrors(false));
    }

    public function testPartialScopeRequiresDetails(): void
    {
        $missing = new WithdrawalRequest('1636', 'client@example.test', 'part', '');
        self::assertContains('Précisez les produits concernés par votre demande.', $missing->fieldErrors(true));

        $given = new WithdrawalRequest('1636', 'client@example.test', 'part', 'Deux pots de miel');
        self::assertSame([], $given->fieldErrors(true));
    }

    public function testScopeLabel(): void
    {
        self::assertSame(
            'La totalité de la commande',
            (new WithdrawalRequest('1636', 'c@example.test', 'all', ''))->scopeLabel(),
        );
        self::assertSame(
            'Une partie de la commande : Deux pots',
            (new WithdrawalRequest('1636', 'c@example.test', 'part', 'Deux pots'))->scopeLabel(),
        );
    }
}

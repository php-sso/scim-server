<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Unit\ScimPatch;

use PhpSso\ScimServer\ScimPatch;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(ScimPatch::class, 'emailFrom')]
final class EmailFromTest extends TestCase
{
    public function testThePrimaryAddressWinsOverTheFirst(): void
    {
        // Arrange
        $emails = [['value' => 'first@acme.com'], ['value' => 'main@acme.com', 'primary' => true]];

        // Act
        $address = ScimPatch::emailFrom($emails);

        // Assert
        self::assertSame('main@acme.com', $address);
    }

    public function testWithoutAPrimaryTheFirstAddressIsUsed(): void
    {
        // Arrange
        $emails = [['value' => 'first@acme.com'], ['value' => 'second@acme.com']];

        // Act
        $address = ScimPatch::emailFrom($emails);

        // Assert
        self::assertSame('first@acme.com', $address);
    }

    public function testAPrimaryFlagSentAsTheTextFalseIsNotPrimary(): void
    {
        // Arrange
        $emails = [['value' => 'first@acme.com'], ['value' => 'other@acme.com', 'primary' => 'false']];

        // Act
        $address = ScimPatch::emailFrom($emails);

        // Assert
        self::assertSame('first@acme.com', $address);
    }

    public function testPlainTextAndASingleObjectAreAccepted(): void
    {
        // Arrange
        $text = '  alice@acme.com ';
        $object = ['value' => 'bob@acme.com'];

        // Act
        $fromText = ScimPatch::emailFrom($text);
        $fromObject = ScimPatch::emailFrom($object);

        // Assert
        self::assertSame(['alice@acme.com', 'bob@acme.com'], [$fromText, $fromObject]);
    }

    public function testSomethingThatIsNotAnAddressGivesNothing(): void
    {
        // Arrange
        $values = [42, null, '   ', [['value' => '']], []];

        // Act
        $addresses = array_map(ScimPatch::emailFrom(...), $values);

        // Assert
        self::assertSame([null, null, null, null, null], $addresses);
    }
}

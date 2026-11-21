<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Unit\Library;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * This is the php-sso/scim-server library. It may only depend on PHP itself and PSR
 * interfaces: the application is plugged in through the interfaces in Contract.
 */
#[CoversNothing]
final class DependenciesTest extends TestCase
{
    public function testTheScimServerDependsOnNothingFromTheApplication(): void
    {
        // Arrange
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 5) . '/src', \FilesystemIterator::SKIP_DOTS));
        $forbidden = '/(?<![A-Za-z0-9_])(PhpSso\\\\(?!ScimServer[\\\\;])|Laminas\\\\|Twig\\\\|OneLogin\\\\|Firebase\\\\|\\\\?PDO(Exception)?\b)/';
        $found = [];

        // Act
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $lines = file($file->getPathname());
            self::assertIsArray($lines);
            foreach ($lines as $number => $line) {
                if (preg_match($forbidden, $line, $m) === 1) {
                    $found[] = basename($file->getPathname()) . ':' . ($number + 1) . ' uses ' . trim($m[0], '\\');
                }
            }
        }

        // Assert
        self::assertSame([], $found);
    }
}

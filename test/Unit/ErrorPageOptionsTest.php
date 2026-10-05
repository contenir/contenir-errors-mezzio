<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Test\Unit;

use Contenir\Errors\Mezzio\ErrorPageOptions;
use Contenir\Errors\Mezzio\Exception\InvalidConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ErrorPageOptionsTest extends TestCase
{
    public function testAppliesTheDefaultsToAnEmptyConfig(): void
    {
        self::assertEquals(
            new ErrorPageOptions('contenir-errors::fault', null, false),
            ErrorPageOptions::fromConfig([]),
        );
    }

    public function testReadsEveryKeyFromConfig(): void
    {
        self::assertEquals(
            new ErrorPageOptions('error::fault', 'layout::error', true),
            ErrorPageOptions::fromConfig([
                'view_template' => 'error::fault',
                'layout'        => 'layout::error',
                'debug'         => true,
            ]),
        );
    }

    public function testTreatsANullLayoutAsTheRendererDefault(): void
    {
        self::assertNull(ErrorPageOptions::fromConfig(['layout' => null])->layout);
    }

    public function testAcceptsADisabledLayout(): void
    {
        self::assertFalse(ErrorPageOptions::fromConfig(['layout' => false])->layout);
    }

    /**
     * @param array<string, mixed> $errors
     */
    #[DataProvider('invalidConfigProvider')]
    public function testRejectsAValueOfTheWrongType(array $errors, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        ErrorPageOptions::fromConfig($errors);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigProvider(): array
    {
        return [
            'view template is empty'      => [['view_template' => ''], 'config[errors][view_template] must be'],
            'view template is not string' => [['view_template' => 42], 'config[errors][view_template] must be'],
            'layout is true'              => [['layout' => true], 'config[errors][layout] must be'],
            'layout is empty'             => [['layout' => ''], 'config[errors][layout] must be'],
            'layout is not a string'      => [['layout' => 42], 'config[errors][layout] must be'],
            'debug is a string'           => [['debug' => 'true'], 'config[errors][debug] must be a boolean'],
            'debug is an integer'         => [['debug' => 1], 'config[errors][debug] must be a boolean'],
        ];
    }
}

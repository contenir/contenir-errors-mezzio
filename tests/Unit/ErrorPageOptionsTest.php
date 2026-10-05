<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Tests\Unit;

use Contenir\Errors\Mezzio\ErrorPageOptions;
use Contenir\Errors\Mezzio\Exception\InvalidConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ErrorPageOptionsTest extends TestCase
{
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

    #[Test]
    public function acceptsADisabledLayout(): void
    {
        static::assertFalse(ErrorPageOptions::fromConfig(['layout' => false])->layout);
    }

    #[Test]
    public function appliesTheDefaultsToAnEmptyConfig(): void
    {
        static::assertEquals(
            new ErrorPageOptions('contenir-errors::fault', null, false),
            ErrorPageOptions::fromConfig([]),
        );
    }

    #[Test]
    public function readsEveryKeyFromConfig(): void
    {
        static::assertEquals(
            new ErrorPageOptions('error::fault', 'layout::error', true),
            ErrorPageOptions::fromConfig([
                'view_template' => 'error::fault',
                'layout'        => 'layout::error',
                'debug'         => true,
            ]),
        );
    }

    /**
     * @param array<string, mixed> $errors
     */
    #[Test]
    #[DataProvider('invalidConfigProvider')]
    public function rejectsAValueOfTheWrongType(array $errors, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        ErrorPageOptions::fromConfig($errors);
    }

    #[Test]
    public function treatsANullLayoutAsTheRendererDefault(): void
    {
        static::assertNull(ErrorPageOptions::fromConfig(['layout' => null])->layout);
    }
}

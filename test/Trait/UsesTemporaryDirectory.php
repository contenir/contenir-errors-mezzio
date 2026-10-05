<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Test\Trait;

use function array_diff;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;

/**
 * Creates a throwaway directory for tests that read files, and removes it and
 * everything written to it again.
 */
trait UsesTemporaryDirectory
{
    private string $temporaryDirectory;

    protected function setUpTemporaryDirectory(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/' . uniqid('contenir-errors-mezzio-', more_entropy: true);
        mkdir($this->temporaryDirectory);
    }

    protected function tearDownTemporaryDirectory(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
    }

    /**
     * Writes a PHP config file returning $config into the temporary directory.
     *
     * @param array<array-key, mixed> $config
     */
    protected function writeConfigFile(string $name, array $config): string
    {
        $path = "{$this->temporaryDirectory}/{$name}";
        file_put_contents($path, '<?php return ' . var_export($config, return: true) . ';');

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        foreach (array_diff(false === $entries ? [] : $entries, ['.', '..']) as $entry) {
            $path = "{$directory}/{$entry}";
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}

<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync\Test\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A directory for one test, removed after it.
 */
trait TemporaryDirectory
{
    /** @var string */
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/module-state-sync-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        // Resolved, because macOS' temporary directory is behind a symlink.
        $this->directory = (string) realpath($this->directory);
    }

    protected function tearDown(): void
    {
        $this->clear();
        rmdir($this->directory);
    }

    /**
     * Remove everything in the directory.
     */
    private function clear(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $path = $file->getPathname();
            is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
        }
    }

    /**
     * Write a file, and the directories it is in.
     *
     * @param string $path relative to the directory
     * @param string $contents
     */
    private function writeFile(string $path, string $contents): string
    {
        $file = $this->directory . '/' . $path;
        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0o777, true);
        }
        file_put_contents($file, $contents);

        return $file;
    }

    /**
     * Write a module directory and return its path.
     *
     * @param string $name
     * @param string[] $sequence
     */
    private function writeModule(string $name, array $sequence = []): string
    {
        $nodes = '';
        foreach ($sequence as $related) {
            $nodes .= "<module name=\"$related\"/>";
        }
        $sequenceNode = $sequence ? "<sequence>$nodes</sequence>" : '';
        $xml = "<config><module name=\"$name\">$sequenceNode</module></config>";

        // The name is hashed: names that differ only by case would share a directory on macOS.
        return $this->writeModuleXml(hash('sha256', $name), $xml);
    }

    /**
     * Write a module directory with the given module.xml and return its path.
     *
     * @param string $path relative to the directory
     * @param string $xml
     */
    private function writeModuleXml(string $path, string $xml): string
    {
        return \dirname($this->writeFile("$path/etc/module.xml", "<?xml version=\"1.0\"?>\n$xml\n"), 2);
    }
}

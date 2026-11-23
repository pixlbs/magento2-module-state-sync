<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync;

use PixelLabs\ModuleStateSync\Exception\SequenceException;

/**
 * Magento's app/etc/config.php.
 */
class ConfigFile
{
    private const INDENT = '    ';

    /** @var array<array-key, mixed> */
    private array $data;

    /**
     * Load the file.
     *
     * @param string $path
     */
    public function __construct(private readonly string $path)
    {
        if (!is_file($path)) {
            throw new SequenceException("$path does not exist.");
        }
        // Evaluated in its own scope.
        // phpcs:ignore Magento2.Security.IncludeFile
        $data = (static fn (): mixed => require $path)();
        if (!\is_array($data) || !\is_array($data['modules'] ?? null) || $data['modules'] === []) {
            throw new SequenceException("$path has no modules.");
        }
        $this->data = $data;
    }

    /**
     * Get the module states.
     *
     * @return array<string,mixed>
     */
    public function getModules(): array
    {
        return $this->data['modules'];
    }

    /**
     * Replace the module states and write the file; every other section keeps its value.
     *
     * The file is replaced atomically: a request that reads it meanwhile gets the old or the new version. When the path
     * is a symlink, its target is replaced and the link stays.
     *
     * @param array<string,int> $modules
     */
    public function saveModules(array $modules): void
    {
        $this->data['modules'] = $modules;
        $target = realpath($this->path);
        $mode = fileperms($this->path);
        if ($target === false || $mode === false) {
            throw new SequenceException("Cannot write {$this->path}.");
        }

        $temporary = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $contents = $this->format($this->data);
        $written = file_put_contents($temporary, $contents) === \strlen($contents)
            && chmod($temporary, $mode & 0o777)
            && rename($temporary, $target);
        if (!$written) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw new SequenceException("Cannot write $target.");
        }
    }

    /**
     * Format the data as Magento's deployment config writer does.
     *
     * @param array<array-key,mixed> $data
     */
    public function format(array $data): string
    {
        return "<?php\nreturn " . $this->export($data, 1) . ";\n";
    }

    /**
     * Export a value with the short array syntax, like Magento's PhpFormatter.
     *
     * @param mixed $value
     * @param int $depth
     */
    private function export(mixed $value, int $depth): string
    {
        if ($value === null) {
            return 'null';
        }
        if (!\is_array($value)) {
            return var_export($value, true);
        }

        // An empty array isn't a list to Magento; it makes no difference to the output.
        $isList = array_is_list($value);
        $items = [];
        foreach ($value as $key => $item) {
            $items[] = str_repeat(self::INDENT, $depth)
                . ($isList ? '' : var_export($key, true) . ' => ')
                . $this->export($item, $depth + 1);
        }

        return \sprintf("[\n%s\n%s]", implode(",\n", $items), str_repeat(self::INDENT, $depth - 1));
    }
}

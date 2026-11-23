<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync;

use PixelLabs\ModuleStateSync\Exception\SequenceException;

/**
 * Loads the file with the module states that must hold, whatever config.php says.
 */
class EnforcedModules
{
    private const STATES = ['enabled' => 1, 'disabled' => 0];

    /**
     * Load and validate the file.
     *
     * @param string $file
     */
    public function load(string $file): EnforcedRules
    {
        // Evaluated in its own scope.
        // phpcs:ignore Magento2.Security.IncludeFile
        $data = (static fn (): mixed => require $file)();
        if (!\is_array($data)) {
            throw new SequenceException("$file must return an array with 'enabled' and 'disabled'.");
        }

        $rules = [];
        foreach ($data as $key => $entries) {
            if (!isset(self::STATES[$key])) {
                throw new SequenceException("$file: unknown key '$key', use 'enabled' and 'disabled'.");
            }
            if (!\is_array($entries) || !array_is_list($entries)) {
                throw new SequenceException("$file: '$key' must be a list of module names and patterns.");
            }
            foreach ($entries as $entry) {
                if (!\is_string($entry)) {
                    throw new SequenceException("$file: '$key' must contain only strings.");
                }
                try {
                    $rules[] = $this->createRules($entry, self::STATES[$key]);
                } catch (SequenceException $exception) {
                    throw new SequenceException("$file: '$key': {$exception->getMessage()}", 0, $exception);
                }
            }
        }

        return new EnforcedRules(array_merge(...$rules));
    }

    /**
     * Create the rules for an entry: one for each combination of its {a,b} alternatives.
     *
     * @param string $entry
     * @param int $state
     * @return list<EnforcedRule>
     */
    private function createRules(string $entry, int $state): array
    {
        // Braces in a regular expression are quantifiers.
        if (str_starts_with($entry, '/') || !preg_match('/^([^{}]*)\{([^{}]*)\}(.*)$/Ds', $entry, $match)) {
            return [new EnforcedRule($entry, $state)];
        }

        $rules = [];
        foreach (explode(',', $match[2]) as $alternative) {
            $rules[] = $this->createRules($match[1] . trim($alternative) . $match[3], $state);
        }

        return array_merge(...$rules);
    }
}

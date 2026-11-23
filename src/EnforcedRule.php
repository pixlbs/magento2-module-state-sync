<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync;

use PixelLabs\ModuleStateSync\Exception\SequenceException;

/**
 * One entry of the enforced modules file: a module name, a name with wildcards or a regular expression.
 */
class EnforcedRule
{
    /** @var string|null null for an exact name */
    private readonly ?string $regex;

    /**
     * Parse the entry.
     *
     * @param string $entry
     * @param int $state 1 (enabled) or 0 (disabled)
     */
    public function __construct(public readonly string $entry, public readonly int $state)
    {
        if (str_starts_with($entry, '/')) {
            $this->assertRegex($entry);
            $this->regex = $entry;
        } elseif (preg_match('/^[A-Za-z0-9_*]+$/D', $entry)) {
            $this->regex = str_contains($entry, '*') ? '/^' . str_replace('*', '.*', $entry) . '$/D' : null;
        } else {
            throw new SequenceException("'$entry' is not a module name, wildcard or /regex/.");
        }
    }

    /**
     * Whether this is the name of one module.
     */
    public function isExact(): bool
    {
        return $this->regex === null;
    }

    /**
     * Whether the rule applies to a module.
     *
     * @param string $name
     */
    public function matches(string $name): bool
    {
        if ($this->regex === null) {
            return $this->entry === $name;
        }

        $result = preg_match($this->regex, $name);
        if ($result === false) {
            throw new SequenceException("{$this->entry} fails on $name: " . preg_last_error_msg() . '.');
        }

        return $result === 1;
    }

    /**
     * Fail on an invalid regular expression.
     *
     * @param string $regex
     */
    private function assertRegex(string $regex): void
    {
        $error = null;
        set_error_handler(static function (int $code, string $message) use (&$error): bool {
            $error = preg_replace('/^preg_match\(\): /', '', $message);
            return true;
        });
        try {
            $valid = preg_match($regex, '') !== false;
        } finally {
            restore_error_handler();
        }
        if (!$valid) {
            throw new SequenceException("'$regex' is not a valid regular expression: " . ($error ?? 'unknown error'));
        }
    }
}

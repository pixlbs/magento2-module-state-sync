<?php

declare(strict_types=1);

namespace Magento\Framework\Xml;

/**
 * Stands in for the factory that Magento generates, which only exists in an installation.
 */
class ParserFactory
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data = []): Parser
    {
        return new Parser();
    }
}

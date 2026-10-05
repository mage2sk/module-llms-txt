<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

interface SectionInterface
{
    public function render(int $storeId): array;
}

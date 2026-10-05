<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\Index;

use Panth\LlmsTxt\Model\Index\Entry;
use PHPUnit\Framework\TestCase;

class EntryTest extends TestCase
{
    public function testConstructorScoreIsClampedToUnitRange(): void
    {
        $this->assertSame(1.0, (new Entry('u', 'l', 't', 4.2))->getScore());
        $this->assertSame(0.0, (new Entry('u', 'l', 't', -1.0))->getScore());
        $this->assertSame(0.5, (new Entry('u', 'l', 't'))->getScore());
    }

    public function testSetScoreIsClampedAndOtherFieldsArePreserved(): void
    {
        $entry = new Entry('https://s/x', 'X', 'cms', 0.3, 'sum', ['k' => 'v']);

        $entry->setScore(9.0);
        $this->assertSame(1.0, $entry->getScore());
        $entry->setScore(-0.1);
        $this->assertSame(0.0, $entry->getScore());
        $entry->setScore(0.42);
        $this->assertSame(0.42, $entry->getScore());

        $this->assertSame('https://s/x', $entry->getUrl());
        $this->assertSame('X', $entry->getLabel());
        $this->assertSame('cms', $entry->getType());
        $this->assertSame('sum', $entry->getSummary());
        $this->assertSame(['k' => 'v'], $entry->getMetadata());
    }
}

<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\Ranker;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Panth\LlmsTxt\Api\Data\IndexEntryInterface;
use Panth\LlmsTxt\Model\Index\Entry;
use Panth\LlmsTxt\Model\Ranker\WeightedRanker;
use PHPUnit\Framework\TestCase;

class WeightedRankerTest extends TestCase
{
    private function ranker(array $config = []): WeightedRanker
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $config[$path] ?? null
        );
        return new WeightedRanker($scopeConfig);
    }

    private function entry(string $url, string $type, array $meta = []): Entry
    {
        return new Entry($url, $url, $type, 0.5, '', $meta);
    }

    private function urls(array $entries): array
    {
        return array_map(static fn (IndexEntryInterface $e): string => $e->getUrl(), $entries);
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        $this->assertSame([], $this->ranker()->rank([], 1));
    }

    public function testDefaultTypeWeightsOrderEntries(): void
    {
        $ranked = $this->ranker()->rank([
            $this->entry('https://s/p', IndexEntryInterface::TYPE_PRODUCT),
            $this->entry('https://s/', IndexEntryInterface::TYPE_HOMEPAGE),
            $this->entry('https://s/c', IndexEntryInterface::TYPE_CATEGORY),
        ], 1);

        $this->assertSame(['https://s/', 'https://s/c', 'https://s/p'], $this->urls($ranked));
        $this->assertSame(1.0, $ranked[0]->getScore());
        $this->assertSame(0.75, $ranked[1]->getScore());
        $this->assertSame(0.55, $ranked[2]->getScore());
    }

    public function testUnknownTypeFallsBackToHalf(): void
    {
        $ranked = $this->ranker()->rank([$this->entry('https://s/x', 'mystery')], 1);

        $this->assertSame(0.5, $ranked[0]->getScore());
    }

    public function testConfiguredTypeWeightsOverrideDefaultsAndIgnoreJunkLines(): void
    {
        $ranker = $this->ranker([
            WeightedRanker::XML_TYPE_WEIGHTS => "# comment\nproduct = 0.95\nnonsense\n=0.3\ncategory=5",
        ]);

        $ranked = $ranker->rank([
            $this->entry('https://s/c', IndexEntryInterface::TYPE_CATEGORY),
            $this->entry('https://s/p', IndexEntryInterface::TYPE_PRODUCT),
        ], 1);

        $this->assertSame(['https://s/c', 'https://s/p'], $this->urls($ranked));
        $this->assertSame(1.0, $ranked[0]->getScore());
        $this->assertSame(0.95, $ranked[1]->getScore());
    }

    public function testExactPinnedUrlAndPathWin(): void
    {
        $ranker = $this->ranker([
            WeightedRanker::XML_PINNED => "https://s/exact = 0.99\n/by-path=0.97\n",
        ]);

        $ranked = $ranker->rank([
            $this->entry('https://s/', IndexEntryInterface::TYPE_HOMEPAGE),
            $this->entry('https://s/exact', IndexEntryInterface::TYPE_SITEMAP),
            $this->entry('https://s/by-path', IndexEntryInterface::TYPE_SITEMAP),
        ], 1);

        $this->assertSame([1.0, 0.99, 0.97], array_map(static fn ($e) => $e->getScore(), $ranked));
    }

    public function testLongestPinnedPrefixWins(): void
    {
        $ranker = $this->ranker([
            WeightedRanker::XML_PINNED => "/blog/*=0.3\n/blog/featured/*=0.9\n*=0.1",
        ]);

        $ranked = $ranker->rank([
            $this->entry('https://s/blog/featured/post', IndexEntryInterface::TYPE_SITEMAP),
            $this->entry('https://s/blog/other', IndexEntryInterface::TYPE_SITEMAP),
        ], 1);

        $this->assertSame(0.9, $ranked[0]->getScore());
        $this->assertSame(0.3, $ranked[1]->getScore());
    }

    public function testEntriesBelowMinimumScoreAreDropped(): void
    {
        $ranker = $this->ranker([
            WeightedRanker::XML_PINNED    => '/hidden=0.05',
            WeightedRanker::XML_MIN_SCORE => '0.5',
        ]);

        $ranked = $ranker->rank([
            $this->entry('https://s/hidden', IndexEntryInterface::TYPE_HOMEPAGE),
            $this->entry('https://s/ext', IndexEntryInterface::TYPE_EXTERNAL),
            $this->entry('https://s/', IndexEntryInterface::TYPE_HOMEPAGE),
        ], 1);

        $this->assertSame(['https://s/'], $this->urls($ranked));
    }

    public function testDefaultMinimumScoreAppliesWhenUnset(): void
    {
        $ranker = $this->ranker([WeightedRanker::XML_PINNED => '/low=0.1']);

        $this->assertSame([], $ranker->rank([$this->entry('https://s/low', 'cms')], 1));
    }

    public function testCapLimitsResultsAndZeroMeansUnlimited(): void
    {
        $entries = [];
        for ($i = 0; $i < 5; $i++) {
            $entries[] = $this->entry('https://s/' . $i, IndexEntryInterface::TYPE_CMS);
        }

        $this->assertCount(2, $this->ranker([WeightedRanker::XML_MAX_ENTRIES => '2'])->rank($entries, 1));
        $this->assertCount(5, $this->ranker([WeightedRanker::XML_MAX_ENTRIES => '0'])->rank($entries, 1));
        $this->assertCount(5, $this->ranker([WeightedRanker::XML_MAX_ENTRIES => '-3'])->rank($entries, 1));
    }

    public function testSitemapPriorityBestsellerRankAndFeaturedBoostScores(): void
    {
        $ranked = $this->ranker()->rank([
            $this->entry('https://s/plain', IndexEntryInterface::TYPE_SITEMAP),
            $this->entry('https://s/prio', IndexEntryInterface::TYPE_SITEMAP, ['sitemap_priority' => 1.0]),
            $this->entry('https://s/low', IndexEntryInterface::TYPE_SITEMAP, ['sitemap_priority' => 0.1]),
            $this->entry('https://s/best', IndexEntryInterface::TYPE_PRODUCT, ['bestseller_rank' => 1]),
            $this->entry('https://s/feat', IndexEntryInterface::TYPE_PRODUCT, ['featured' => true]),
            $this->entry('https://s/rank0', IndexEntryInterface::TYPE_PRODUCT, ['bestseller_rank' => 0]),
        ], 1);

        $scores = [];
        foreach ($ranked as $e) {
            $scores[$e->getUrl()] = $e->getScore();
        }
        $this->assertEqualsWithDelta(0.45, $scores['https://s/plain'], 0.0001);
        $this->assertEqualsWithDelta(0.60, $scores['https://s/prio'], 0.0001);
        $this->assertEqualsWithDelta(0.33, $scores['https://s/low'], 0.0001);
        $this->assertEqualsWithDelta(0.65, $scores['https://s/best'], 0.0001);
        $this->assertEqualsWithDelta(0.60, $scores['https://s/feat'], 0.0001);
        $this->assertEqualsWithDelta(0.55, $scores['https://s/rank0'], 0.0001);
    }

    public function testDeepBestsellerRanksGetNoNegativeBoost(): void
    {
        $ranked = $this->ranker()->rank([
            $this->entry('https://s/deep', IndexEntryInterface::TYPE_PRODUCT, ['bestseller_rank' => 500]),
        ], 1);

        $this->assertEqualsWithDelta(0.55, $ranked[0]->getScore(), 0.0001);
    }

    public function testNonEntryImplementationsAreRankedWithoutMutation(): void
    {
        $foreign = $this->createStub(IndexEntryInterface::class);
        $foreign->method('getUrl')->willReturn('https://s/foreign');
        $foreign->method('getType')->willReturn(IndexEntryInterface::TYPE_HOMEPAGE);
        $foreign->method('getMetadata')->willReturn([]);
        $foreign->method('getScore')->willReturn(0.2);

        $ranked = $this->ranker()->rank([$foreign], 1);

        $this->assertSame([$foreign], $ranked);
    }
}

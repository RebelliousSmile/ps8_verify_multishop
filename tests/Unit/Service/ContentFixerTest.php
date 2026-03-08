<?php

declare(strict_types=1);

namespace ScVerifyMultishop\Tests\Unit\Service;

use ScVerifyMultishop\Service\ContentFixer;

class ContentFixerTest extends AbstractServiceTestCase
{
    private ContentFixer $fixer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixer = new ContentFixer($this->connection, $this->prefix);
    }

    public function testGetSupportedTypes(): void
    {
        $this->assertSame(['image_shop', 'cms', 'meta'], $this->fixer->getSupportedTypes());
    }

    public function testPreviewReturnsUnsupportedForUnknownType(): void
    {
        $result = $this->fixer->preview('unknown');

        $this->assertFalse($result['success']);
    }

    // === preview: image_shop ===

    public function testPreviewImageShopCountsMissingAssociations(): void
    {
        $this->mockFetchOneSequence([2, 100, 50]); // totalShops, totalImages, missingCount

        $result = $this->fixer->preview('image_shop');

        $this->assertTrue($result['success']);
        $this->assertSame('image_shop', $result['type']);
        $this->assertSame(100, $result['changes']['total_images']);
        $this->assertSame(50, $result['changes']['missing_associations']);
    }

    // === preview: cms ===

    public function testPreviewCmsShowsMissingAssociations(): void
    {
        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                [['id_shop' => 1], ['id_shop' => 2]],
                [['id_lang' => 1], ['id_lang' => 2]]
            );
        $this->mockFetchOneSequence([5]); // missingShopAssoc

        $result = $this->fixer->preview('cms');

        $this->assertTrue($result['success']);
        $this->assertSame('cms', $result['type']);
        $this->assertSame(5, $result['changes']['missing_shop_associations']);
    }

    // === preview: meta ===

    public function testPreviewMetaCountsMissingTranslations(): void
    {
        $this->mockFetchOneSequence([2, 2, 10]); // totalShops, totalLangs, missingCount

        $result = $this->fixer->preview('meta');

        $this->assertTrue($result['success']);
        $this->assertSame('meta', $result['type']);
        $this->assertSame(10, $result['changes']['missing_translations']);
    }

    // === apply: image_shop ===

    public function testApplyImageShopInsertsAssociations(): void
    {
        // sourceShopId, ROW_COUNT(delete orphans), ROW_COUNT(insert)
        $this->mockFetchOneSequence([1, 3, 50]);
        $this->connection->method('executeStatement')->willReturn(50);

        $result = $this->fixer->apply('image_shop');

        $this->assertTrue($result['success']);
        $this->assertSame('image_shop', $result['type']);
        $this->assertSame(3, $result['cleaned_orphans']);
        $this->assertSame(50, $result['inserted']);
    }

    public function testApplyImageShopHandlesException(): void
    {
        $this->connection->method('fetchOne')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->fixer->apply('image_shop');

        $this->assertFalse($result['success']);
        $this->assertSame('DB error', $result['error']);
    }

    // === apply: cms ===

    public function testApplyCmsInsertsAssociationsAndLang(): void
    {
        $this->connection->method('executeStatement')->willReturn(5);
        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls(3, 2); // shopAssocInserted, langInserted

        $result = $this->fixer->apply('cms');

        $this->assertTrue($result['success']);
        $this->assertSame('cms', $result['type']);
        $this->assertSame(3, $result['shop_associations']);
        $this->assertSame(2, $result['translations']);
    }

    public function testApplyCmsHandlesException(): void
    {
        $this->connection->method('executeStatement')
            ->willThrowException(new \Exception('Table not found'));

        $result = $this->fixer->apply('cms');

        $this->assertFalse($result['success']);
        $this->assertSame('Table not found', $result['error']);
    }

    // === apply: meta ===

    public function testApplyMetaInsertsTranslations(): void
    {
        $this->connection->method('executeStatement')->willReturn(10);
        $this->mockFetchOneSequence([10]); // ROW_COUNT

        $result = $this->fixer->apply('meta');

        $this->assertTrue($result['success']);
        $this->assertSame('meta', $result['type']);
        $this->assertSame(10, $result['inserted']);
    }

    public function testApplyMetaHandlesException(): void
    {
        $this->connection->method('executeStatement')
            ->willThrowException(new \Exception('Syntax error'));

        $result = $this->fixer->apply('meta');

        $this->assertFalse($result['success']);
        $this->assertSame('Syntax error', $result['error']);
    }
}

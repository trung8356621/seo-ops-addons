<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Support\SeoProjectRunItemsDisplayPresenter;
use Tests\TestCase;

final class SeoProjectRunItemsDisplayPresenterTest extends TestCase
{
    private SeoProjectRunItemsDisplayPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = new SeoProjectRunItemsDisplayPresenter;
    }

    public function test_same_run_item_id_collapses_to_one_row(): void
    {
        $rows = $this->presenter->consolidate([
            [
                'run_item_id' => 55,
                'task_id' => 10,
                'status' => 'failed',
                'attempt' => 1,
            ],
            [
                'run_item_id' => 55,
                'task_id' => 10,
                'article_id' => 100,
                'status' => 'success',
                'attempt' => 2,
            ],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(55, (int) $rows[0]['run_item_id']);
        $this->assertSame('success', (string) $rows[0]['status']);
        $this->assertSame(100, (int) $rows[0]['article_id']);
    }

    public function test_different_run_item_ids_stay_separate(): void
    {
        $rows = $this->presenter->consolidate([
            [
                'run_item_id' => 1,
                'task_id' => 10,
                'source_content' => 'cùng keyword',
                'status' => 'success',
            ],
            [
                'run_item_id' => 2,
                'task_id' => 11,
                'source_content' => 'cùng keyword',
                'status' => 'success',
            ],
        ]);

        $this->assertCount(2, $rows);
    }

    public function test_same_source_different_task_ids_without_run_item_stay_separate(): void
    {
        $rows = $this->presenter->consolidate([
            [
                'id' => 'legacy-a',
                'task_id' => 1,
                'source_content' => 'cùng keyword',
                'status' => 'success',
            ],
            [
                'id' => 'legacy-b',
                'task_id' => 2,
                'source_content' => 'cùng keyword',
                'status' => 'success',
            ],
        ]);

        $this->assertCount(2, $rows);
    }

    public function test_does_not_collapse_by_article_id_alone(): void
    {
        $rows = $this->presenter->consolidate([
            [
                'run_item_id' => 11,
                'task_id' => 11,
                'article_id' => 500,
                'status' => 'failed',
            ],
            [
                'run_item_id' => 22,
                'task_id' => 22,
                'article_id' => 500,
                'status' => 'success',
            ],
        ]);

        $this->assertCount(2, $rows);
    }

    public function test_does_not_mutate_input_arrays_identity(): void
    {
        $raw = [[
            'run_item_id' => 1,
            'task_id' => 1,
            'status' => 'success',
            'attempt' => 1,
        ]];

        $before = json_encode($raw);
        $this->presenter->consolidate($raw);

        $this->assertSame($before, json_encode($raw));
    }
}

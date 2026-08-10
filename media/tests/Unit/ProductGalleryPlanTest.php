<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;

use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPlanParser;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryPlan;
use PHPUnit\Framework\TestCase;

final class ProductGalleryPlanTest extends TestCase
{
    private ProductGalleryPlanParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ProductGalleryPlanParser(
            supportedAspectRatios: ['1:1', '4:3', '3:4'],
            maxShots: 6,
        );
    }

    public function test_valid_planner_json(): void
    {
        $json = json_encode([
            'shots' => [
                [
                    'slot' => 1,
                    'shot_key' => 'front',
                    'label' => 'Mặt trước',
                    'priority' => 'required',
                    'aspect_ratio' => '1:1',
                    'instruction' => 'Front product view on white background',
                ],
                [
                    'slot' => 2,
                    'shot_key' => 'side',
                    'label' => 'Mặt bên',
                    'priority' => 'required',
                    'aspect_ratio' => '1:1',
                    'instruction' => 'Side angle of the product',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json, 6);

        $this->assertTrue($result['ok']);
        $this->assertInstanceOf(ProductGalleryPlan::class, $result['plan']);
        $this->assertCount(2, $result['plan']->shots);
    }

    public function test_duplicate_slot_rejected(): void
    {
        $json = '{"shots":['
            .'{"slot":1,"shot_key":"a","label":"A","priority":"required","aspect_ratio":"1:1","instruction":"one"},'
            .'{"slot":1,"shot_key":"b","label":"B","priority":"required","aspect_ratio":"1:1","instruction":"two"}'
            .']}';

        $result = $this->parser->parse($json, 4);

        $this->assertFalse($result['ok']);
        $this->assertContains('duplicate_slot', $result['errors']);
    }

    public function test_duplicate_shot_key_rejected(): void
    {
        $json = '{"shots":['
            .'{"slot":1,"shot_key":"front","label":"A","priority":"required","aspect_ratio":"1:1","instruction":"one"},'
            .'{"slot":2,"shot_key":"front","label":"B","priority":"required","aspect_ratio":"1:1","instruction":"two"}'
            .']}';

        $result = $this->parser->parse($json, 4);

        $this->assertFalse($result['ok']);
        $this->assertContains('duplicate_shot_key', $result['errors']);
    }

    public function test_markdown_outside_json_rejected(): void
    {
        $raw = "Here is the plan:\n".'{"shots":[{"slot":1,"shot_key":"front","label":"A","priority":"required","aspect_ratio":"1:1","instruction":"ok"}]}';

        $result = $this->parser->parse($raw, 4);

        $this->assertFalse($result['ok']);
        $this->assertContains('markdown_outside_json', $result['errors']);
    }

    public function test_collage_instruction_rejected(): void
    {
        $json = '{"shots":[{"slot":1,"shot_key":"front","label":"A","priority":"required","aspect_ratio":"1:1","instruction":"Make a collage grid of angles"}]}';

        $result = $this->parser->parse($json, 4);

        $this->assertFalse($result['ok']);
        $this->assertContains('instruction_collage_or_grid_forbidden', $result['errors']);
    }

    public function test_slot_must_start_at_one(): void
    {
        $json = '{"shots":[{"slot":2,"shot_key":"front","label":"A","priority":"required","aspect_ratio":"1:1","instruction":"ok"}]}';

        $result = $this->parser->parse($json, 4);

        $this->assertFalse($result['ok']);
        $this->assertContains('slot_must_start_at_1', $result['errors']);
    }

    public function test_count_exceeds_requested(): void
    {
        $shots = [];
        for ($i = 1; $i <= 3; $i++) {
            $shots[] = [
                'slot' => $i,
                'shot_key' => 's'.$i,
                'label' => 'L'.$i,
                'priority' => 'required',
                'aspect_ratio' => '1:1',
                'instruction' => 'shot '.$i,
            ];
        }
        $result = $this->parser->parse(json_encode(['shots' => $shots], JSON_THROW_ON_ERROR), 2);

        $this->assertFalse($result['ok']);
        $this->assertContains('count_exceeds_requested', $result['errors']);
    }
}

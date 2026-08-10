<?php

declare(strict_types=1);

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        Keyword::on($this->connection)
            ->where('type', 'internal')
            ->orderBy('id')
            ->chunkById(200, function ($keywords): void {
                $ids = [];

                foreach ($keywords as $keyword) {
                    if (InternalAnchorKeywordFilter::looksLikeUrlOrLinkLabel((string) $keyword->phrase)) {
                        $ids[] = $keyword->id;
                    }
                }

                if ($ids === []) {
                    return;
                }

                if (! Schema::connection($this->connection)->hasTable('seo_article_links')) {
                    Keyword::on($this->connection)->whereIn('id', $ids)->delete();

                    return;
                }

                DB::connection($this->connection)
                    ->table('seo_article_links')
                    ->whereIn('keyword_id', $ids)
                    ->update(['keyword_id' => null]);

                Keyword::on($this->connection)->whereIn('id', $ids)->delete();
            });
    }

    public function down(): void
    {
        // Không khôi phục keyword dạng URL đã xóa.
    }
};

<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Compat;

trait UsesSeoDatabase
{
    protected function requireSeoDatabaseConnection(): void
    {
        if (! is_array(config('database.connections.omi_seo_ai'))) {
            $this->markTestSkipped('MySQL SEO database connection is not configured for tests.');
        }
    }
}

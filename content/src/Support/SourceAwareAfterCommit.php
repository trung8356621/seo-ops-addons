<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use App\Support\Automation\AutomationConnection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Run callback after all relevant open DB transactions commit.
 * Prevents BusinessHook schedule on AutomationConnection while omi_seo_ai txn still open.
 */
final class SourceAwareAfterCommit
{
    /**
     * @param  list<string>|null  $connections
     */
    public static function run(callable $callback, ?array $connections = null): void
    {
        $names = $connections ?? [
            'omi_seo_ai',
            AutomationConnection::name(),
            (string) config('database.default', 'mysql'),
        ];

        $open = [];
        foreach (array_values(array_unique(array_filter($names))) as $name) {
            try {
                $db = DB::connection($name);
                if ($db->transactionLevel() > 0) {
                    $open[] = $name;
                }
            } catch (Throwable) {
                // Connection may be unavailable in pure unit tests.
            }
        }

        if ($open === []) {
            $callback();

            return;
        }

        $remaining = count($open);
        $fired = false;
        foreach ($open as $name) {
            DB::connection($name)->afterCommit(static function () use (&$remaining, &$fired, $callback): void {
                $remaining--;
                if ($remaining > 0 || $fired) {
                    return;
                }
                $fired = true;
                $callback();
            });
        }
    }
}

<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Support\SeoSqlStreamParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SeoSqlStreamParserTest extends TestCase
{
    public function test_parses_multiple_statements_with_quoted_semicolons(): void
    {
        $sql = <<<'SQL'
SET FOREIGN_KEY_CHECKS=0;
INSERT INTO `demo` (`note`) VALUES ('a;b');
INSERT INTO `demo` (`note`) VALUES ("x;y");
SET FOREIGN_KEY_CHECKS=1;
SQL;

        $handle = fopen('php://memory', 'rb+');
        fwrite($handle, $sql);
        rewind($handle);

        $parser = new SeoSqlStreamParser;
        $statements = [];

        $parser->executeStream($handle, function (string $statement) use (&$statements): void {
            $statements[] = trim($statement);
        });

        fclose($handle);

        $this->assertCount(4, $statements);
        $this->assertSame('SET FOREIGN_KEY_CHECKS=0', $statements[0]);
        $this->assertSame("INSERT INTO `demo` (`note`) VALUES ('a;b')", $statements[1]);
        $this->assertSame('INSERT INTO `demo` (`note`) VALUES ("x;y")', $statements[2]);
        $this->assertSame('SET FOREIGN_KEY_CHECKS=1', $statements[3]);
    }

    public function test_blocks_dangerous_statements(): void
    {
        $this->expectException(RuntimeException::class);

        $handle = fopen('php://memory', 'rb+');
        fwrite($handle, "SELECT LOAD_FILE('/etc/passwd');");
        rewind($handle);

        $parser = new SeoSqlStreamParser;
        $parser->executeStream($handle, static function (): void {});

        fclose($handle);
    }
}

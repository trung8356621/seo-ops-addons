<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ResumeProjectExecutionHandler;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\ProjectRoot;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Resume must re-enter ContentProjectRunEngine (not merely flip stopping→running).
 */
final class ContentProjectResumeExecutionOwnershipTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    public function test_resume_handler_injects_and_calls_run_engine(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath()
            .'/content-projects/src/Services/ContentProject/Application/Handlers/ResumeProjectExecutionHandler.php'
        );

        self::assertStringContainsString('ContentProjectRunEngine', $src);
        self::assertStringContainsString('private readonly ContentProjectRunEngine $runEngine', $src);
        self::assertStringContainsString('$this->runEngine->resume($run)', $src);
        self::assertStringNotContainsString("STATUS_RUNNING,\n            ])->saveQuietly()", $src);
        self::assertStringNotContainsString("forceFill([\n                'status' => SeoProjectRun::STATUS_RUNNING", $src);
    }

    public function test_engine_resume_clears_stopping_then_dispatches(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/RunEngine/ContentProjectRunEngine.php'
        );

        self::assertStringContainsString('function resume(SeoProjectRun $run)', $src);
        self::assertStringContainsString('clearStoppingToRunning', $src);
        self::assertStringContainsString('STATUS_STOPPING', $src);
        self::assertStringContainsString('resume_from_stopping', $src);
        self::assertStringContainsString('dispatchNextArticle($run)', $src);
    }

    public function test_engine_resume_method_exists_and_handler_constructor_requires_engine(): void
    {
        $engine = new ReflectionClass(ContentProjectRunEngine::class);
        self::assertTrue($engine->hasMethod('resume'));

        $handler = new ReflectionClass(ResumeProjectExecutionHandler::class);
        $ctor = $handler->getConstructor();
        self::assertNotNull($ctor);
        $names = array_map(static fn ($p) => $p->getName(), $ctor->getParameters());
        self::assertContains('runEngine', $names);
    }

    public function test_stopping_does_not_allow_dispatch_until_resume_clears_it(): void
    {
        self::assertSame('stopping', SeoProjectRun::STATUS_STOPPING);
        self::assertSame('running', SeoProjectRun::STATUS_RUNNING);

        $clear = new ReflectionMethod(ContentProjectRunEngine::class, 'clearStoppingToRunning');
        self::assertTrue($clear->isPrivate());
    }
}

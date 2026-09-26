<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Guards Agent Workspace against Livewire ImplicitlyBoundMethod 404s.
 *
 * Livewire auto-calls hydrate{Property} / updated{Property} via wrap() +
 * ImplicitlyBoundMethod. A private method named hydrateKeywordContext(Model)
 * collides with public array $keywordContext and receives the array as a
 * route-binding value → ModelNotFoundException / HTTP 404.
 */
final class AgentWorkspaceLivewireBindingTest extends TestCase
{
    public function test_no_public_frontend_action_accepts_eloquent_model_parameter(): void
    {
        $class = new ReflectionClass(AgentWorkspacePage::class);
        $violations = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() === \Livewire\Component::class) {
                continue;
            }

            foreach ($this->modelTypedParameters($method) as $detail) {
                $violations[] = $method->getName().'('.$detail.')';
            }
        }

        self::assertSame([], $violations, 'Public Livewire actions must not type-hint Eloquent/UrlRoutable params.');
    }

    public function test_no_lifecycle_named_method_accepts_eloquent_model_parameter(): void
    {
        $class = new ReflectionClass(AgentWorkspacePage::class);
        $violations = [];
        $lifecyclePrefixes = ['hydrate', 'dehydrate', 'updating', 'updated', 'mount', 'boot', 'booted'];

        foreach ($class->getMethods() as $method) {
            $name = $method->getName();
            $isLifecycleNamed = false;
            foreach ($lifecyclePrefixes as $prefix) {
                if (str_starts_with($name, $prefix) && $name !== $prefix && ctype_upper(substr($name, strlen($prefix), 1))) {
                    $isLifecycleNamed = true;
                    break;
                }
            }
            if (! $isLifecycleNamed) {
                continue;
            }

            foreach ($this->modelTypedParameters($method) as $detail) {
                $violations[] = $method->getName().'('.$detail.')';
            }
        }

        self::assertSame([], $violations, 'Lifecycle-named methods must not type-hint Eloquent/UrlRoutable params.');
    }

    public function test_keyword_context_loader_is_not_livewire_hydrate_hook_name(): void
    {
        $class = new ReflectionClass(AgentWorkspacePage::class);

        self::assertFalse(
            $class->hasMethod('hydrateKeywordContext'),
            'hydrateKeywordContext collides with Livewire hydrate{keywordContext} for public $keywordContext.',
        );
        self::assertTrue($class->hasMethod('loadKeywordContextFromConversation'));
        self::assertTrue($class->hasProperty('keywordContext'));
    }

    public function test_draft_loader_is_not_livewire_hydrate_hook_name(): void
    {
        $class = new ReflectionClass(AgentWorkspacePage::class);

        self::assertFalse($class->hasMethod('hydrateActiveDraftFromConversation'));
        self::assertTrue($class->hasMethod('loadActiveDraftFromConversation'));
    }

    public function test_cli_public_methods_accept_scalar_strings_only(): void
    {
        $selectCommand = new ReflectionMethod(AgentWorkspacePage::class, 'selectCommand');
        $selectCli = new ReflectionMethod(AgentWorkspacePage::class, 'selectCliCommand');
        $suggest = new ReflectionMethod(AgentWorkspacePage::class, 'getCliArgumentSuggestions');
        $answer = new ReflectionMethod(AgentWorkspacePage::class, 'answerConversation');

        self::assertSame('string', $selectCommand->getParameters()[0]->getType()?->getName());
        self::assertSame('string', $selectCli->getParameters()[0]->getType()?->getName());
        self::assertSame('string', $suggest->getParameters()[0]->getType()?->getName());
        self::assertSame('string', $suggest->getParameters()[1]->getType()?->getName());
        self::assertSame('string', $answer->getParameters()[0]->getType()?->getName());
    }

    public function test_agent_blade_passes_scalar_command_keys_to_livewire(): void
    {
        $path = LegacyAddonPath::resolve('resources/views/filament/pages/agent-workspace.blade.php');
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('selectCommand(String(command))', $source);
        self::assertStringContainsString('selectCommand(String(row.key))', $source);
        self::assertStringContainsString('getCliArgumentSuggestions(String(ctx.type), String(ctx.query || \'\'))', $source);
        self::assertStringNotContainsString('selectCliCommand(@js(', $source);
        self::assertStringNotContainsString('$wire.selectCommand(row)', $source);
        self::assertStringNotContainsString('$wire.selectCommand(commandObject)', $source);
    }

    /**
     * @return list<string>
     */
    private function modelTypedParameters(ReflectionMethod $method): array
    {
        $bad = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type === null) {
                continue;
            }

            $names = [];
            if ($type instanceof ReflectionNamedType) {
                $names[] = $type->getName();
            } elseif ($type instanceof ReflectionUnionType) {
                foreach ($type->getTypes() as $inner) {
                    if ($inner instanceof ReflectionNamedType) {
                        $names[] = $inner->getName();
                    }
                }
            }

            foreach ($names as $name) {
                if (in_array($name, ['int', 'string', 'bool', 'float', 'array', 'mixed', 'null', 'false', 'true'], true)) {
                    continue;
                }
                if (! class_exists($name) && ! interface_exists($name)) {
                    continue;
                }
                $implements = class_implements($name) ?: [];
                if ($name === Model::class || is_subclass_of($name, Model::class) || isset($implements[UrlRoutable::class])) {
                    $bad[] = '$'.$parameter->getName().': '.$name;
                }
            }
        }

        return $bad;
    }
}

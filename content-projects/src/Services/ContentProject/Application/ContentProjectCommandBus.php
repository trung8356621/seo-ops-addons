<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessAuditor;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectIdempotencyStore;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectOperationLogger;
use Carbon\Carbon;
use InvalidArgumentException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Thin command bus — mọi entry (Filament/API/Agent/Queue) đi qua đây.
 */
final class ContentProjectCommandBus
{
    /** @var array<string, ContentProjectCommandHandler> */
    private array $handlers = [];

    public function __construct(
        private readonly ContentProjectIdempotencyStore $idempotency,
        private readonly ContentProjectBusinessAuditor $auditor,
        private readonly ContentProjectOperationLogger $operationLogger,
    ) {}

    public function register(string $commandClass, ContentProjectCommandHandler $handler): void
    {
        $this->handlers[$commandClass] = $handler;
    }

    public function dispatch(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        $handler = $this->handlers[$command::class] ?? null;
        if (! $handler instanceof ContentProjectCommandHandler) {
            throw new InvalidArgumentException('No handler for '.$command::class);
        }

        $action = $command->name();
        $tenantKey = 'site:'.(string) ($actor->siteId ?? 0).':actor:'.(string) ($actor->actorType);
        $idemKey = (string) ($actor->idempotencyKey ?? '');
        $started = microtime(true);
        $requestId = $actor->correlationId ?? (string) Str::uuid();
        $operationId = (string) Str::uuid();

        if ($idemKey !== '') {
            $replay = $this->idempotency->begin($tenantKey, $action, $idemKey);
            if ($replay instanceof ContentProjectActionResult) {
                $replay = $this->withOperationMeta($replay, $operationId, $requestId, true);
                $this->logOperation(
                    command: $command,
                    action: $action,
                    result: $replay,
                    started: $started,
                    operationId: $operationId,
                    idemKey: $idemKey,
                    actor: $actor,
                    requestId: $requestId,
                    idempotentReplay: true,
                );

                return $replay;
            }
        }

        try {
            $result = $handler->handle($command, $actor);
        } catch (ValidationException $e) {
            $flat = [];
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $flat[] = (string) $message;
                }
            }
            $result = ContentProjectActionResult::fail(
                ContentProjectActionCodes::VALIDATION_FAILED,
                $flat[0] ?? $e->getMessage(),
                errors: $e->errors(),
                metadata: ['exception' => ValidationException::class],
            );
        } catch (Throwable $e) {
            $result = ContentProjectActionResult::fail(
                ContentProjectActionCodes::FAILED,
                $e->getMessage(),
                metadata: ['exception' => $e::class],
            );
        }

        $result = $this->withOperationMeta($result, $operationId, $requestId, false);

        if ($idemKey !== '') {
            $this->idempotency->complete($tenantKey, $action, $idemKey, $result);
        }

        $this->auditor->record($actor, $action, $result);

        $this->logOperation(
            command: $command,
            action: $action,
            result: $result,
            started: $started,
            operationId: $operationId,
            idemKey: $idemKey,
            actor: $actor,
            requestId: $requestId,
        );

        return $result;
    }

    private function withOperationMeta(
        ContentProjectActionResult $result,
        string $operationId,
        string $requestId,
        bool $idempotentReplay,
    ): ContentProjectActionResult {
        return new ContentProjectActionResult(
            success: $result->success,
            code: $result->code,
            message: $result->message,
            projectId: $result->projectId,
            affectedItemIds: $result->affectedItemIds,
            warnings: $result->warnings,
            errors: $result->errors,
            metadata: array_merge($result->metadata, [
                'operation_id' => $operationId,
                'operation_ref' => $operationId,
                'request_id' => $requestId,
                'idempotent_replay' => $idempotentReplay,
            ]),
        );
    }

    private function logOperation(
        ContentProjectCommand $command,
        string $action,
        ContentProjectActionResult $result,
        float $started,
        string $operationId,
        string $idemKey,
        ActorContext $actor,
        string $requestId,
        bool $idempotentReplay = false,
    ): void {
        $projectRef = $result->projectId !== null
            ? ContentProjectPublicRef::project($result->projectId)
            : null;

        $affectedItemRefs = array_map(
            static fn (int $id): string => ContentProjectPublicRef::item($id),
            $result->affectedItemIds,
        );

        $extra = [
            'request_id' => $requestId,
            'command_class' => $command::class,
            'command_payload' => $this->commandPayloadForLog($command),
            'success' => $result->success,
            'affected_item_refs' => $affectedItemRefs,
        ];

        if ($idempotentReplay) {
            $extra['idempotent_replay'] = true;
        }

        $this->operationLogger->info(
            command: $action,
            resultCode: $result->code,
            durationMs: (int) round((microtime(true) - $started) * 1000),
            operationId: $operationId,
            idempotencyKey: $idemKey !== '' ? $idemKey : null,
            actorType: $actor->actorType,
            actorId: $actor->actorId,
            tenantRef: 'site:'.(string) ($actor->siteId ?? 0),
            projectRef: $projectRef,
            itemRef: isset($affectedItemRefs[0]) ? $affectedItemRefs[0] : null,
            extra: $extra,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function commandPayloadForLog(ContentProjectCommand $command): array
    {
        $payload = [];
        $ref = new ReflectionClass($command);

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            $value = $property->getValue($command);

            if ($value instanceof Carbon) {
                $payload[$name] = $value->toIso8601String();

                continue;
            }

            $payload[$name] = $value;
        }

        return $payload;
    }
}

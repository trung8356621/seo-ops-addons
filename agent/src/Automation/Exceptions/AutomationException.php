<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Exceptions;

use RuntimeException;

final class AutomationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function actionNotFound(string $key): self
    {
        return new self('action_not_found', "Automation action [{$key}] is not registered.");
    }

    public static function handlerMissing(string $key): self
    {
        return new self('handler_missing', "Automation action [{$key}] has no executable handler.");
    }

    public static function notSelectable(string $key): self
    {
        return new self(
            'action_not_selectable',
            "Automation action [{$key}] is not selectable for workflow/rule origins.",
        );
    }

    public static function invalidInput(string $key, string $detail): self
    {
        return new self('invalid_input', "Automation action [{$key}] input invalid: {$detail}");
    }

    public static function publishIntentRequired(string $key): self
    {
        return new self(
            'publish_intent_required',
            "Automation action [{$key}] requires a valid PublishIntent (manual_publish|scheduled_publish|republish).",
        );
    }

    public static function duplicateKey(string $key): self
    {
        return new self('duplicate_action_key', "Duplicate automation action key [{$key}].");
    }

    public static function invalidHandler(string $class): self
    {
        return new self(
            'invalid_handler',
            "Handler [{$class}] must implement BusinessAction.",
        );
    }
}

@props([
    /** @var 'selectSkill'|'selectTemplate'|'selectCommand'|'selectConversation'|'pinConversation'|'archiveConversation'|'viewKnowledge'|'forgetKnowledge'|'openProposedIntentForm'|'confirmSkill'|'retryExecution'|'viewAutomation'|'runAutomationNow'|'pauseAutomation'|'resumeAutomation'|'deleteAutomation'|'loadAutomationDiagnostics'|'viewPack'|'enablePack'|'disablePack'|'setPackStudioTab'|'resolveMemoryProposal' */
    'action' => 'selectSkill',
    'value' => '',
    'decision' => null,
    'disabled' => false,
])

@php
    $reference = trim((string) $value);
    $extraDecision = is_string($decision) ? trim($decision) : '';
    $allowed = [
        'selectSkill',
        'selectTemplate',
        'selectCommand',
        'selectConversation',
        'pinConversation',
        'archiveConversation',
        'viewKnowledge',
        'forgetKnowledge',
        'openProposedIntentForm',
        'confirmSkill',
        'retryExecution',
        'viewAutomation',
        'runAutomationNow',
        'pauseAutomation',
        'resumeAutomation',
        'deleteAutomation',
        'loadAutomationDiagnostics',
        'viewPack',
        'enablePack',
        'disablePack',
        'setPackStudioTab',
        'resolveMemoryProposal',
    ];
    $actionKey = in_array($action, $allowed, true) ? $action : null;
@endphp

{{--
  Livewire-only owner. Key in value=; expression static.
  Use $event.currentTarget (not target) so clicks on child spans still read button value.
--}}
@if ($actionKey !== null && $reference !== '')
    <button
        type="button"
        value="{{ $reference }}"
        @if ($extraDecision !== '')
            data-decision="{{ $extraDecision }}"
        @endif
        @if ($actionKey === 'selectSkill')
            wire:click="selectSkill($event.currentTarget.value)"
        @elseif ($actionKey === 'selectTemplate')
            wire:click="selectTemplate($event.currentTarget.value)"
        @elseif ($actionKey === 'selectCommand')
            wire:click="selectCommand($event.currentTarget.value)"
        @elseif ($actionKey === 'selectConversation')
            wire:click="selectConversation($event.currentTarget.value)"
        @elseif ($actionKey === 'pinConversation')
            wire:click="pinConversation($event.currentTarget.value)"
        @elseif ($actionKey === 'archiveConversation')
            wire:click="archiveConversation($event.currentTarget.value)"
        @elseif ($actionKey === 'viewKnowledge')
            wire:click="viewKnowledge($event.currentTarget.value)"
        @elseif ($actionKey === 'forgetKnowledge')
            wire:click="forgetKnowledge($event.currentTarget.value)"
        @elseif ($actionKey === 'openProposedIntentForm')
            wire:click="openProposedIntentForm($event.currentTarget.value)"
        @elseif ($actionKey === 'confirmSkill')
            wire:click="confirmSkill($event.currentTarget.value)"
        @elseif ($actionKey === 'retryExecution')
            wire:click="retryExecution($event.currentTarget.value)"
        @elseif ($actionKey === 'viewAutomation')
            wire:click="viewAutomation($event.currentTarget.value)"
        @elseif ($actionKey === 'runAutomationNow')
            wire:click="runAutomationNow($event.currentTarget.value)"
        @elseif ($actionKey === 'pauseAutomation')
            wire:click="pauseAutomation($event.currentTarget.value)"
        @elseif ($actionKey === 'resumeAutomation')
            wire:click="resumeAutomation($event.currentTarget.value)"
        @elseif ($actionKey === 'deleteAutomation')
            wire:click="deleteAutomation($event.currentTarget.value)"
        @elseif ($actionKey === 'loadAutomationDiagnostics')
            wire:click="loadAutomationDiagnostics($event.currentTarget.value)"
        @elseif ($actionKey === 'viewPack')
            wire:click="viewPack($event.currentTarget.value)"
        @elseif ($actionKey === 'enablePack')
            wire:click="enablePack($event.currentTarget.value)"
        @elseif ($actionKey === 'disablePack')
            wire:click="disablePack($event.currentTarget.value)"
        @elseif ($actionKey === 'setPackStudioTab')
            wire:click="setPackStudioTab($event.currentTarget.value)"
        @elseif ($actionKey === 'resolveMemoryProposal')
            wire:click="resolveMemoryProposal($event.currentTarget.value, $event.currentTarget.dataset.decision)"
        @endif
        @disabled($disabled)
        {{ $attributes->merge(['class' => '']) }}
    >
        {{ $slot }}
    </button>
@endif

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
            wire:click.prevent="selectSkill($event.currentTarget.value)"
        @elseif ($actionKey === 'selectTemplate')
            wire:click.prevent="selectTemplate($event.currentTarget.value)"
        @elseif ($actionKey === 'selectCommand')
            wire:click.prevent="selectCommand($event.currentTarget.value)"
        @elseif ($actionKey === 'selectConversation')
            wire:click.prevent="selectConversation($event.currentTarget.value)"
        @elseif ($actionKey === 'pinConversation')
            wire:click.prevent="pinConversation($event.currentTarget.value)"
        @elseif ($actionKey === 'archiveConversation')
            wire:click.prevent="archiveConversation($event.currentTarget.value)"
        @elseif ($actionKey === 'viewKnowledge')
            wire:click.prevent="viewKnowledge($event.currentTarget.value)"
        @elseif ($actionKey === 'forgetKnowledge')
            wire:click.prevent="forgetKnowledge($event.currentTarget.value)"
        @elseif ($actionKey === 'openProposedIntentForm')
            wire:click.prevent="openProposedIntentForm($event.currentTarget.value)"
        @elseif ($actionKey === 'confirmSkill')
            wire:click.prevent="confirmSkill($event.currentTarget.value)"
        @elseif ($actionKey === 'retryExecution')
            wire:click.prevent="retryExecution($event.currentTarget.value)"
        @elseif ($actionKey === 'viewAutomation')
            wire:click.prevent="viewAutomation($event.currentTarget.value)"
        @elseif ($actionKey === 'runAutomationNow')
            wire:click.prevent="runAutomationNow($event.currentTarget.value)"
        @elseif ($actionKey === 'pauseAutomation')
            wire:click.prevent="pauseAutomation($event.currentTarget.value)"
        @elseif ($actionKey === 'resumeAutomation')
            wire:click.prevent="resumeAutomation($event.currentTarget.value)"
        @elseif ($actionKey === 'deleteAutomation')
            wire:click.prevent="deleteAutomation($event.currentTarget.value)"
        @elseif ($actionKey === 'loadAutomationDiagnostics')
            wire:click.prevent="loadAutomationDiagnostics($event.currentTarget.value)"
        @elseif ($actionKey === 'viewPack')
            wire:click.prevent="viewPack($event.currentTarget.value)"
        @elseif ($actionKey === 'enablePack')
            wire:click.prevent="enablePack($event.currentTarget.value)"
        @elseif ($actionKey === 'disablePack')
            wire:click.prevent="disablePack($event.currentTarget.value)"
        @elseif ($actionKey === 'setPackStudioTab')
            wire:click.prevent="setPackStudioTab($event.currentTarget.value)"
        @elseif ($actionKey === 'resolveMemoryProposal')
            wire:click.prevent="resolveMemoryProposal($event.currentTarget.value, $event.currentTarget.dataset.decision)"
        @endif
        @disabled($disabled)
        {{ $attributes->merge(['class' => '']) }}
    >
        {{ $slot }}
    </button>
@endif

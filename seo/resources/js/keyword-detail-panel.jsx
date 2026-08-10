import { initKeywordDetailPanel } from './keywordDetailPanel';

function bootKeywordDetailPanel() {
    initKeywordDetailPanel();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootKeywordDetailPanel);
} else {
    bootKeywordDetailPanel();
}

document.addEventListener('livewire:navigated', bootKeywordDetailPanel);

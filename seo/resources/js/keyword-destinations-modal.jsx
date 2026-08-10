import { initKeywordDestinationsListModal } from './keywordDestinationsListModal';

function bootKeywordDestinationsModal() {
    initKeywordDestinationsListModal();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootKeywordDestinationsModal);
} else {
    bootKeywordDestinationsModal();
}

document.addEventListener('livewire:navigated', bootKeywordDestinationsModal);

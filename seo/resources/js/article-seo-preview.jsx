import { initArticleSeoListModal } from './articleSeoListModal';
import { initArticleListTableLoading } from '@content-addon/articleListTableLoading.js';
import '../css/article-seo-preview.css';

export { mountArticleSeoPreview, unmountArticleSeoPreview } from './articleSeoPreviewMount';

function bootArticleSeoListModal() {
    initArticleSeoListModal();
    initArticleListTableLoading();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootArticleSeoListModal);
} else {
    bootArticleSeoListModal();
}

document.addEventListener('livewire:navigated', bootArticleSeoListModal);

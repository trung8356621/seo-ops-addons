import React, { lazy, Suspense } from 'react';
import { useEditorHostApi } from '../../host/EditorHostApiContext';
import { t } from '../../../utils/i18n';

const ReviewsModule = lazy(() => import('../../../modules/ReviewsModule'));

export function ReviewsSidebarPanel() {
    const api = useEditorHostApi();
    const reviews = api.reviews || {};

    return (
        <Suspense fallback={<div className="seo-module-loading p-3 text-sm">{t('editor_module_loading')}</div>}>
            <ReviewsModule {...reviews} />
        </Suspense>
    );
}

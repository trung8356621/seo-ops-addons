import SiteTopicalMapPage from './pages/SiteTopicalMapPage';
import KeywordRelationshipPage from './pages/KeywordRelationshipPage';

/**
 * Smallest route abstraction — Laravel decides mode via bootstrap config.
 * No react-router dependency.
 */
export default function AppRouter({ config }) {
    const mode = String(config?.mode || 'site');
    if (mode === 'keyword-relationship') {
        return <KeywordRelationshipPage config={config} />;
    }
    return <SiteTopicalMapPage config={config} />;
}

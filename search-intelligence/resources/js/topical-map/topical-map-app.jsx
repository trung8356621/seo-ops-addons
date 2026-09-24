import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import './styles/topical-map-app.css';

const el = document.getElementById('topical-map-app-root');
if (el) {
    let props = {};
    try {
        props = JSON.parse(el.getAttribute('data-props') || '{}');
    } catch (error) {
        console.warn('[TopicalMap] Invalid bootstrap props', error);
    }
    createRoot(el).render(
        <React.StrictMode>
            <App config={props} />
        </React.StrictMode>,
    );
}

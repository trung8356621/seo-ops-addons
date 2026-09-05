import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * Resolve omnichannel-client/public whether invoked via:
 * - client junction: addons/seeding
 * - peer repo path: omnichannel-addons/seeding
 */
function resolveClientPublic() {
    const candidates = [
        path.resolve(__dirname, '../../public'),
        path.resolve(__dirname, '../../omnichannel-client/public'),
        path.resolve(__dirname, '../../../omnichannel-client/public'),
    ];

    for (const candidate of candidates) {
        if (fs.existsSync(candidate)) {
            return candidate;
        }
    }

    return candidates[0];
}

const clientPublic = resolveClientPublic();
const buildDirectory = 'build-seeding';
const hotFile = path.join(clientPublic, 'hot-seeding');

export default defineConfig({
    root: __dirname,
    server: {
        port: 5174,
        strictPort: true,
        origin: 'http://127.0.0.1:5174',
        fs: {
            allow: [__dirname, path.resolve(__dirname, '..'), path.resolve(__dirname, '../..')],
        },
    },
    plugins: [
        laravel({
            input: [
                'resources/js/seeding-workspace.jsx',
                'resources/css/seeding-workspace.css',
            ],
            publicDirectory: clientPublic,
            buildDirectory,
            hotFile,
            refresh: false,
        }),
        react(),
    ],
    build: {
        manifest: 'manifest.json',
        outDir: path.join(clientPublic, buildDirectory),
        emptyOutDir: true,
        rollupOptions: {
            output: {
                entryFileNames: 'assets/[name]-[hash].js',
                chunkFileNames: 'assets/[name]-[hash].js',
                assetFileNames: 'assets/[name]-[hash][extname]',
            },
        },
    },
    resolve: {
        dedupe: ['react', 'react-dom', 'lucide-react'],
        alias: {
            '@seeding': path.resolve(__dirname, 'resources/js/seeding'),
        },
    },
});

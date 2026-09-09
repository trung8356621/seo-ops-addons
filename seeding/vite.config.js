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
const entrypoints = [
    'resources/js/seeding-workspace.jsx',
    'resources/css/seeding-workspace.css',
];

/**
 * Windows junction builds can emit long relative manifest keys.
 * Rewrite them back to the short entrypoint names SeedingVite expects.
 */
function normalizeSeedingManifestKeys() {
    return {
        name: 'normalize-seeding-manifest-keys',
        closeBundle() {
            const manifestPath = path.join(clientPublic, buildDirectory, 'manifest.json');
            if (!fs.existsSync(manifestPath)) {
                return;
            }

            const raw = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
            const next = { ...raw };

            for (const entry of entrypoints) {
                if (next[entry]) {
                    continue;
                }

                const matchKey = Object.keys(next).find((key) => {
                    const src = String(next[key]?.src ?? key).replace(/\\/g, '/');
                    return src === entry || src.endsWith(`/${entry}`);
                });

                if (!matchKey) {
                    continue;
                }

                next[entry] = { ...next[matchKey], src: entry };
                delete next[matchKey];
            }

            fs.writeFileSync(manifestPath, `${JSON.stringify(next, null, 2)}\n`);
        },
    };
}

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
            input: entrypoints,
            publicDirectory: clientPublic,
            buildDirectory,
            hotFile,
            refresh: false,
        }),
        react(),
        normalizeSeedingManifestKeys(),
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

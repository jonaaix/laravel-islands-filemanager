import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const PACKAGE_NAME = '@aaix/laravel-islands-filemanager';
const COMPOSER_NAME = 'aaix/laravel-islands-filemanager';

const packageRoot = dirname(fileURLToPath(import.meta.url));

function readJson(path) {
    try {
        return JSON.parse(readFileSync(path, 'utf8'));
    } catch {
        return null;
    }
}

function expandPathRepository(root, url) {
    if (!url.endsWith('/*')) {
        return [resolve(root, url)];
    }

    const parent = resolve(root, url.slice(0, -2));

    if (!existsSync(parent)) {
        return [];
    }

    return readdirSync(parent, { withFileTypes: true })
        .filter((entry) => entry.isDirectory())
        .map((entry) => join(parent, entry.name));
}

function findComposerPathSource(root) {
    const composer = readJson(join(root, 'composer.json'));

    if (!composer?.repositories) {
        return null;
    }

    const repositories = Array.isArray(composer.repositories)
        ? composer.repositories
        : Object.values(composer.repositories);

    for (const repository of repositories) {
        if (repository?.type !== 'path' || typeof repository.url !== 'string') {
            continue;
        }

        for (const candidate of expandPathRepository(root, repository.url)) {
            if (readJson(join(candidate, 'composer.json'))?.name === COMPOSER_NAME) {
                return candidate;
            }
        }
    }

    return null;
}

export function resolvePackageSource(root) {
    return findComposerPathSource(root) ?? packageRoot;
}

function aliasEntries(source) {
    const exports = readJson(join(source, 'package.json'))?.exports ?? {};

    return Object.entries(exports)
        .filter(([subpath, target]) => subpath !== './vite' && typeof target === 'string')
        .map(([subpath, target]) => ({
            subpath: subpath === '.' ? '' : subpath.slice(1),
            replacement: resolve(source, target),
        }))
        .sort((a, b) => b.subpath.length - a.subpath.length)
        .map(({ subpath, replacement }) => ({
            find: new RegExp(`^${PACKAGE_NAME}${subpath}$`),
            replacement,
        }));
}

export default function islandsFilemanager() {
    return {
        name: 'aaix:laravel-islands-filemanager',
        config(userConfig) {
            const root = userConfig.root ? resolve(userConfig.root) : process.cwd();

            return {
                resolve: {
                    alias: aliasEntries(resolvePackageSource(root)),
                },
            };
        },
    };
}

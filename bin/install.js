#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const packageRoot = path.resolve(__dirname, '..');
const filesRoot = path.join(packageRoot, 'files');

const ROUTE_SNIPPET = `

# SEO programmatique
# Route volontairement placee en dernier: elle sert les pages SEO a la racine,
# sans prefixe technique, tout en laissant les routes classiques prioritaires.
seo_programmatic_page:
    path: /{slug}
    controller: 'App\\Controller\\SeoProgrammaticController::show'
    methods: [GET]
    requirements:
        slug: '[a-z0-9][a-z0-9-]*'
`;

const DASHBOARD_MENU_SNIPPET = `

        $seoProgrammaticItems = $this->getVisibleModulesForSection('SEO programmatique');
        if ($seoProgrammaticItems !== []) {
            yield MenuItem::section('SEO programmatique');
            yield MenuItem::linkToRoute('0. Documentation SEO', 'fa fa-book', 'admin_seo_documentation');
            foreach ($seoProgrammaticItems as $entity) {
                yield $this->linkToModuleCrud($entity);
            }
        }
`;

const DASHBOARD_HELPERS_SNIPPET = `

    private function getVisibleModulesForSection(string $section): array
    {
        $items = [];
        $modules = property_exists($this, 'entitys')
            ? $this->entitys
            : $this->entityManager->getRepository(Module::class)->findAll();

        foreach ($modules as $entity) {
            if (!$entity instanceof Module || $entity->getSection() !== $section || $entity->isValid() !== true) {
                continue;
            }

            if ($this->canSeeModule($entity)) {
                $items[] = $entity;
            }
        }

        return $items;
    }

    private function canSeeModule(Module $module): bool
    {
        $userRoles = property_exists($this, 'UserRoles')
            ? $this->UserRoles
            : $this->security->getUser()->getRoles();

        foreach ($userRoles as $RoleUser) {
            if (in_array($RoleUser, $module->getSee(), true) || $RoleUser === 'ROLE_ADMIN') {
                return true;
            }
        }

        return false;
    }

    private function linkToModuleCrud(Module $module)
    {
        return MenuItem::linkToCrud($module->getTitle(), $module->getIcon(), "App\\\\Entity\\\\" . $module->getName());
    }
`;

const SITEMAP_SNIPPET = `

        foreach ($this->em->getRepository(SeoPage::class)->findIndexablePages() as $seoPage) {
            if ($seoPage->getUpdatedAt()) {
                $date = $seoPage->getUpdatedAt()->format('Y-m-d');
            } elseif ($seoPage->getPublishedAt()) {
                $date = $seoPage->getPublishedAt()->format('Y-m-d');
            } else {
                $date = $seoPage->getCreatedAt()->format('Y-m-d');
            }

            $urls[] = [
                'loc' => $this->generateUrl('seo_programmatic_page', [
                    'slug' => $seoPage->getSlug(),
                ]),
                'lastmod' => $date,
            ];
        }
`;

const TINYMCE_EXCLUSION_SNIPPET = `
const plainTextareaFieldNames = [
    'contentJson',
    'faqJson',
    'internalLinksJson',
    'templateCopyJson',
    'schemaJsonText',
];

plainTextareaFieldNames.forEach(function (fieldName) {
    $('textarea[name$="[' + fieldName + ']"]').addClass('seo-plain-text');
});

`;

function scssVariableValue(args, variableName, fallback) {
    const variablesFile = path.join(args.target, 'assets/styles/front/_variables.scss');

    if (!fs.existsSync(variablesFile)) {
        return fallback;
    }

    const variablesContent = read(variablesFile);
    const variablePattern = new RegExp(`\\$${variableName}\\s*:`);

    return variablePattern.test(variablesContent) ? `#{$${variableName}}` : fallback;
}

function frontColorVariableDeclarations(args) {
    const primary = scssVariableValue(args, 'primary', '#F88233');
    const primaryLight = scssVariableValue(args, 'primary-light', primary);

    return [
        {
            key: '--wt-primary',
            line: `  --wt-primary: ${primary};`,
        },
        {
            key: '--wt-primary-light',
            line: `  --wt-primary-light: ${primaryLight};`,
        },
    ];
}

function parseArgs(argv) {
    const args = {
        target: process.cwd(),
        env: '.env.local',
        dryRun: false,
        force: false,
        backup: true,
        keepBackups: false,
        cleanBackups: false,
        help: false,
    };

    for (const arg of argv) {
        if (arg === '--dry-run') {
            args.dryRun = true;
        } else if (arg === '--force') {
            args.force = true;
        } else if (arg === '--no-backup') {
            args.backup = false;
        } else if (arg === '--keep-backups') {
            args.keepBackups = true;
        } else if (arg === '--clean-backups') {
            args.cleanBackups = true;
        } else if (arg === '--help' || arg === '-h') {
            args.help = true;
        } else if (arg.startsWith('--target=')) {
            args.target = arg.slice('--target='.length);
        } else if (arg.startsWith('--env=')) {
            args.env = arg.slice('--env='.length);
        }
    }

    args.target = path.resolve(args.target);
    return args;
}

function showHelp() {
    console.log(`Installateur SEO programmatique

Usage:
  npx seo-prog
  npx seo-prog --dry-run
  npx seo-prog --clean-backups
  npx seo-prog --target=/chemin/projet --env=.env.local

Options:
  --dry-run        Affiche les actions sans modifier les fichiers
  --force          Remplace les fichiers du module deja presents
  --no-backup      Ne cree pas de sauvegarde avant patch
  --keep-backups    Conserve les sauvegardes apres une installation reussie
  --clean-backups  Supprime les sauvegardes .seo-programmatique.bak-*
  --env=FILE       Fichier env cible, par defaut .env.local
  --target=DIR     Projet Symfony cible, par defaut le dossier courant
`);
}

function log(kind, message) {
    const labels = {
        ok: '[OK]',
        add: '[ADD]',
        patch: '[PATCH]',
        skip: '[SKIP]',
        warn: '[WARN]',
        dry: '[DRY]',
        delete: '[DEL]',
    };

    console.log(`${labels[kind] || '[INFO]'} ${message}`);
}

function ensureProjectRoot(targetRoot) {
    if (!fs.existsSync(path.join(targetRoot, 'composer.json'))) {
        throw new Error(`composer.json introuvable dans ${targetRoot}. Lance la commande depuis la racine du projet Symfony ou utilise --target=...`);
    }
}

function listFiles(dir, base = dir) {
    if (!fs.existsSync(dir)) {
        return [];
    }

    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const fullPath = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            return listFiles(fullPath, base);
        }

        return [path.relative(base, fullPath)];
    });
}

function read(file) {
    return fs.readFileSync(file, 'utf8');
}

function write(file, content, dryRun) {
    if (!dryRun) {
        fs.mkdirSync(path.dirname(file), { recursive: true });
        fs.writeFileSync(file, content, 'utf8');
    }
}

function sameContent(source, target) {
    return fs.existsSync(target) && read(source) === read(target);
}

function backup(file, args) {
    if (!args.backup || !fs.existsSync(file) || args.dryRun) {
        return null;
    }

    const stamp = new Date().toISOString().replace(/[-:T.Z]/g, '').slice(0, 14);
    const backupFile = `${file}.seo-programmatique.bak-${stamp}`;
    fs.copyFileSync(file, backupFile);
    return backupFile;
}

function listBackupFiles(dir, base = dir) {
    if (!fs.existsSync(dir)) {
        return [];
    }

    const ignoredDirectories = new Set(['.git', 'node_modules', 'vendor', 'var']);
    const backupPattern = /\.seo-programmatique\.bak-\d{14}$/;

    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const fullPath = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            return ignoredDirectories.has(entry.name) ? [] : listBackupFiles(fullPath, base);
        }

        return backupPattern.test(entry.name) ? [fullPath] : [];
    });
}

function cleanBackupFiles(args, silentWhenEmpty = false) {
    const backupFiles = listBackupFiles(args.target);

    if (backupFiles.length === 0) {
        if (!silentWhenEmpty) {
            log('skip', 'aucune sauvegarde SEO programmatique trouvee');
        }
        return;
    }

    for (const file of backupFiles) {
        const relative = path.relative(args.target, file);
        log(args.dryRun ? 'dry' : 'delete', `suppression sauvegarde ${relative}`);

        if (!args.dryRun) {
            fs.unlinkSync(file);
        }
    }

    log('ok', `${backupFiles.length} sauvegarde(s) SEO programmatique ${args.dryRun ? 'trouvee(s)' : 'supprimee(s)'}`);
}

function copyModuleFiles(args) {
    const files = listFiles(filesRoot);

    for (const relative of files) {
        if (relative === '.env.seo-programmatique.example') {
            log('skip', `${relative} garde en interne, variables fusionnees dans ${args.env}`);
            continue;
        }

        const source = path.join(filesRoot, relative);
        const target = path.join(args.target, relative);

        if (!fs.existsSync(target)) {
            log(args.dryRun ? 'dry' : 'add', `copie ${relative}`);
            if (!args.dryRun) {
                fs.mkdirSync(path.dirname(target), { recursive: true });
                fs.copyFileSync(source, target);
            }
            continue;
        }

        if (sameContent(source, target)) {
            log('skip', `${relative} deja identique`);
            continue;
        }

        if (!args.force) {
            log('skip', `${relative} existe deja, non remplace. Utilise --force pour remplacer.`);
            continue;
        }

        const backupFile = backup(target, args);
        log(args.dryRun ? 'dry' : 'patch', `remplacement ${relative}${backupFile ? ` sauvegarde: ${path.basename(backupFile)}` : ''}`);
        if (!args.dryRun) {
            fs.copyFileSync(source, target);
        }
    }
}

function patchTextFile(relative, args, patcher) {
    const file = path.join(args.target, relative);

    if (!fs.existsSync(file)) {
        log('warn', `${relative} introuvable, patch manuel necessaire`);
        return;
    }

    const original = read(file);
    const result = patcher(original, file);

    if (!result || result.content === original) {
        log('skip', result && result.message ? result.message : `${relative} deja OK`);
        return;
    }

    const backupFile = backup(file, args);
    log(args.dryRun ? 'dry' : 'patch', `${relative}${backupFile ? ` sauvegarde: ${path.basename(backupFile)}` : ''}`);
    write(file, result.content, args.dryRun);
}

function patchEnv(args) {
    const exampleFile = path.join(filesRoot, '.env.seo-programmatique.example');
    const targetFile = path.join(args.target, args.env);

    if (!fs.existsSync(exampleFile)) {
        log('warn', '.env.seo-programmatique.example introuvable dans le package');
        return;
    }

    const exampleLines = read(exampleFile).split(/\r?\n/);
    const targetContent = fs.existsSync(targetFile) ? read(targetFile) : '';
    const existingKeys = new Set();
    const envFilesToCheck = [targetFile];

    const defaultEnvFile = path.join(args.target, '.env');
    if (path.resolve(targetFile) !== path.resolve(defaultEnvFile)) {
        envFilesToCheck.push(defaultEnvFile);
    }

    for (const envFile of envFilesToCheck) {
        if (!fs.existsSync(envFile)) {
            continue;
        }

        for (const line of read(envFile).split(/\r?\n/)) {
            const match = line.match(/^\s*([A-Z0-9_]+)\s*=/);
            if (match) {
                existingKeys.add(match[1]);
            }
        }
    }

    const missingLines = [];
    for (const line of exampleLines) {
        const match = line.match(/^\s*([A-Z0-9_]+)\s*=/);
        if (match && !existingKeys.has(match[1])) {
            missingLines.push(line);
        }
    }

    if (missingLines.length === 0) {
        log('skip', `${args.env} contient deja les variables SEO`);
        return;
    }

    const block = [
        '',
        '### SEO programmatique / Claude ###',
        ...missingLines,
        '### Fin SEO programmatique ###',
        '',
    ].join('\n');

    const backupFile = backup(targetFile, args);
    log(args.dryRun ? 'dry' : 'patch', `${args.env} ajout variables: ${missingLines.map((line) => line.split('=')[0]).join(', ')}${backupFile ? ` sauvegarde: ${path.basename(backupFile)}` : ''}`);
    write(targetFile, `${targetContent.replace(/\s*$/, '')}${block}`, args.dryRun);
}

function patchRoutes(args) {
    patchTextFile('config/routes.yaml', args, (content) => {
        if (content.includes('seo_programmatic_page:')) {
            return { content, message: 'config/routes.yaml contient deja seo_programmatic_page' };
        }

        return { content: `${content.replace(/\s*$/, '')}${ROUTE_SNIPPET}\n` };
    });
}

function ensurePhpUse(content, useLine) {
    if (content.includes(useLine)) {
        return content;
    }

    const matches = [...content.matchAll(/^use .+;$/gm)];
    if (matches.length > 0) {
        const last = matches[matches.length - 1];
        const insertAt = last.index + last[0].length;
        return `${content.slice(0, insertAt)}\n${useLine}${content.slice(insertAt)}`;
    }

    const namespaceMatch = content.match(/^namespace .+;$/m);
    if (!namespaceMatch || namespaceMatch.index === undefined) {
        return content;
    }

    const insertAt = namespaceMatch.index + namespaceMatch[0].length;
    return `${content.slice(0, insertAt)}\n\n${useLine}${content.slice(insertAt)}`;
}

function findMethodEnd(content, methodName) {
    const methodIndex = content.indexOf(`function ${methodName}`);
    if (methodIndex === -1) {
        return -1;
    }

    const openIndex = content.indexOf('{', methodIndex);
    if (openIndex === -1) {
        return -1;
    }

    let depth = 0;
    for (let i = openIndex; i < content.length; i += 1) {
        if (content[i] === '{') {
            depth += 1;
        } else if (content[i] === '}') {
            depth -= 1;
            if (depth === 0) {
                return i;
            }
        }
    }

    return -1;
}

function insertBeforeMethodEnd(content, methodName, snippet) {
    const end = findMethodEnd(content, methodName);
    if (end === -1) {
        return null;
    }

    return `${content.slice(0, end)}${snippet}${content.slice(end)}`;
}

function insertBeforeClassEnd(content, snippet) {
    const end = content.lastIndexOf('}');
    if (end === -1) {
        return null;
    }

    return `${content.slice(0, end)}${snippet}${content.slice(end)}`;
}

function patchDashboard(args) {
    patchTextFile('src/Controller/Admin/DashboardController.php', args, (content) => {
        let next = content;

        if (next.includes('admin_seo_documentation') && next.includes("getVisibleModulesForSection('SEO programmatique')")) {
            return { content: next, message: 'DashboardController.php contient deja le menu SEO programmatique' };
        }

        if (!next.includes('function configureMenuItems')) {
            return { content: next, message: 'DashboardController.php: configureMenuItems introuvable, patch manuel necessaire' };
        }

        next = ensurePhpUse(next, 'use App\\Entity\\Module;');

        if (!next.includes("getVisibleModulesForSection('SEO programmatique')")) {
            const configIndex = next.indexOf("if ($this->security->isGranted('ROLE_MODO')");
            if (configIndex !== -1) {
                next = `${next.slice(0, configIndex)}${DASHBOARD_MENU_SNIPPET}        ${next.slice(configIndex)}`;
            } else {
                const inserted = insertBeforeMethodEnd(next, 'configureMenuItems', DASHBOARD_MENU_SNIPPET);
                if (!inserted) {
                    return { content, message: 'DashboardController.php: impossible de placer le menu SEO, patch manuel necessaire' };
                }
                next = inserted;
            }
        }

        if (!next.includes('private function getVisibleModulesForSection')) {
            const inserted = insertBeforeClassEnd(next, DASHBOARD_HELPERS_SNIPPET);
            if (!inserted) {
                return { content, message: 'DashboardController.php: impossible de placer les helpers SEO, patch manuel necessaire' };
            }
            next = inserted;
        }

        return { content: next };
    });
}

function patchSitemap(args) {
    patchTextFile('src/Controller/SitemapController.php', args, (content) => {
        let next = ensurePhpUse(content, 'use App\\Entity\\SeoPage;');

        if (next.includes('findIndexablePages()')) {
            return { content: next, message: 'SitemapController.php contient deja les pages SEO' };
        }

        const marker = next.indexOf('// Fabrication de la reponse');
        if (marker !== -1) {
            next = `${next.slice(0, marker)}${SITEMAP_SNIPPET}\n        ${next.slice(marker)}`;
            return { content: next };
        }

        const responseIndex = next.indexOf('$response = new Response');
        if (responseIndex !== -1) {
            next = `${next.slice(0, responseIndex)}${SITEMAP_SNIPPET}\n        ${next.slice(responseIndex)}`;
            return { content: next };
        }

        return { content, message: 'SitemapController.php: emplacement de la reponse introuvable, patch manuel necessaire' };
    });
}

function patchTinyMce(args) {
    patchTextFile('assets/js/back/script.js', args, (content) => {
        if (content.includes('seo-plain-text')) {
            return { content, message: 'assets/js/back/script.js contient deja le correctif TinyMCE SEO' };
        }

        let next = content;
        const tinyIndex = next.indexOf('// TinyMCE');
        const initIndex = next.indexOf('tinymce.init');
        const insertIndex = tinyIndex !== -1 ? tinyIndex : initIndex;

        if (insertIndex === -1) {
            return { content, message: 'assets/js/back/script.js: tinymce.init introuvable, patch manuel necessaire' };
        }

        next = `${next.slice(0, insertIndex)}${TINYMCE_EXCLUSION_SNIPPET}${next.slice(insertIndex)}`;

        const replaced = next.replace(/selector:\s*["']textarea["']/, 'selector: "textarea:not(.seo-plain-text)"');
        if (replaced === next) {
            log('warn', 'selector TinyMCE "textarea" introuvable: verifie manuellement que TinyMCE ignore .seo-plain-text');
            return { content: next };
        }

        return { content: replaced };
    });
}

function patchFrontColorVariables(args) {
    patchTextFile('assets/styles/front/custom.scss', args, (content) => {
        const declarations = frontColorVariableDeclarations(args);
        const missingDeclarations = declarations.filter((declaration) => !content.includes(`${declaration.key}:`));

        if (missingDeclarations.length === 0) {
            return { content, message: 'assets/styles/front/custom.scss contient deja les variables couleur SEO' };
        }

        const rootMatch = content.match(/:root\s*\{[\s\S]*?\}/);

        if (rootMatch && rootMatch.index !== undefined) {
            const insertAt = rootMatch.index + rootMatch[0].lastIndexOf('}');
            const insertion = `${missingDeclarations.map((declaration) => `\n${declaration.line}`).join('')}`;

            return {
                content: `${content.slice(0, insertAt)}${insertion}${content.slice(insertAt)}`,
            };
        }

        const imports = [...content.matchAll(/^@import .+;$/gm)];
        const insertAt = imports.length > 0
            ? imports[imports.length - 1].index + imports[imports.length - 1][0].length
            : 0;
        const rootBlock = `\n\n:root {\n${missingDeclarations.map((declaration) => declaration.line).join('\n')}\n}\n`;

        return {
            content: `${content.slice(0, insertAt)}${rootBlock}${content.slice(insertAt)}`,
        };
    });
}

function main() {
    const args = parseArgs(process.argv.slice(2));

    if (args.help) {
        showHelp();
        return;
    }

    ensureProjectRoot(args.target);

    log('ok', `projet cible: ${args.target}`);
    if (args.dryRun) {
        log('dry', 'mode simulation actif, aucun fichier ne sera modifie');
    }

    if (args.cleanBackups) {
        cleanBackupFiles(args);
        return;
    }

    copyModuleFiles(args);
    patchEnv(args);
    patchRoutes(args);
    patchDashboard(args);
    patchSitemap(args);
    patchTinyMce(args);
    patchFrontColorVariables(args);

    if (!args.keepBackups) {
        cleanBackupFiles(args, true);
    }

    log('ok', 'installation terminee');
    log('ok', 'prochaine etape: php bin/console doctrine:migrations:migrate puis npm run build et cache Symfony');
}

try {
    main();
} catch (error) {
    console.error(`[ERROR] ${error.message}`);
    process.exitCode = 1;
}

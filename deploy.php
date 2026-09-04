<?php

namespace Deployer;

require 'recipe/symfony.php';

// Config

set('repository', 'git@github.com:matthieubozec/matthieubozec.fr.git');

add('shared_files', [
    'var/db/data_prod.db',
]);
add('shared_dirs', [
    'public/media/cache',
]);
add('writable_dirs', [
    'public/media/cache',
]);

set('http_user', 'matthieu');
set('bin/php', '/usr/bin/php8.5');

// Hosts
host('www')
    ->set('remote_user', 'matthieu')
    ->set('deploy_path', '~/www')
    ->set('hostname', '51.91.248.135')
    ->set('branch', 'main')
    ->set('port', 2121)
;

// Hooks
task('deploy:assets', function () {
    run('{{bin/console}} assets:install {{console_options}} {{release_path}}/public');
    // npm install + build depasse largement le timeout par defaut de 300s de Deployer.
    run(
        'export PATH="$HOME/.nodenv/bin:$PATH" && eval "$(nodenv init -)" && cd {{release_path}} && npm ci --prefer-offline --no-audit --no-fund && npm run build',
        timeout: 1800,
    );
});

after('deploy:cache:clear', 'deploy:assets');

after('deploy:failed', 'deploy:unlock');

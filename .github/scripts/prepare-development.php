<?php

declare(strict_types=1);

// Build a separate Composer root from actual dependency checkouts. Package versions
// are discovered from their Git branches and declared aliases, never overridden.
$root = dirname(__DIR__, 2);
$dependencyRoot = $argv[1] ?? $root . '/.development-dependencies';
$manifest = json_decode(file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$dependencies = json_decode(file_get_contents(dirname(__DIR__) . '/development-dependencies.json'), true, flags: JSON_THROW_ON_ERROR);
$repositories = [];

foreach ($dependencies as $name => $expected) {
    $path = realpath($dependencyRoot . '/' . $name);
    if ($path === false) {
        throw new RuntimeException('Missing development dependency checkout: ' . $name);
    }
    $package = json_decode(file_get_contents($path . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $branch = 'dev-' . $expected['branch'];
    if (($package['name'] ?? null) !== 'componenta/' . $name
        || ($package['extra']['branch-alias'][$branch] ?? null) !== $expected['alias']) {
        throw new RuntimeException('Unexpected package or branch alias in dependency checkout: ' . $name);
    }

    $manifest['require'][$package['name']] = $branch;
    unset($manifest['require-dev'][$package['name']]);
    $repositories[] = ['type' => 'path', 'url' => $path, 'options' => ['symlink' => true]];
}

$manifest['repositories'] = array_merge($repositories, array_values($manifest['repositories'] ?? []));
$manifest['minimum-stability'] = 'stable';
$manifest['prefer-stable'] = true;
file_put_contents($root . '/.composer-development.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\DevtoolboxManager;

it('ships boost guidelines mentioning every tool', function (): void {
    $guidelines = file_get_contents(__DIR__.'/../../../resources/boost/guidelines/core.blade.php');

    foreach ((new DevtoolboxManager(app()))->registry()->all() as $name) {
        expect($guidelines)->toContain("devtoolbox-$name");
    }

    expect($guidelines)->toContain('mcp:start devtoolbox')->toContain('--format=json');
});

it('ships a boost skill with valid frontmatter', function (): void {
    $skill = file_get_contents(__DIR__.'/../../../resources/boost/skills/devtoolbox-analysis/SKILL.md');

    expect($skill)->toStartWith("---\nname: devtoolbox-analysis\ndescription: ")
        ->toContain('## When to use this skill')
        ->toContain('devtoolbox-sql-analysis')
        ->toContain('devtoolbox-model-usage')
        ->toContain('devtoolbox-provider-timeline');
});

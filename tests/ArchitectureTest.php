<?php

declare(strict_types=1);

arch('Domain has no framework dependencies')
    ->expect('App\Domain')
    ->not->toUse(['Symfony', 'Doctrine', 'Predis']);

arch('Controllers never touch repositories directly')
    ->expect('App\Controller')
    ->not->toUse('App\Infrastructure\Persistence');

arch('All files declare strict types')
    ->expect('App')
    ->toUseStrictTypes();

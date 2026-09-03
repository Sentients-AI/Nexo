<?php

arch('it uses no debugging helpers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('it applies the php preset')
    ->preset()->php();

arch('it applies the security preset')
    ->preset()->security();

arch('env() is only read from configuration')
    ->expect('env')
    ->toOnlyBeUsedIn('config');

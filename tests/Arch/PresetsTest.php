<?php

// Pest's own presets, moved from tests/Unit/ArchTest.php.

arch('it applies the php preset')
    ->preset()->php();

arch('it applies the security preset')
    ->preset()->security();

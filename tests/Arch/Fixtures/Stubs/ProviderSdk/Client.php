<?php

namespace Tests\Arch\Fixtures\Stubs\ProviderSdk;

/**
 * Stand-in for a provider SDK in the L5 fixtures. Pest matches a dependency
 * only under a Composer PSR-4 root, so the uninstalled Stripe SDK cannot be
 * used to prove the rule; this namespace can.
 */
final class Client {}

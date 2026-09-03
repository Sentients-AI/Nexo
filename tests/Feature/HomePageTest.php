<?php

it('serves the storefront landing page', function () {
    $this->get('/')->assertOk();
});

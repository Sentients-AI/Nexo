<?php

it('exposes a health check endpoint', function () {
    $this->get('/up')->assertOk();
});

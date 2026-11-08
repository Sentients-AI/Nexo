<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renders the storefront home page through Inertia', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Storefront/Home')
            ->where('locale', 'en')
            ->where('direction', 'ltr'));
});

it('sets the document language and direction on the root element', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('<html lang="en" dir="ltr">', escape: false);
});

it('derives a right-to-left direction for Arabic', function () {
    $this->get('/ar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'ar')
            ->where('direction', 'rtl'))
        ->assertSee('<html lang="ar" dir="rtl">', escape: false);
});

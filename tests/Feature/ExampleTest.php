<?php

it('redirects guests from the root to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
});

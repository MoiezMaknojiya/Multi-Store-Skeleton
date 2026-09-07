<?php

it('redirects a guest to the login page', function () {
    $response = $this->get('/');

    $response->assertRedirect('/login');
});

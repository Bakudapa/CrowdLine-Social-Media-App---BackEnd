<?php

it('returns a successful response from the root endpoint', function () {
    $this->get('/')->assertOk()->assertJsonStructure(['name', 'api']);
});

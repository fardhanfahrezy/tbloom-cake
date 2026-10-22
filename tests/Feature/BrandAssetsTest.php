<?php

test('shows the supplied TBloom logo and PNG favicon on public pages', function (string $path) {
    $this->get($path)->assertOk()
        ->assertSee('src="'.asset('icon.PNG').'"', false)
        ->assertSee('alt="TBloom Cake"', false)
        ->assertSee('href="'.asset('favicon.PNG').'"', false)
        ->assertSee('type="image/png"', false)
        ->assertSee('Katalog');
})->with(['/', '/login']);

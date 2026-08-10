<?php

declare(strict_types=1);

it('serves the public landing page', function (): void {
    $this->get('/')->assertOk();
});

<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the Laravel application. Unit tests stay framework-free
| so they run fast (e.g. hash/placement and timeline merge logic).
|
*/

pest()->extend(TestCase::class)
    ->in('Feature');

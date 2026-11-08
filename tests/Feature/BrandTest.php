<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * O cliente da instalação (config/thandizo.php) chega a todas as páginas,
 * para aparecer junto do nome THANDIZO.
 */
class BrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_receives_the_client_of_this_installation(): void
    {
        config([
            'thandizo.client.name' => 'Save the Children',
            'thandizo.client.programme' => 'Projecto LINK GCF',
            'thandizo.client.tagline' => ' ',
        ]);

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('brand.client', 'Save the Children')
            ->where('brand.programme', 'Projecto LINK GCF')
            ->where('brand.tagline', null));
    }

    public function test_without_a_client_only_thandizo_is_shown(): void
    {
        config(['thandizo.client' => ['name' => null, 'programme' => '', 'tagline' => null]]);

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('brand', ['client' => null, 'programme' => null, 'tagline' => null, 'logos' => []]));
    }
}

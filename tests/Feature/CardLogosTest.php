<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CardLogo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Logótipos do cliente nos cartões, carregados pelo administrador na
 * Configuração e guardados na base de dados.
 */
class CardLogosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->role(UserRole::SystemAdmin)->create();
    }

    private function upload(string $name = 'logo.png'): void
    {
        $this->actingAs($this->admin)->post('/admin/settings/logos', ['logo' => UploadedFile::fake()->image($name, 300, 120)])
            ->assertSessionHasNoErrors();
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return CardLogo::query()->orderBy('position')->pluck('original_name')->all();
    }

    public function test_the_meal_manager_and_the_administrator_manage_the_logos_and_nobody_else(): void
    {
        $manager = User::factory()->role(UserRole::MealManager)->create();
        $officer = User::factory()->role(UserRole::MealOfficer)->create();

        $this->actingAs($this->admin)->get('/admin/settings')->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/settings'));
        $this->actingAs($manager)->post('/admin/settings/logos', ['logo' => UploadedFile::fake()->image('x.png')])->assertSessionHasNoErrors();
        $this->assertSame(1, CardLogo::query()->count());
        $this->actingAs($officer)->get('/admin/settings')->assertForbidden();
        $this->actingAs($officer)->post('/admin/settings/logos', ['logo' => UploadedFile::fake()->image('y.png')])->assertForbidden();
    }

    public function test_logos_are_stored_served_and_sent_to_every_page_in_order(): void
    {
        $this->upload('a.png');
        $this->upload('b.png');
        $logo = CardLogo::query()->where('position', 1)->firstOrFail();

        $this->assertSame(['a.png', 'b.png'], $this->names());
        $this->actingAs($this->admin)->get("/brand/logos/{$logo->id}")
            ->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->admin)->get('/admin/cards')
            ->assertInertia(fn (Assert $page) => $page->where('brand.logos.0', $logo->url())->has('brand.logos', 2));
    }

    public function test_only_images_up_to_1_mb_and_at_most_three(): void
    {
        $post = fn (UploadedFile $file) => $this->actingAs($this->admin)->post('/admin/settings/logos', ['logo' => $file]);

        $post(UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'))->assertSessionHasErrors(['logo' => 'Use uma imagem PNG, JPG ou WebP.']);
        $post(UploadedFile::fake()->image('big.png')->size(2048))->assertSessionHasErrors(['logo' => 'O logótipo não pode passar de 1 MB.']);

        $this->upload('1.png');
        $this->upload('2.png');
        $this->upload('3.png');
        $post(UploadedFile::fake()->image('4.png'))->assertSessionHasErrors(['logo' => 'O cartão leva no máximo 3 logótipos. Retire um antes de carregar outro.']);
    }

    public function test_moving_and_removing_keep_the_order_without_gaps(): void
    {
        $this->upload('a.png');
        $this->upload('b.png');
        $this->upload('c.png');
        $c = CardLogo::query()->where('original_name', 'c.png')->firstOrFail();
        $a = CardLogo::query()->where('original_name', 'a.png')->firstOrFail();

        $this->actingAs($this->admin)->put("/admin/settings/logos/{$c->id}/move-left")->assertRedirect();
        $this->assertSame(['a.png', 'c.png', 'b.png'], $this->names());

        $this->actingAs($this->admin)->delete("/admin/settings/logos/{$a->id}")->assertRedirect();
        $this->assertSame(['c.png', 'b.png'], $this->names());
        $this->assertSame([1, 2], CardLogo::query()->orderBy('position')->pluck('position')->all());
    }
}

<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_deactivated_during_the_session_is_logged_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/account')->assertOk();

        $user->update(['status' => RecordStatus::Inactive]);

        $this->get('/account')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'O seu utilizador não está ativo no programa.']);
        $this->assertGuest();
    }

    public function test_account_page_shows_my_details(): void
    {
        $user = User::factory()->create(['full_name' => 'Ana Maria']);

        $this->actingAs($user)
            ->get('/account')
            ->assertInertia(fn (Assert $page) => $page
                ->component('account')
                ->where('account.full_name', 'Ana Maria')
                ->where('account.email', $user->email)
                ->where('account.role_label', 'Oficial MEAL'));
    }
}

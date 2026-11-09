<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserAccessRequest;
use App\Models\Agrodealer;
use App\Models\Province;
use App\Models\User;
use App\Services\UserAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request, UserAdminService $users): Response
    {
        Gate::authorize('viewAny', User::class);
        $me = $request->user();

        return Inertia::render('admin/users', [
            'users' => $users->list($me)->map(fn (User $user): array => [
                'id' => $user->id,
                'email' => $user->email,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'role' => $user->role->value,
                'role_label' => $user->role->label(),
                'agrodealer_id' => $user->agrodealer_id,
                'agrodealer_name' => $user->agrodealer?->name,
                'province_id' => $user->province_id,
                'province_name' => $user->province?->name,
                'status' => $user->status->value,
                'is_me' => $user->is($me),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ]),
            // O oficial MEAL só cria contas de comerciantes da sua província.
            'roles' => collect(UserRole::cases())->filter(fn (UserRole $role): bool => ! $me->isProvinceScoped() || $role->isSeller())->values()->map(fn (UserRole $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
                'agrodealer' => $role->isSeller(),
                'province' => $role->needsProvince(),
            ]),
            'agrodealers' => Agrodealer::query()->visibleTo($me)->orderBy('name')->get(['id', 'code', 'name', 'status'])
                ->map(fn (Agrodealer $agrodealer): array => [
                    'id' => $agrodealer->id,
                    'code' => $agrodealer->code,
                    'name' => $agrodealer->name,
                    'status' => $agrodealer->status->value,
                ]),
            'provinces' => Province::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreUserRequest $request, UserAdminService $users): RedirectResponse
    {
        $user = $users->create($request->user(), $request->validated());

        return to_route('admin.users.index')->with('credentials', [
            'full_name' => $user->full_name,
            'role_label' => $user->role->label(),
            'email' => $user->email,
            'username' => $user->username,
            'password' => $request->validated('password'),
            'kind' => 'created',
        ]);
    }

    public function update(UpdateUserAccessRequest $request, User $user, UserAdminService $users): RedirectResponse
    {
        $users->updateAccess($request->user(), $user, $request->validated());
        $state = $user->isActive() ? 'ativo' : 'inativo';

        return to_route('admin.users.index')
            ->with('success', "{$user->full_name} foi atualizado: {$user->role->label()}, {$state}.");
    }
}

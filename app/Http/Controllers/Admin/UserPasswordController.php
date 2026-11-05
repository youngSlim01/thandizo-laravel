<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResetUserPasswordRequest;
use App\Models\User;
use App\Services\UserAdminService;
use Illuminate\Http\RedirectResponse;

class UserPasswordController extends Controller
{
    /**
     * Repõe a palavra-passe de outra pessoa; a temporária é mostrada uma vez.
     */
    public function update(ResetUserPasswordRequest $request, User $user, UserAdminService $users): RedirectResponse
    {
        $users->resetPassword($request->user(), $user, $request->validated('password'));

        return to_route('admin.users.index')->with('credentials', [
            'full_name' => $user->full_name,
            'role_label' => $user->role->label(),
            'email' => $user->email,
            'password' => $request->validated('password'),
            'kind' => 'reset',
        ]);
    }
}

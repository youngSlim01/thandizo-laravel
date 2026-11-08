<?php

namespace App\Http\Controllers;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SystemAdminDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()->role === UserRole::SystemAdmin, 403);

        $activeUsers = User::where('status', RecordStatus::Active->value)->count();
        $pendingUsers = User::where('status', RecordStatus::Inactive->value)->count();
        $roleCounts = collect(UserRole::cases())->map(fn ($role) => [
            'label' => $role->label(),
            'count' => User::where('role', $role->value)->count(),
        ])->values();

        $cycles = DB::table('cycles')->count();
        $transactionCount = DB::table('transactions')->count();
        $transactionValue = (float) DB::table('transactions')->where('status', 'CONFIRMED')->sum('total_amount');
        $rejected = DB::table('transactions')->where('status', 'REJECTED')->where('created_at', '>=', now()->subDay())->count();
        $pendingTransactions = DB::table('transactions')->where('status', 'PENDING')->count();
        $agrodealers = DB::table('agrodealers')->count();

        $recentUsers = User::query()->latest()->limit(5)->get(['id', 'full_name', 'username', 'role', 'created_at']);
        $recentTransactions = DB::table('transactions')->latest()->limit(5)->get(['id', 'transaction_code', 'status', 'created_at']);
        $activity = $recentUsers->map(fn ($u) => [
            'type' => 'user', 'title' => 'Utilizador registado: '.$u->full_name,
            'detail' => $u->role->label(), 'date' => $u->created_at?->toISOString(),
        ])->concat($recentTransactions->map(fn ($t) => [
            'type' => 'transaction', 'title' => 'Transacção '.$t->transaction_code,
            'detail' => $t->status, 'date' => $t->created_at?->toISOString(),
        ]))->sortByDesc('date')->take(6)->values();

        return Inertia::render('system/dashboard', ['dashboard' => [
            'period' => now()->locale('pt')->translatedFormat('F Y'),\n            'users_active' => $activeUsers, 'users_pending' => $pendingUsers,
            'cycles_total' => $cycles, 'transactions_total' => $transactionCount,
            'transactions_value' => $transactionValue, 'agrodealers_total' => $agrodealers,
            'transactions_rejected_24h' => $rejected, 'transactions_pending' => $pendingTransactions,
            'roles' => $roleCounts, 'recent_activity' => $activity,
        ]]);
    }
}

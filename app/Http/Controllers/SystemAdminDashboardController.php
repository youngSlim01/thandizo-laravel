<?php

namespace App\\Http\\Controllers;

use App\\Enums\\RecordStatus;
use App\\Enums\\UserRole;
use App\\Models\\User;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Inertia\\Inertia;
use Inertia\\Response;

class SystemAdminDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()->role === UserRole::SystemAdmin, 403);

        $users = User::query();
        $activeUsers = (clone $users)->where('status', RecordStatus::Active)->count();
        $pendingUsers = (clone $users)->where('status', RecordStatus::Inactive)->count();
        $roles = UserRole::cases();
        $roleCounts = collect($roles)->map(fn ($role) => [
            'label' => $role->label(),
            'count' => User::where('role', $role)->count(),
        ])->values();

        $programmes = DB::table('cycles')
            ->select('programme_id')
            ->whereNotNull('programme_id')
            ->distinct()
            ->count();

        $transactions = DB::table('transactions');
        $transactionCount = (clone $transactions)->count();
        $transactionValue = (float) (clone $transactions)->where('status', 'CONFIRMED')->sum('total_amount');
        $rejected = (clone $transactions)->where('status', 'REJECTED')->where('created_at', '>=', now()->subDay())->count();
        $pendingTransactions = (clone $transactions)->where('status', 'PENDING')->count();
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

        return Inertia::render('system/dashboard', [
            'dashboard' => [
                'users_active' => $activeUsers,
                'users_pending' => $pendingUsers,
                'programmes_active' => $programmes,
                'transactions_total' => $transactionCount,
                'transactions_value' => $transactionValue,
                'agrodealers_total' => $agrodealers,
                'transactions_rejected_24h' => $rejected,
                'transactions_pending' => $pendingTransactions,
                'roles' => $roleCounts,
                'recent_activity' => $activity,
                'programmes' => [],
            ],
        ]);
    }
}

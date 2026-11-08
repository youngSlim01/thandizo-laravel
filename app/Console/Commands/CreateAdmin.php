<?php

namespace App\Console\Commands;

use App\Services\UserAdminService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Cria um administrador do sistema (o superuser de uma instalação), com uma
 * palavra-passe temporária forte, mostrada uma só vez e a trocar no primeiro
 * acesso. Para produção; os seeders só servem para desenvolvimento.
 */
#[Signature('thandizo:create-admin {--name= : Nome completo} {--email= : E-mail} {--username= : Nome de utilizador (opcional)}')]
#[Description('Cria um administrador do sistema com palavra-passe temporária')]
class CreateAdmin extends Command
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';

    public function handle(UserAdminService $users): int
    {
        $data = [
            'name' => $this->option('name') ?? $this->ask('Nome completo'),
            'email' => mb_strtolower(trim((string) ($this->option('email') ?? $this->ask('E-mail')))),
            'username' => mb_strtolower(trim((string) ($this->option('username') ?? $this->ask('Nome de utilizador (opcional, Enter para nenhum)', '')))),
        ];
        $data['username'] = $data['username'] === '' ? null : $data['username'];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'username' => ['nullable', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('users', 'username')],
        ], [
            'name.required' => 'Indique o nome completo.',
            'email.required' => 'Indique o e-mail.',
            'email.email' => 'E-mail inválido.',
            'email.unique' => 'Já existe uma conta com este e-mail.',
            'username.unique' => 'Já existe uma conta com este nome de utilizador.',
            'username.*' => 'Nome de utilizador inválido: 3 a 30 caracteres, letras minúsculas, números, ponto, hífen e sublinhado.',
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $password = $this->temporaryPassword();
        $user = $users->createSystemAdmin($data['name'], $data['email'], $data['username'], $password);

        $this->info("Administrador do sistema criado: {$user->full_name}");
        $this->line("  E-mail:                  {$user->email}");
        if ($user->username) {
            $this->line("  Nome de utilizador:      {$user->username}");
        }
        $this->line("  Palavra-passe temporária: {$password}");
        $this->warn('Guarde-a agora: não volta a ser mostrada. No primeiro acesso é pedida uma nova.');

        return self::SUCCESS;
    }

    /**
     * 14 caracteres sem letras parecidas (0/O, 1/l/I), para ler e escrever sem enganos.
     */
    private function temporaryPassword(): string
    {
        $max = strlen(self::ALPHABET) - 1;

        return implode('', array_map(fn (): string => self::ALPHABET[random_int(0, $max)], range(1, 14)));
    }
}

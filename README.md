# THANDIZO — Laravel

Plataforma de e-vouchers THANDIZO, reescrita em **Laravel 13 + Inertia.js +
React + MySQL**. Substitui o sistema Next.js + Supabase
(`youngslim01/voucher-link-gcf.sci`), que continua em produção até ao corte.

O plano completo da migração (decisões, ordem dos módulos, dia do corte) está
em [`docs/MIGRACAO_LARAVEL.md`](docs/MIGRACAO_LARAVEL.md).

## Estado

Fase 1 — fundação:

- Laravel 13, Inertia 3, React 19, TypeScript, Vite.
- O CSS do sistema actual (`resources/css/app.css`) é reaproveitado tal como
  está.
- Tabela `users` (UUID, papel, estado, troca obrigatória de palavra-passe),
  que junta `auth.users` e `app_users` do Supabase.
- Login, sessão e saída, com o mesmo ecrã do sistema actual. Utilizadores
  desativados não entram, e as tentativas são limitadas a 5 por minuto.

## Correr no GitHub Codespaces

No GitHub: **Code → Codespaces → Create codespace** na branch de trabalho.
Na primeira vez, o Codespace instala tudo (PHP 8.3, MySQL 8, Node 22), cria a
base de dados com dados de exemplo e compila o frontend. Isto demora alguns
minutos. No fim abre-se a porta 8000 (separador **PORTS**).

Login local: `admin@thandizo.test` / `password`.

## Correr localmente

Requisitos: PHP 8.3+, Composer, Node 22+, MySQL 8.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Criar as bases `thandizo` e `thandizo_test` no MySQL (utilizador e palavra-passe
em `.env` / `phpunit.xml`), depois:

```bash
php artisan migrate --seed
composer run dev
```

Utilizador local criado pelo seeder: `admin@thandizo.test` / `password`.

## Testes

Os testes correm contra MySQL (base `thandizo_test`), porque as protecções do
ledger vão usar triggers MySQL:

```bash
php artisan test
```

## Organização

| Onde | O quê |
|---|---|
| `app/Enums` | Papéis (`UserRole`) e estados |
| `app/Http/Controllers` | Controllers: recebem o pedido e devolvem `Inertia::render(...)` |
| `app/Services` | (a partir da fase 3) regras de negócio: o "motor" |
| `resources/js/pages` | Páginas React, uma por ecrã |
| `resources/js/components` | Componentes React partilhados |
| `app/Http/Middleware/HandleInertiaRequests.php` | Dados partilhados com todas as páginas (utilizador, mensagens) |

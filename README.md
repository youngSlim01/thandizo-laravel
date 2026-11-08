# THANDIZO — Laravel

Plataforma de e-vouchers THANDIZO, reescrita em **Laravel 13 + Inertia.js +
React + MySQL**. Substitui o sistema Next.js + Supabase
(`youngslim01/voucher-link-gcf.sci`), que continua em produção até ao corte.

O plano completo da migração (decisões, ordem dos módulos, dia do corte) está
em [`docs/MIGRACAO_LARAVEL.md`](docs/MIGRACAO_LARAVEL.md).

Decisões sobre o funcionamento do sistema e o caminho até aos novos clientes: [`docs/DECISOES.md`](docs/DECISOES.md).

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

O servidor arranca sozinho sempre que o Codespace inicia. Se a página der
erro 502 (servidor parado), arranca-o de novo com:

```bash
bash .devcontainer/start-server.sh
```

## Correr localmente

Requisitos: PHP 8.3+, Composer, Node 22+, MySQL 8.

### No Mac (Homebrew), passo a passo

Uma só vez: ferramentas, MySQL com a opção dos triggers, bases e utilizador.

```bash
brew install php composer node mysql@8.4 gh
echo 'export PATH="$(brew --prefix mysql@8.4)/bin:$PATH"' >> ~/.zshrc && source ~/.zshrc
printf '[mysqld]\nlog_bin_trust_function_creators=1\n' >> "$(brew --prefix)/etc/my.cnf"
brew services start mysql@8.4
mysql -uroot -e "CREATE DATABASE thandizo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE thandizo_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'thandizo'@'localhost' IDENTIFIED BY 'secret'; GRANT ALL ON thandizo.* TO 'thandizo'@'localhost'; GRANT ALL ON thandizo_test.* TO 'thandizo'@'localhost';"
```

Trazer o projecto e instalar (o branch de trabalho é `claude/ecstatic-hopper-9mpu3l`):

```bash
gh auth login
gh repo clone youngslim01/thandizo-laravel ~/thandizo-laravel -- -b claude/ecstatic-hopper-9mpu3l
cd ~/thandizo-laravel
composer setup
php artisan db:seed --class=DemoSeeder
php artisan serve
```

Abrir http://localhost:8000. Para parar o servidor: Ctrl + C.

Manter sincronizado (traz as últimas alterações e actualiza tudo, sem apagar
os dados): `composer actualizar`, depois `php artisan serve`.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Criar as bases `thandizo` e `thandizo_test` no MySQL (utilizador e palavra-passe
em `.env` / `phpunit.xml`), depois:

```bash
php artisan migrate --seed --seeder=DemoSeeder
composer run dev
```

O `DemoSeeder` cria dados para experimentar: 3 produtos com preço, um ciclo
aberto e 20 agregados com cartão entregue (`VCH-MUT-000001-8`, …). Utilizadores,
todos com a palavra-passe `password`: `admin`, `operador`, `gestor-agro`,
`meal`, `gestor-meal` e `financas` `@thandizo.test` (ou só o nome, por exemplo `operador`, como nome de utilizador).

O cliente de cada instalação (organização, programa e frase do cliente) e os
logótipos impressos nos cartões definem-se no próprio sistema, em Configuração
(administrador do sistema), e aparecem junto do nome THANDIZO nos ecrãs, no
recibo, no cartão e nos PDFs. Sem cliente, só aparece THANDIZO com a frase do
produto.

O MySQL precisa de `log_bin_trust_function_creators=1` para criar os triggers
(`SET GLOBAL log_bin_trust_function_creators=1;`).

Em produção (ou numa instalação nova) o primeiro administrador do sistema
cria-se com um comando, que gera uma palavra-passe temporária a trocar no
primeiro acesso; os seeders são só para desenvolvimento:

```bash
php artisan thandizo:create-admin
```

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

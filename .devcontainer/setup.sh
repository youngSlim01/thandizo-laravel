#!/usr/bin/env bash
# Prepara o projecto na primeira vez que o Codespace é criado.
set -euo pipefail

composer install --no-interaction
npm install

if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --no-interaction
fi

echo "A aguardar o MySQL..."
for _ in $(seq 1 60); do
    if php -r 'new PDO("mysql:host=127.0.0.1;port=3306;dbname=thandizo", "thandizo", "secret");' 2>/dev/null; then
        break
    fi
    sleep 2
done

php artisan migrate --seed --seeder=DemoSeeder --no-interaction
npm run build

echo
echo "Pronto. Abre a porta 8000 (separador PORTS)."
echo "Contas de demonstração (palavra-passe: password):"
echo "  admin, gestor-meal, meal, financas, operador, gestor-agro"

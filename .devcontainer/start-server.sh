#!/usr/bin/env bash
# Arranca o servidor Laravel em segundo plano sempre que o Codespace inicia.
# Registo em storage/logs/serve.log. Para parar: pkill -f "artisan serve"
cd "$(dirname "$0")/.."

if pgrep -f "artisan serve" >/dev/null; then
    echo "O servidor já está a correr (porta 8000)."
    exit 0
fi

setsid nohup php artisan serve --host=0.0.0.0 --port=8000 > storage/logs/serve.log 2>&1 < /dev/null &
echo "Servidor a arrancar na porta 8000 (registo: storage/logs/serve.log)."

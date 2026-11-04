-- Base para os testes (php artisan test usa thandizo_test, ver phpunit.xml).
CREATE DATABASE IF NOT EXISTS thandizo_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON thandizo_test.* TO 'thandizo'@'%';
FLUSH PRIVILEGES;

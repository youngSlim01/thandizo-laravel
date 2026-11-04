# THANDIZO — Plano de migração para Laravel

Documento de trabalho. Regista o que já foi decidido, o que a auditoria do
repositório mostrou e as decisões que ainda estão em aberto.

## 1. Decisões tomadas

| Tema | Decisão |
|---|---|
| Linguagem do backend | PHP / Laravel |
| Frontend | React, ligado ao Laravel por Inertia.js |
| Regras de negócio ("motor") | Passam do PostgreSQL (funções SQL) para PHP (Services Laravel) |
| Base de dados | Migra de PostgreSQL (Supabase) para **MySQL**, com os dados existentes |
| Visual | Mantém-se: JSX e `app/globals.css` são reaproveitados |
| Motivo do MySQL | Familiaridade da equipa |
| Estratégia | **Migração de uma só vez:** o novo sistema é construído já sobre MySQL; o sistema actual continua em produção até ao dia do corte |
| Schema | Gerido por migrações Laravel, escritas de raiz para MySQL |
| Autenticação | Laravel (tabela `users`); deixa de depender do Supabase Auth |
| Repositório (D1) | **Repositório novo** só para o Laravel; este fica como referência até ao corte |
| Protecções na BD (D4) | **Recriar em MySQL** os triggers de imutabilidade (ledger, itens, transacções) e `CHECK` de saldo ≥ 0; RLS não é recriada (passa para Policies) |
| Testes (D5) | Os 25 testes SQL são reescritos em Pest/PHPUnit **antes** de cada Service |
| Ordem (D6) | Do menos dependente para o mais dependente (secção 7) |
| Alojamento (D7) | **Laravel Cloud**, com base de dados MySQL gerida pelo Laravel Cloud no mesmo ambiente/região |

## 2. O que existe hoje (auditoria)

| Item | Valor |
|---|---|
| Código da aplicação (React + CSS) | ~10 mil linhas, 54 componentes de cliente |
| Rotas de servidor no Next.js | 2 (`app/api/admin/users/...`) |
| Funções SQL chamadas pelo frontend | ~55 (de 102 definidas) |
| Políticas RLS | 40 |
| Uso de `auth.uid()` nas funções SQL | 177 |
| Triggers de protecção (ledger e transacções imutáveis) | 6 |
| Testes SQL de regras de negócio | 25 ficheiros em `supabase/tests` |
| Ficheiros que chamam o Supabase directamente | 30 |
| Ficheiros que usam `next/link` / `next/navigation` | 16 / 15 |

Conclusão: hoje o motor está no PostgreSQL, e o Next.js é sobretudo uma casca
à volta do React. A migração consiste em trocar a casca (Next.js → Laravel +
Inertia) e reescrever o motor (SQL → PHP).

## 3. Inventário do motor por módulo

Cada função SQL abaixo dá origem a um Service PHP e aos testes correspondentes.

| Módulo | Funções SQL actuais | Risco |
|---|---|---|
| Utilizadores e acessos | `admin_set_app_user`, `admin_list_app_users`, `admin_require_password_change`, `mark_password_changed` | Médio |
| Geografia do programa | `list_programme_districts`, `add_programme_district`, `list_household_geography` | Baixo |
| Produtos e preços | `save_product`, `set_product_price`, `list_product_prices`, `import_product_prices`, `get_agrodealer_catalogue`, `set_agrodealer_product` | Baixo |
| Agrodealers e POS | `save_agrodealer`, `save_pos`, `list_agrodealers_admin` | Baixo |
| Beneficiários | `create_household`, `update_household`, `delete_household`, `set_household_status`, `search_households`, `get_household_detail`, `get_household_history`, `import_households` | Médio |
| Cartões | `generate_card_batch`, `list_card_batches`, `get_card_batch_cards`, `mark_card_batch_printed`, `cancel_card_batch`, `distribute_cards`, `find_household_cards`, `list_card_communities` | Médio |
| Ciclos | `open_programme_cycle`, `close_programme_cycle`, `allocate_cycle`, `cycle_summary`, `list_programme_cycles` | Alto (mexe em saldos) |
| POS e resgate | `lookup_voucher_for_pos`, `preview_voucher_redemption`, `confirm_pos_redemption`, `list_my_recent_transactions`, `get_transaction_receipt`, `void_voucher_transaction` | Alto (dinheiro) |
| Transacções e relatórios | `search_transactions`, `agrodealer_transactions_report`, `list_report_prints`, `meal_dashboard_month`, `finance_dashboard_month` | Baixo (só leitura) |
| Reconciliação | `list_settlements`, `create_agrodealer_settlement`, `submit_agrodealer_settlement`, `manager_approve_settlement`, `manager_return_settlement`, `finance_validate_settlement`, `finance_return_settlement` | Alto (dinheiro) |

## 4. Impacto da passagem para MySQL

| Hoje no PostgreSQL | Quantidade | Em MySQL / Laravel |
|---|---|---|
| Funções plpgsql (`security definer`) | 141 | Já iam ser reescritas como Services PHP |
| `jsonb` / `json_build_object` / `json_agg` | 862 / 98 | Quase tudo dentro das funções: desaparece com elas. Colunas JSON → `json` do MySQL |
| Arrays (`[]`) | 174 | Sem equivalente em MySQL: tabelas filhas ou `json` |
| Enums (`create type ... as enum`) | 7 | `enum` do MySQL ou enums PHP com coluna `string` |
| `uuid` / `gen_random_uuid()` | 695 / 46 | `char(36)` + `HasUuids` do Laravel |
| `timestamptz` | 69 | `timestamp` em UTC; o fuso fica a cargo da aplicação |
| `ilike` | 22 | `like` com collation `*_ci` |
| `date_trunc` | 24 | `DATE_FORMAT` ou cálculo em PHP |
| Políticas RLS | 40 | **Não existem em MySQL:** passam para Policies, scopes e middleware do Laravel |
| Triggers de imutabilidade do ledger | 6 | Possíveis em MySQL (`SIGNAL SQLSTATE`) |
| `select ... for update` | 39 | Igual: `lockForUpdate()` em `DB::transaction` (InnoDB) |
| Ligação a `auth.users` (Supabase Auth) | 2 FKs | Tabela `users` do Laravel |

## 5. O que muda no frontend

- **Fica:** JSX, `globals.css`, leitor de QR (`jsqr`), PDF (`jspdf`), Excel.
- **Muda:**
  - `callRpc(...)` / `getSupabase()` → dados recebidos como props do
    controller; acções enviadas com `router.post/put/delete` do Inertia.
  - `next/link` → `Link` do Inertia; `next/navigation` → `router` do Inertia.
  - `app/auth-context.tsx` → utilizador partilhado pelo Laravel
    (`HandleInertiaRequests`).
  - Páginas `app/**/page.tsx` → `resources/js/Pages/**`.

## 6. Migração dos dados e dia do corte

Como o corte é feito de uma só vez, a migração dos dados é um projecto à
parte e tem de ser ensaiada várias vezes antes do dia.

- **Comando Laravel de migração** (`php artisan thandizo:import-supabase`) com
  duas ligações (`pgsql` de origem, `mysql` de destino). Tem de poder correr
  várias vezes do zero, porque o sistema antigo continua a receber dados até
  ao corte.
- **Ordem:** geografia → produtos e preços → agrodealers e POS → utilizadores
  → agregados familiares → cartões e vouchers → ciclos → transacções e itens
  → ledger → reconciliações → auditoria.
- **Utilizadores:** copiar o hash bcrypt de `auth.users.encrypted_password`.
  Ainda falta confirmar se o Laravel aceita o prefixo `$2a$` ou se é preciso
  normalizar para `$2y$`. Os UUID de utilizador mantêm-se, para não partir
  as referências.
- **Verificações obrigatórias depois de cada ensaio** (o corte só avança se
  todas baterem certo):
  - contagem de linhas por tabela, origem = destino;
  - saldo de cada voucher = soma do seu ledger, em MySQL;
  - soma dos saldos, das transacções e dos copagamentos, origem = destino;
  - estado de cada reconciliação, origem = destino.
- **No dia do corte:**
  1. congelar o sistema antigo (só leitura);
  2. correr a migração final e as verificações;
  3. apontar o domínio para o Laravel.

  O sistema antigo fica disponível em leitura durante algum tempo, como
  plano de recuo.

## 7. Ordem de construção (D6) — do menos ao mais dependente

Derivada das chaves estrangeiras do schema actual. Cada nível só depende dos
anteriores. As tabelas `programmes`, `redemptions` e `redemption_items` são legado
(removidas ou movidas para o schema `legacy` em `20261008113400`) e não passam
para MySQL. "Programa" no sistema actual é a marca `districts.programme_active`
e os ciclos (`programme_cycles`, nível 8).

`audit_logs` foi antecipada para o nível 0: só depende de `users` (que já
existe desde a fundação) e a primeira regra migrada escreve nela.

| Nível | Módulo | Tabelas |
|---|---|---|
| 0 | Geografia base ✅ | `provinces`, `districts` (+ `audit_logs`, antecipada) |
| 1 | Geografia detalhada | `administrative_posts`, `localities`, `communities` |
| 2 | Produtos | `products` |
| 3 | Agrodealers e POS | `agrodealers`, `agrodealer_pos` |
| 4 | Utilizadores e acessos | `users` (ex-`app_users` + `auth.users`) |
| 5 | Preços e catálogos | `price_lists`, `agrodealer_products` |
| 6 | Beneficiários | `household_import_batches`, `households` |
| 7 | Cartões e vouchers | `card_batches`, `vouchers` |
| 8 | Ciclos | `programme_cycles`, `cycle_households` |
| 9 | POS e resgate | `transactions`, `transaction_items`, `voucher_ledger`, `reversals` |
| 10 | Reconciliação | `settlement_batches`, `settlement_items` |
| 11 | Relatórios e dashboards | só leitura sobre todos os anteriores |

**Atenção ao nível 4.** Quase todas as tabelas têm `created_by` → utilizador,
mas o utilizador depende de `agrodealers` e `provinces`. Para se conseguir
entrar no sistema desde o início, a fundação cria já a tabela `users` com
login e papel. As colunas `province_id` e `agrodealer_id` (e as suas chaves
estrangeiras) entram quando os níveis 0 e 3 existirem.

## 7b. Notas sobre D4 (protecções na base de dados)

Os triggers (`SIGNAL SQLSTATE '45000'`) ficam nas migrações Laravel via
`DB::unprepared`. O motivo para os recriar é que, num sistema que guarda
dinheiro de beneficiários, a base de dados deve recusar alterar ou apagar o
histórico, mesmo que um bug no PHP ou uma consulta manual na consola o tente.
O custo é baixo: 6 triggers. A anulação de vendas continua a ser feita como
hoje, com um movimento inverso (`reversals`) e nunca apagando registos. Os
testes Pest verificam que a base de dados recusa estas operações.

## 8. Fases (rascunho)

1. **Fundação:** repositório novo, Laravel + Inertia + React + MySQL, layout
   com o CSS actual, tabela `users` com login, ligação ao Laravel Cloud.
2. **Autenticação e papéis:** login, sessão, troca obrigatória de palavra-passe,
   Policies e scopes que substituem as 40 políticas RLS (incluindo o âmbito
   por província do MEAL Officer).
3. **Módulos:** para cada um, testes → Service → páginas Inertia, pela ordem
   da secção 7.
4. **Migração dos dados:** comando de importação e verificações (secção 6),
   ensaiado com cópias da produção.
5. **Homologação:** utilizadores reais testam com dados migrados.
6. **Corte:** secção 6.

Até ao corte, o sistema Next.js + Supabase actual continua em produção sem
alterações.

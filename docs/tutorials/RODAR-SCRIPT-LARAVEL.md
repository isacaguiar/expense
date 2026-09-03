No diretório do Laravel em prod (/home1/isacag00/novemax/expense/api/):

```bash
php artisan migrate:status
```

Deve listar 2026_09_02_000000_add_settled_at_to_ex_group_cycle_snapshots_table como Pending. Então:

```bash
php artisan migrate --force
```
# Database Migrations

The CMS component includes database migration capabilities powered by [Phinx](https://phinx.org/). This allows you to version control your database schema and manage changes across different environments.

## Configuration

Add the following configuration to your `config/config.yaml`:

```yaml
database:
  # Database adapter (mysql, pgsql, sqlite)
  adapter: mysql

  # Database host
  host: localhost

  # Database name
  name: neuron_cms

  # Database username
  user: root

  # Database password
  pass: secret

  # Database port (3306 for MySQL, 5432 for PostgreSQL)
  port: 3306

  # Character set
  charset: utf8mb4

migrations:
  # Path to migrations directory (relative to project root)
  path: db/migrate

  # Path to seeds directory (relative to project root)
  seeds_path: db/seed

  # Migration tracking table name
  table: phinx_log
```

See `resources/config/database.yaml.example` for a complete configuration example.

## Directory Structure

Migrations and seeders are stored in your project root (not in vendor):

```
your-project/
├── config/
│   └── config.yaml          # Database configuration
├── db/
│   ├── migrate/             # Migration files
│   └── seed/                # Seeder files
└── vendor/
    └── neuron-php/cms/
```

## Available Commands

### Create Migration

Create a new migration file:

```bash
php neuron db:migration:generate CreateUsersTable
```

Options:
- `--class` - Use a specific class name
- `--template` - Use a custom migration template
- `--config` - Path to configuration directory (defaults to auto-detection)

This creates a timestamped migration file in `db/migrate/`:

```php
<?php

use Phinx\Migration\AbstractMigration;

class CreateUsersTable extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('users');
        $table->addColumn('email', 'string', ['limit' => 255])
              ->addColumn('password', 'string', ['limit' => 255])
              ->addColumn('created_at', 'datetime')
              ->addColumn('updated_at', 'datetime', ['null' => true])
              ->addIndex(['email'], ['unique' => true])
              ->create();
    }
}
```

### Run Migrations

Execute all pending migrations:

```bash
php neuron db:migrate
```

Options:
- `--target` - Migrate to a specific version
- `--date` - Migrate to a specific date (YYYYMMDD format)
- `--dry-run` - Show migrations without executing
- `--fake` - Mark migrations as run without executing

Examples:

```bash
# Run all pending migrations
php neuron db:migrate

# Migrate to specific version
php neuron db:migrate --target=20241105120000

# Dry run to see what would be executed
php neuron db:migrate --dry-run
```

### Rollback Migrations

Rollback the last migration:

```bash
php neuron db:rollback
```

Options:
- `--target` - Rollback to a specific version
- `--date` - Rollback to a specific date
- `--force` - Skip confirmation prompt
- `--dry-run` - Show what would be rolled back
- `--fake` - Mark as rolled back without executing

Examples:

```bash
# Rollback last migration (with confirmation)
php neuron db:rollback

# Rollback to specific version without confirmation
php neuron db:rollback --target=20241105120000 --force

# Dry run to see what would be rolled back
php neuron db:rollback --dry-run
```

### Check Migration Status

View the status of all migrations:

```bash
php neuron db:migrate:status
```

Options:
- `--format` - Output format (text or json)

Output example:

```
Status  Migration ID    Migration Name
--------------------------------------
   up   20241105120000  CreateUsersTable
   up   20241105130000  CreatePostsTable
  down  20241105140000  AddCommentsTable
```

### Run Seeders

Populate the database with test or initial data:

```bash
php neuron db:seed
```

Options:
- `--seed` or `-s` - Run a specific seeder class
- `--config` - Path to configuration directory

Examples:

```bash
# Run all seeders
php neuron db:seed

# Run specific seeder
php neuron db:seed --seed=UserSeeder
```

Create a seeder file in `db/seed/`:

```php
<?php

use Phinx\Seed\AbstractSeed;

class UserSeeder extends AbstractSeed
{
    public function run()
    {
        $data = [
            [
                'email' => 'admin@example.com',
                'password' => password_hash('secret', PASSWORD_BCRYPT),
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'email' => 'user@example.com',
                'password' => password_hash('secret', PASSWORD_BCRYPT),
                'created_at' => date('Y-m-d H:i:s'),
            ]
        ];

        $users = $this->table('users');
        $users->insert($data)->save();
    }
}
```

## Migration Examples

### Creating Tables

```php
public function change()
{
    $table = $this->table('posts');
    $table->addColumn('title', 'string', ['limit' => 255])
          ->addColumn('body', 'text')
          ->addColumn('user_id', 'integer')
          ->addColumn('published_at', 'datetime', ['null' => true])
          ->addColumn('created_at', 'datetime')
          ->addColumn('updated_at', 'datetime', ['null' => true])
          ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
          ->create();
}
```

### Modifying Tables

```php
public function change()
{
    $table = $this->table('users');
    $table->addColumn('phone', 'string', ['limit' => 20, 'null' => true, 'after' => 'email'])
          ->addColumn('status', 'string', ['limit' => 20, 'default' => 'active'])
          ->addIndex(['status'])
          ->update();
}
```

### Data Migrations

```php
public function up()
{
    $this->execute("UPDATE users SET status = 'active' WHERE status IS NULL");
}

public function down()
{
    $this->execute("UPDATE users SET status = NULL WHERE status = 'active'");
}
```

## Best Practices

1. **Always test migrations** - Test both up and down migrations in a development environment

2. **Use change() when possible** - Phinx can automatically reverse most migration operations

3. **Use up() and down() for complex migrations** - When automatic reversal isn't possible

4. **Keep migrations focused** - One logical change per migration file

5. **Don't modify old migrations** - Create new migrations to change existing tables

6. **Use seeders for test data** - Keep production data separate from migrations

7. **Version control everything** - Commit migration files to your repository

8. **Back up before rollback** - Always backup production databases before rolling back

## Database Support

The migration system supports the following database adapters:

- **MySQL** - adapter: mysql
- **PostgreSQL** - adapter: pgsql
- **SQLite** - adapter: sqlite

## Troubleshooting

### Migrations directory not found

If you see "Migrations directory not found", ensure:
1. The `migrations.path` is set in your config.yaml
2. The directory exists or run a migration command to auto-create it

### Configuration not found

If configuration is not detected automatically:
1. Use the `--config` option to specify the path
2. Ensure config.yaml exists in the specified directory

### Database connection errors

Verify your database configuration:
1. Check database credentials in config.yaml
2. Ensure the database exists
3. Verify the database server is running
4. Check network connectivity to the database host

## Further Reading

For advanced Phinx features and configuration options, see:
- [Phinx Documentation](https://docs.phinx.org/)
- [Writing Migrations](https://docs.phinx.org/en/latest/migrations.html)
- [Seeding Data](https://docs.phinx.org/en/latest/seeding.html)

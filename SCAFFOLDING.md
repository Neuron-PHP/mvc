# Code Scaffolding

The MVC component includes code generation commands to quickly scaffold controllers, events, and listeners. These generators follow best practices and create production-ready code with sensible defaults.

## Available Generators

- **Controller Generator** - Create RESTful controllers with views and routes
- **Event Generator** - Create event classes
- **Listener Generator** - Create event listeners with automatic registration
- **Job Generator** - Create scheduled job classes with automatic scheduling

## Controller Generator

Generate RESTful controllers with Bootstrap 5 views and route definitions.

### Basic Usage

```bash
php neuron controller:generate Post
```

This creates:
- Controller: `app/Controllers/PostController.php`
- Views: `resources/views/post/index.php`, `create.php`, `edit.php`
- Routes: Appends to `config/routes.yaml`

### Generated Controller Structure

```php
<?php

namespace App\Controllers;

use Neuron\Mvc\Controllers\Base;

class PostController extends Base
{
    // Display listing
    public function index()
    {
        // TODO: Fetch posts from database
        $posts = [];

        $this->renderHtml('post/index', [
            'posts' => $posts
        ]);
    }

    // Show create form
    public function create()
    {
        $this->renderHtml('post/create');
    }

    // Store new post
    public function store()
    {
        // TODO: Validate and save post

        header('Location: /posts');
        exit;
    }

    // Show edit form
    public function edit( int $id )
    {
        // TODO: Fetch post by ID
        $post = null;

        $this->renderHtml('post/edit', [
            'post' => $post
        ]);
    }

    // Update existing post
    public function update( int $id )
    {
        // TODO: Validate and update post

        header('Location: /posts');
        exit;
    }

    // Delete post
    public function destroy( int $id )
    {
        // TODO: Delete post

        header('Location: /posts');
        exit;
    }
}
```

### Generated Routes

```yaml
posts_index:
  controller: App\Controllers\PostController
  method: index
  request_method: GET
  route: /posts

posts_create:
  controller: App\Controllers\PostController
  method: create
  request_method: GET
  route: /posts/create

posts_store:
  controller: App\Controllers\PostController
  method: store
  request_method: POST
  route: /posts

posts_edit:
  controller: App\Controllers\PostController
  method: edit
  request_method: GET
  route: /posts/{id}/edit

posts_update:
  controller: App\Controllers\PostController
  method: update
  request_method: POST
  route: /posts/{id}

posts_destroy:
  controller: App\Controllers\PostController
  method: destroy
  request_method: POST
  route: /posts/{id}/delete
```

### Options

#### Custom Namespace

```bash
php neuron controller:generate Post --namespace="Cms\Controllers"
```

Creates controller in `app/Cms/Controllers/PostController.php`

#### Nested Controllers

```bash
php neuron controller:generate Admin/Post
```

Creates:
- Controller: `app/Controllers/Admin/PostController.php`
- Views: `resources/views/admin/post/`
- Routes: `/admin/posts/*`

#### API Controller

Generate a controller that returns JSON responses:

```bash
php neuron controller:generate Post --api
```

Creates an API controller with JSON responses:

```php
public function index()
{
    $posts = [];

    $this->renderJson([
        'success' => true,
        'data' => $posts
    ]);
}
```

API controllers skip view generation automatically.

#### Custom Model Name

```bash
php neuron controller:generate Post --model="Article"
```

Uses "Article" as the model name in comments and variable names.

#### Route Filters

Add authentication or other filters to generated routes:

```bash
php neuron controller:generate Admin/Post --filter="auth"
```

Generates routes with filter:

```yaml
admin_posts_index:
  controller: App\Controllers\Admin\PostController
  method: index
  request_method: GET
  route: /admin/posts
  filter: auth
```

#### Skip Views

```bash
php neuron controller:generate Post --no-views
```

Generates controller and routes only, no views.

#### Skip Routes

```bash
php neuron controller:generate Post --no-routes
```

Generates controller and views only, no routes.

#### Force Overwrite

```bash
php neuron controller:generate Post --force
```

Overwrites existing files without prompting.

### Complete Example

```bash
php neuron controller:generate Admin/Product \
  --namespace="Cms\Controllers" \
  --model="Product" \
  --filter="auth" \
  --force
```

## Event Generator

Generate event classes for the event system.

### Basic Usage

```bash
php neuron event:generate UserRegistered
```

Creates: `app/Events/UserRegistered.php`

```php
<?php

namespace App\Events;

use Neuron\Events\IEvent;

class UserRegistered implements IEvent
{
}
```

### With Properties

```bash
php neuron event:generate UserRegistered \
  --property="userId:int" \
  --property="email:string" \
  --property="registeredAt:DateTimeImmutable"
```

Creates:

```php
<?php

namespace App\Events;

use Neuron\Events\IEvent;

class UserRegistered implements IEvent
{
    public int $userId;
    public string $email;
    public DateTimeImmutable $registeredAt;

    public function __construct(int $userId, string $email, DateTimeImmutable $registeredAt)
    {
        $this->userId = $userId;
        $this->email = $email;
        $this->registeredAt = $registeredAt;
    }
}
```

### Options

#### Custom Namespace

```bash
php neuron event:generate OrderPlaced --namespace="Cms\Events"
```

#### Force Overwrite

```bash
php neuron event:generate UserRegistered --force
```

### Emitting Events

After generating an event, emit it in your code:

```php
use Neuron\Application\CrossCutting\Event;
use App\Events\UserRegistered;

// Emit the event
Event::emit(new UserRegistered(
    userId: 123,
    email: 'user@example.com',
    registeredAt: new DateTimeImmutable()
));
```

## Listener Generator

Generate listener classes and automatically register them in `config/event-listeners.yaml`.

### Basic Usage

```bash
php neuron listener:generate SendWelcomeEmail --event="App\Events\UserRegistered"
```

Creates: `app/Listeners/SendWelcomeEmail.php`

```php
<?php

namespace App\Listeners;

use Neuron\Events\IListener;
use App\Events\UserRegistered;

class SendWelcomeEmail implements IListener
{
    public function event( $Event )
    {
        // TODO: Implement event handling logic
    }
}
```

**Automatically updates** `config/event-listeners.yaml`:

```yaml
events:
  userRegistered:
    class: 'App\Events\UserRegistered'
    listeners:
      - 'App\Listeners\SendWelcomeEmail'
```

### Multiple Listeners

Add multiple listeners to the same event:

```bash
php neuron listener:generate SendWelcomeEmail --event="App\Events\UserRegistered"
php neuron listener:generate CreateUserProfile --event="App\Events\UserRegistered"
php neuron listener:generate LogRegistration --event="App\Events\UserRegistered"
```

Updates `config/event-listeners.yaml`:

```yaml
events:
  userRegistered:
    class: 'App\Events\UserRegistered'
    listeners:
      - 'App\Listeners\SendWelcomeEmail'
      - 'App\Listeners\CreateUserProfile'
      - 'App\Listeners\LogRegistration'
```

### Options

#### Custom Namespace

```bash
php neuron listener:generate SendEmail \
  --event="App\Events\UserRegistered" \
  --namespace="Cms\Listeners"
```

#### Force Overwrite

```bash
php neuron listener:generate SendWelcomeEmail \
  --event="App\Events\UserRegistered" \
  --force
```

### Implementing Listener Logic

Edit the generated listener to add your logic:

```php
<?php

namespace App\Listeners;

use Neuron\Events\IListener;
use App\Events\UserRegistered;

class SendWelcomeEmail implements IListener
{
    public function event( $Event )
    {
        if (!$Event instanceof UserRegistered) {
            return;
        }

        // Send welcome email
        $this->sendEmail(
            to: $Event->email,
            subject: 'Welcome!',
            body: $this->getWelcomeEmailBody()
        );
    }

    private function sendEmail(string $to, string $subject, string $body): void
    {
        // TODO: Implement email sending
    }

    private function getWelcomeEmailBody(): string
    {
        return 'Welcome to our application!';
    }
}
```

## Job Generator

Generate scheduled job classes that integrate with the Neuron Jobs component for cron-based task execution.

### Basic Usage

```bash
php neuron job:generate SendEmailReminders
```

Creates: `app/Jobs/SendEmailReminders.php`

```php
<?php

namespace App\Jobs;

use Neuron\Jobs\IJob;
use Neuron\Log\Log;

class SendEmailReminders implements IJob
{
    public function getName(): string
    {
        return 'send_email_reminders';
    }

    public function run(array $Argv = []): mixed
    {
        Log::info("SendEmailReminders job started");

        // TODO: Implement job logic here

        Log::info("SendEmailReminders job completed");

        return true;
    }
}
```

### With Scheduling

Generate a job and automatically add it to `config/schedule.yaml`:

```bash
php neuron job:generate SendEmailReminders --cron="0 9 * * *"
```

This creates the job class **and** updates `config/schedule.yaml`:

```yaml
schedule:
  sendEmailReminders:
    class: 'App\Jobs\SendEmailReminders'
    cron: "0 9 * * *"
    args: []
```

The command validates the cron expression and shows when the job will next run:

```
Cron expression validated: 0 9 * * *
Next run: 2025-11-09 09:00:00
```

### Cron Expression Examples

Common cron patterns:

```bash
# Every minute
php neuron job:generate ProcessQueue --cron="* * * * *"

# Every 15 minutes
php neuron job:generate CleanupTemp --cron="*/15 * * * *"

# Daily at 2:00 AM
php neuron job:generate BackupDatabase --cron="0 2 * * *"

# Every 6 hours
php neuron job:generate SyncData --cron="0 */6 * * *"

# Weekly on Sunday at midnight
php neuron job:generate WeeklyReport --cron="0 0 * * 0"

# Monthly on the 1st at 3:00 AM
php neuron job:generate MonthlyInvoice --cron="0 3 1 * *"

# Weekdays at 8:00 AM
php neuron job:generate DailyReport --cron="0 8 * * 1-5"
```

### Options

#### Custom Namespace

```bash
php neuron job:generate CleanupLogs --namespace="Cms\Jobs"
```

Creates job in `app/Cms/Jobs/CleanupLogs.php`

#### Force Overwrite

```bash
php neuron job:generate SendEmailReminders --cron="0 10 * * *" --force
```

Overwrites existing job and updates schedule.

### Implementing Job Logic

Edit the generated job to add your business logic:

```php
<?php

namespace App\Jobs;

use Neuron\Jobs\IJob;
use Neuron\Log\Log;

class SendEmailReminders implements IJob
{
    public function getName(): string
    {
        return 'send_email_reminders';
    }

    public function run(array $Argv = []): mixed
    {
        Log::info("SendEmailReminders job started");

        // Get users who need reminders
        $users = $this->getUsersNeedingReminders();

        foreach ($users as $user) {
            $this->sendReminderEmail($user);
        }

        Log::info("SendEmailReminders job completed - sent " . count($users) . " reminders");

        return true;
    }

    private function getUsersNeedingReminders(): array
    {
        // TODO: Query database for users
        return [];
    }

    private function sendReminderEmail($user): void
    {
        // TODO: Send email
        Log::debug("Sent reminder to: {$user->email}");
    }
}
```

### Using Job Arguments

Jobs can accept arguments from the schedule configuration:

Update `config/schedule.yaml`:

```yaml
schedule:
  sendEmailReminders:
    class: 'App\Jobs\SendEmailReminders'
    cron: "0 9 * * *"
    args:
      reminder_type: 'payment_due'
      days_before: 7
```

Access arguments in your job:

```php
public function run(array $Argv = []): mixed
{
    $reminderType = $Argv['reminder_type'] ?? 'general';
    $daysBefore = $Argv['days_before'] ?? 3;

    Log::info("Sending {$reminderType} reminders for {$daysBefore} days");

    // Use the arguments in your logic
    $users = $this->getUsersNeedingReminders($reminderType, $daysBefore);

    // ...
}
```

### Running Jobs

Once generated and scheduled, run the job scheduler:

```bash
# Run scheduler in infinite loop (daemon mode)
php neuron jobs:schedule

# Run a single poll and exit (for cron)
php neuron jobs:schedule --poll

# Custom polling interval (30 seconds)
php neuron jobs:schedule --interval=30

# Debug mode (single poll then exit)
php neuron jobs:schedule --debug
```

#### Production Setup

For production, use system cron to run the scheduler:

```bash
# Add to crontab (runs every minute)
* * * * * cd /path/to/project && php neuron jobs:schedule --poll >> /dev/null 2>&1
```

Or run as a daemon with a process manager like supervisor:

```ini
[program:neuron-scheduler]
command=php /path/to/project/neuron jobs:schedule
directory=/path/to/project
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/log/neuron-scheduler.log
stderr_logfile=/var/log/neuron-scheduler-error.log
```

## Workflow Examples

### Creating a Complete Feature

```bash
# 1. Generate the controller with views and routes
php neuron controller:generate Product --filter="auth"

# 2. Generate events for product actions
php neuron event:generate ProductCreated --property="productId:int"
php neuron event:generate ProductUpdated --property="productId:int"
php neuron event:generate ProductDeleted --property="productId:int"

# 3. Generate listeners
php neuron listener:generate UpdateInventory --event="App\Events\ProductCreated"
php neuron listener:generate SendNotification --event="App\Events\ProductCreated"
php neuron listener:generate ClearProductCache --event="App\Events\ProductUpdated"
```

### Creating an API with Events

```bash
# 1. Generate API controller
php neuron controller:generate Api/User --api --namespace="App\Controllers\Api"

# 2. Generate events
php neuron event:generate UserCreated \
  --property="userId:int" \
  --property="email:string"

# 3. Generate listeners
php neuron listener:generate SendVerificationEmail --event="App\Events\UserCreated"
php neuron listener:generate CreateDefaultProfile --event="App\Events\UserCreated"
```

### Scheduled Jobs with Events

```bash
# 1. Generate scheduled jobs
php neuron job:generate SendDailyDigest --cron="0 8 * * *"
php neuron job:generate CleanupExpiredSessions --cron="0 */6 * * *"
php neuron job:generate GenerateMonthlyReport --cron="0 0 1 * *"

# 2. Generate events that jobs can emit
php neuron event:generate ReportGenerated --property="reportId:int" --property="month:string"
php neuron event:generate SessionsCleanedUp --property="count:int"

# 3. Generate listeners for job events
php neuron listener:generate NotifyAdmins --event="App\Events\ReportGenerated"
php neuron listener:generate LogCleanupMetrics --event="App\Events\SessionsCleanedUp"
```

Then in your job, emit the event when work is complete:

```php
use Neuron\Application\CrossCutting\Event;
use App\Events\ReportGenerated;

public function run(array $Argv = []): mixed
{
    // Generate the report
    $reportId = $this->generateReport();

    // Emit event to notify listeners
    Event::emit(new ReportGenerated(
        reportId: $reportId,
        month: date('Y-m')
    ));

    return true;
}
```

## Directory Structure

After using the generators, your project structure will look like:

```
your-project/
├── config/
│   ├── config.yaml
│   ├── routes.yaml              # Generated routes appended here
│   ├── event-listeners.yaml     # Generated listener registrations
│   └── schedule.yaml            # Generated job schedules
├── app/
│   ├── Controllers/
│   │   ├── PostController.php
│   │   └── Admin/
│   │       └── ProductController.php
│   ├── Events/
│   │   ├── UserRegistered.php
│   │   ├── ProductCreated.php
│   │   └── ProductUpdated.php
│   ├── Listeners/
│   │   ├── SendWelcomeEmail.php
│   │   ├── UpdateInventory.php
│   │   └── ClearProductCache.php
│   └── Jobs/
│       ├── SendEmailReminders.php
│       ├── BackupDatabase.php
│       └── CleanupTemp.php
└── resources/
    └── views/
        ├── post/
        │   ├── index.php
        │   ├── create.php
        │   └── edit.php
        └── admin/
            └── product/
                ├── index.php
                ├── create.php
                └── edit.php
```

## Best Practices

1. **Generate first, customize later** - Use generators to create boilerplate, then customize to your needs

2. **Use events for side effects** - Emit events from controllers and handle side effects in listeners

3. **Keep controllers thin** - Move business logic to services or models

4. **Use filters for authentication** - Apply `--filter="auth"` to protected routes

5. **API vs HTML controllers** - Use `--api` for API endpoints, regular controllers for web pages

6. **Nested controllers for admin** - Use `Admin/` prefix for admin controllers

7. **Consistent naming** - Use singular names for controllers (Post, not Posts)

8. **Test generated code** - Always test generated controllers and routes before customizing

## Customizing Templates

The generators use stub templates located in:
```
vendor/neuron-php/mvc/src/Mvc/Cli/Commands/Generate/stubs/
```

You can modify these templates to match your project's conventions:
- `controller.resource.stub` - RESTful controller template
- `controller.api.stub` - API controller template
- `view.index.stub` - Index view template
- `view.create.stub` - Create form template
- `view.edit.stub` - Edit form template
- `event.stub` - Event class template
- `listener.stub` - Listener class template

## Further Reading

- [Neuron Events Documentation](../events/readme.md)
- [Neuron Routing Documentation](../routing/readme.md)
- [MVC Controllers Documentation](readme.md)

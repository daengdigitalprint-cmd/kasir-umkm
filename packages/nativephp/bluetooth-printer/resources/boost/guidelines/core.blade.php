## nativephp/plugin-example

A NativePHP Mobile plugin

### Installation

```bash
composer require nativephp/plugin-example
```

### PHP Usage (Livewire/Blade)

Use the `Example` facade:

@verbatim
<code-snippet name="Using Example Facade" lang="php">
use Nativephp\Example\Facades\Example;

// Execute the plugin functionality
$result = Example::execute(['option1' => 'value']);

// Get the current status
$status = Example::getStatus();
</code-snippet>
@endverbatim

### Available Methods

- `Example::execute()`: Execute the plugin functionality
- `Example::getStatus()`: Get the current status

### Events

- `ExampleCompleted`: Listen with `#[OnNative(ExampleCompleted::class)]`

@verbatim
<code-snippet name="Listening for Example Events" lang="php">
use Native\Mobile\Attributes\OnNative;
use Nativephp\Example\Events\ExampleCompleted;

#[OnNative(ExampleCompleted::class)]
public function handleExampleCompleted($result, $id = null)
{
    // Handle the event
}
</code-snippet>
@endverbatim

### JavaScript Usage (Vue/React/Inertia)

@verbatim
<code-snippet name="Using Example in JavaScript" lang="javascript">
import { example } from '@nativephp/plugin-example';

// Execute the plugin functionality
const result = await example.execute({ option1: 'value' });

// Get the current status
const status = await example.getStatus();
</code-snippet>
@endverbatim
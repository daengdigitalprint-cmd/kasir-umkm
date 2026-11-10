# Example Plugin for NativePHP Mobile

A NativePHP Mobile plugin

## Installation

```bash
composer require nativephp/plugin-example
```

## Usage

```php
use Nativephp\Example\Facades\Example;

// Execute functionality
$result = Example::execute(['option1' => 'value']);

// Get status
$status = Example::getStatus();
```

## Listening for Events

```php
use Livewire\Attributes\On;

#[On('native:Nativephp\Example\Events\ExampleCompleted')]
public function handleExampleCompleted($result, $id = null)
{
    // Handle the event
}
```

## License

MIT
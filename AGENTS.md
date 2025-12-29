# AGENTS.md - Developer Guidelines for OPBX

This file contains essential guidelines for agentic coding assistants working on the OPBX (Open-Source Business PBX) project.

## Build, Lint, and Test Commands

### Backend (Laravel/PHP)
```bash
# Run all tests
composer test

# Run specific test file
./vendor/bin/phpunit tests/Feature/Api/ExtensionControllerTest.php

# Run specific test method
./vendor/bin/phpunit --filter test_index_returns_extensions tests/Feature/Api/ExtensionControllerTest.php

# Run tests with coverage
./vendor/bin/phpunit --coverage-html coverage

# Run tests for specific directory
./vendor/bin/phpunit tests/Unit/
./vendor/bin/phpunit tests/Feature/
./vendor/bin/phpunit tests/Integration/

# Code formatting (Laravel Pint)
./vendor/bin/pint

# Check code style without fixing
./vendor/bin/pint --test

# Development server
composer run dev
```

### Frontend (React/TypeScript)
```bash
# Development server
npm run dev

# Build for production
npm run build

# Type checking (built into Vite)
npm run build
```

### Full Stack Development
```bash
# Start all services (requires Docker)
docker compose up -d

# Run tests across all services
docker compose exec app composer test
docker compose exec frontend npm run build
```

## Code Style Guidelines

### PHP/Laravel Backend

#### File Structure
- Use PSR-4 autoloading
- Controllers in `app/Http/Controllers/Api/`
- Models in `app/Models/`
- Services in `app/Services/`
- Requests in `app/Http/Requests/`
- Resources in `app/Http/Resources/`

#### Strict Typing
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

class ExtensionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // Method must declare return type
    }
}
```

#### Import Organization
```php
<?php

declare(strict_types=1);

namespace App\Models;

// Laravel imports first
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Application imports (grouped by namespace)
use App\Enums\ExtensionType;
use App\Enums\UserStatus;
use App\Scopes\OrganizationScope;

// External packages
use Illuminate\Support\Facades\Log;
```

#### Naming Conventions
- **Classes**: PascalCase (ExtensionController, UserService)
- **Methods**: camelCase (getTargetExtensionId, isActive)
- **Variables**: camelCase ($extensionNumber, $userId)
- **Constants**: UPPER_SNAKE_CASE
- **Database**: snake_case (extension_number, user_id)
- **Enums**: PascalCase (ExtensionType::SIP, UserStatus::ACTIVE)

#### Model Attributes
```php
class Extension extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'extension_number',
        'type',
        'status',
    ];

    protected $hidden = [
        'password', // Security: never expose sensitive data
    ];

    protected function casts(): array
    {
        return [
            'type' => ExtensionType::class,
            'status' => UserStatus::class,
            'voicemail_enabled' => 'boolean',
            'configuration' => 'array',
        ];
    }
}
```

#### Error Handling
```php
public function store(StoreExtensionRequest $request): JsonResponse
{
    try {
        $extension = DB::transaction(function () use ($request) {
            // Business logic here
            return Extension::create($request->validated());
        });

        return new ExtensionResource($extension);
    } catch (Exception $e) {
        Log::error('Failed to create extension', [
            'error' => $e->getMessage(),
            'user_id' => $request->user()->id,
            'organization_id' => $request->user()->organization_id,
        ]);

        return response()->json([
            'message' => 'Failed to create extension'
        ], 500);
    }
}
```

#### Documentation
```php
/**
 * Get the organization that owns the extension.
 *
 * @return BelongsTo<Organization, Extension>
 */
public function organization(): BelongsTo
{
    return $this->belongsTo(Organization::class);
}
```

### React/TypeScript Frontend

#### Component Structure
```tsx
/**
 * User Form Component
 *
 * Form for creating and editing users with validation
 */

import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

// UI components (external libraries)
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

// Internal components
import type { User, CreateUserRequest } from '@/types/api.types';

// Validation schema at top
const userSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters'),
  email: z.string().email('Invalid email address'),
  role: z.enum(['owner', 'admin', 'agent'] as const),
});

type UserFormData = z.infer<typeof userSchema>;

interface UserFormProps {
  user?: User;
  onSubmit: (data: CreateUserRequest) => void;
  onCancel: () => void;
  isLoading?: boolean;
}

export function UserForm({ user, onSubmit, onCancel, isLoading }: UserFormProps) {
  // Component logic
}
```

#### Import Organization
```tsx
// React imports first
import { useState, useEffect } from 'react';

// External libraries (alphabetical)
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

// UI components (grouped by library)
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

// Internal imports (types, then components)
import type { User } from '@/types/api.types';
import { api } from '@/lib/api';
```

#### Naming Conventions
- **Components**: PascalCase (UserForm, ExtensionCard)
- **Files**: PascalCase for components (UserForm.tsx), camelCase for utilities (apiClient.ts)
- **Hooks**: camelCase with 'use' prefix (useUsers, useAuth)
- **Types**: PascalCase (User, CreateUserRequest)
- **Variables/Functions**: camelCase (userData, handleSubmit)

#### Type Safety
```tsx
interface UserFormProps {
  user?: User;
  onSubmit: (data: CreateUserRequest | UpdateUserRequest) => void;
  onCancel: () => void;
  isLoading?: boolean;
}

// Use proper typing for event handlers
const handleSubmit = (data: UserFormData) => {
  onSubmit(data);
};

// Avoid any, use proper types
const [users, setUsers] = useState<User[]>([]);
```

#### Error Handling
```tsx
const { data, error, isLoading } = useQuery({
  queryKey: ['users'],
  queryFn: api.getUsers,
  onError: (error) => {
    console.error('Failed to fetch users:', error);
    toast.error('Failed to load users');
  },
});
```

## Testing Guidelines

### PHP Unit Tests
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Extension;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtensionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
    }

    public function test_get_target_extension_id_returns_correct_id(): void
    {
        // Given
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // When
        $didNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $extension->id],
        ]);

        // Then
        $this->assertEquals($extension->id, $didNumber->getTargetExtensionId());
    }
}
```

### Testing Best Practices
- Use factories for test data creation
- Test one thing per test method
- Use descriptive test method names
- Group related tests in test classes
- Use RefreshDatabase trait for database isolation
- Mock external dependencies
- Test edge cases and error conditions

## Security Guidelines

### Authentication & Authorization
- Use Laravel Sanctum for API authentication
- Implement role-based access control (RBAC)
- Always check permissions with `Gate::authorize()`
- Never expose sensitive data in API responses

### Multi-Tenant Isolation
- All queries must be scoped by `organization_id`
- Use `#[ScopedBy([OrganizationScope::class])]` attribute on models
- Validate organization ownership before operations

### Input Validation
- Use Form Request classes for validation
- Sanitize all user inputs
- Use enums for constrained values
- Implement proper error messages

## Performance Guidelines

### Database Optimization
- Use eager loading to prevent N+1 queries
- Implement proper indexing
- Use Redis for caching frequently accessed data
- Paginate large result sets

### Caching Strategy
- Cache routing configurations in Redis
- Use cache tags for efficient invalidation
- Implement cache warming for critical data

## Commit Message Conventions

```
feat: add user authentication system
fix: resolve extension deletion bug
docs: update API documentation
refactor: simplify call routing logic
test: add unit tests for extension model
```

## Additional Resources

- [Laravel Documentation](https://laravel.com/docs)
- [React Documentation](https://react.dev)
- [TypeScript Handbook](https://www.typescriptlang.org/docs/)
- [Cloudonix Developer Docs](https://developers.cloudonix.com/)
- [Tailwind CSS](https://tailwindcss.com/docs)</content>
<parameter name="filePath">/Users/nirs/Documents/repos/opbx.cloudonix.com/AGENTS.md
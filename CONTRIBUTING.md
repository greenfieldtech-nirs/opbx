# Contributing to OPBX

Thank you for your interest in contributing to OPBX! This document provides comprehensive guidelines for contributing code to the project.

## Table of Contents

- [Introduction](#introduction)
- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [Forking and Creating Pull Requests](#forking-and-creating-pull-requests)
- [Coding Standards](#coding-standards)
- [Testing Requirements](#testing-requirements)
- [Commit Message Guidelines](#commit-message-guidelines)
- [Pull Request Process](#pull-request-process)
- [Code Review Expectations](#code-review-expectations)
- [Community Guidelines](#community-guidelines)

## Introduction

OPBX is an open-source business PBX platform built on top of the Cloudonix CPaaS platform. We welcome contributions from the community whether you're fixing bugs, adding features, improving documentation, or suggesting enhancements.

**All contributions must be made via forking and pull requests.** Direct commits to the main repository are not permitted.

## Getting Started

### Prerequisites

Before contributing, ensure you have:

- Git installed and configured
- Docker and Docker Compose
- A GitHub account
- Basic understanding of the technologies used:
  - Laravel (PHP)
  - React with TypeScript
  - MySQL and Redis
  - Cloudonix CPaaS platform

### Initial Steps

1. **Star the repository** - It helps the project visibility
2. **Watch the repository** - Stay updated with discussions and changes
3. **Check existing issues** - Look for bugs, feature requests, or enhancement ideas
4. **Join our Discord** - Participate in our [Discord community](https://discord.gg/etCGgNh9VV) for ideas and feedback

## Development Setup

### 1. Fork the Repository

1. Navigate to the [OPBX repository](https://github.com/greenfieldtech-nirs/OPBX)
2. Click the **Fork** button in the top-right corner
3. Select your GitHub account as the fork destination
4. Wait for the fork to complete

### 2. Clone Your Fork

```bash
# Clone your fork
git clone https://github.com/YOUR-USERNAME/OPBX.git

# Navigate to the project directory
cd OPBX

# Add the original repository as upstream
git remote add upstream https://github.com/greenfieldtech-nirs/OPBX.git
```

### 3. Set Up Development Environment

```bash
# Copy environment example
cp .env.example .env

# Start Docker containers
docker compose up -d

# Run database migrations
docker compose exec app php artisan migrate

# Install frontend dependencies
docker compose exec frontend npm install

# Start frontend development server
docker compose exec frontend npm run dev
```

### 4. Verify Your Setup

- Access the application at `http://localhost`
- Access the frontend at `http://localhost:3000`
- Access ngrok for webhook testing at `http://localhost:4040`
- Check logs: `docker compose logs -f`

### 5. Sync Your Fork

Before starting work, always sync your fork with the upstream:

```bash
# Fetch upstream changes
git fetch upstream

# Switch to main branch
git checkout main

# Merge upstream changes
git merge upstream/main

# Push to your fork
git push origin main
```

## Forking and Creating Pull Requests

### Creating a Feature Branch

```bash
# Ensure you're on main and up to date
git checkout main
git pull upstream main

# Create a new feature branch
git checkout -b feature/your-feature-name

# For bug fixes
git checkout -b fix/issue-description

# For hotfixes
git checkout -b hotfix/critical-fix
```

### Naming Conventions

| Type | Prefix | Example |
|------|--------|---------|
| Features | `feature/` | `feature/add-ring-groups` |
| Bug Fixes | `fix/` | `fix/login-redirect-issue` |
| Hotfixes | `hotfix/` | `hotfix/security-patch` |
| Documentation | `docs/` | `docs/update-readme` |
| Refactoring | `refactor/` | `refactor/api-endpoints` |
| Testing | `test/` | `test/add-unit-tests` |

### Making Changes

1. **Write clear, focused code**
2. **Follow coding standards** (see below)
3. **Add tests** for your changes
4. **Update documentation** as needed
5. **Commit frequently** with descriptive messages

### Committing Changes

```bash
# Stage your changes
git add .

# Commit with a descriptive message
git commit -m "feat: add simultaneous ring group strategy"

# Push to your fork
git push origin feature/your-feature-name
```

### Creating a Pull Request

1. **Navigate to your fork** on GitHub
2. **Click "New Pull Request"**
3. **Select your branch** as the compare branch
4. **Fill in the PR template** completely
5. **Submit the pull request**

### PR Title Convention

Use conventional commit format for PR titles:

```
<type>(<scope>): <description>
```

Examples:
- `feat(auth): add two-factor authentication`
- `fix(api): resolve rate limiting issue`
- `docs(readme): update installation instructions`
- `refactor(database): optimize query performance`

## Coding Standards

### PHP (Laravel)

Follow PSR-12 coding standards and Laravel conventions:

```php
// Use strict types
declare(strict_types=1);

// Follow Laravel naming conventions
class UserController extends Controller
{
    // Use dependency injection
    public function __construct(
        protected UserService $userService
    ) {}
    
    // Use form requests for validation
    public function store(UserStoreRequest $request)
    {
        // Implementation
    }
}
```

**Guidelines:**
- Use type hints and return types
- Use strict typing (`declare(strict_types=1);`)
- Follow PSR-4 autoloading
- Use dependency injection over singletons
- Write docblocks for classes and public methods
- Use descriptive variable names

### TypeScript/React

Follow TypeScript and React best practices:

```typescript
// Use interfaces for object types
interface User {
  id: string;
  email: string;
  name: string;
}

// Use functional components with hooks
export function UserCard({ user }: UserCardProps): JSX.Element {
  const [isExpanded, setIsExpanded] = useState(false);
  
  return (
    <Card>
      <CardHeader>{user.name}</CardHeader>
    </Card>
  );
}
```

**Guidelines:**
- Enable strict mode in TypeScript
- Use functional components with hooks
- Use `.tsx` extension for React components
- Define interfaces for props and data types
- Use proper TypeScript types (avoid `any`)
- Follow React hooks rules
- Use proper accessibility attributes

### CSS/Tailwind

```tsx
// Use Tailwind utility classes
<div className="flex items-center justify-between p-4 bg-background">
  <span className="text-lg font-semibold">{title}</span>
</div>
```

**Guidelines:**
- Use Tailwind CSS utilities
- Follow mobile-first approach
- Use semantic color tokens
- Maintain responsive design
- Use proper spacing scale

### General Guidelines

1. **Single Responsibility** - Each function/class should do one thing well
2. **DRY (Don't Repeat Yourself)** - Extract common code
3. **KISS (Keep It Simple, Stupid)** - Prefer simple solutions
4. **SOLID Principles** - Follow object-oriented design principles
5. **Clean Code** - Write readable, self-documenting code

## Testing Requirements

### Backend Testing (PHPUnit)

All backend changes must include tests:

```php
// Feature test example
public function test_users_can_be_created(): void
{
    $response = $this->postJson('/api/v1/users', [
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);
    
    $response->assertCreated();
    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
    ]);
}
```

**Requirements:**
- Unit tests for models and services
- Feature tests for API endpoints
- Minimum 80% code coverage for new code
- Test both success and failure scenarios

### Frontend Testing (Vitest/React Testing Library)

```typescript
// Component test example
import { render, screen } from '@testing-library/react';
import { UserCard } from './UserCard';

describe('UserCard', () => {
  it('renders user name', () => {
    render(<UserCard user={{ id: '1', name: 'John', email: 'john@test.com' }} />);
    expect(screen.getByText('John')).toBeInTheDocument();
  });
});
```

**Requirements:**
- Unit tests for utility functions
- Component tests for UI components
- Integration tests for critical user flows
- Mock external dependencies

### Running Tests

```bash
# Backend tests
docker compose exec app php artisan test

# Frontend tests
docker compose exec frontend npm run test

# With coverage
docker compose exec frontend npm run test:coverage
```

## Commit Message Guidelines

### Format

```
<type>(<scope>): <subject>

<body>

<footer>
```

### Types

| Type | Description |
|------|-------------|
| `feat` | New feature |
| `fix` | Bug fix |
| `docs` | Documentation changes |
| `style` | Code style changes (formatting, etc.) |
| `refactor` | Code refactoring (no feature or bug fix) |
| `perf` | Performance improvements |
| `test` | Adding or modifying tests |
| `chore` | Maintenance tasks |
| `ci` | CI configuration changes |

### Examples

```
feat(auth): add two-factor authentication

Implement 2FA support using TOTP algorithm.

- Add secret generation endpoint
- Create verification middleware
- Add QR code generation
- Implement backup codes

Closes #123
```

```
fix(api: prevent race condition in webhook processing

Add Redis distributed lock to prevent concurrent webhook
processing that was causing duplicate call routing.

Reviewed-by: @reviewer
Ref: SECURITY-456
```

### Rules

1. Use imperative mood ("add feature" not "adding feature")
2. Limit subject line to 72 characters
3. Capitalize the subject line
4. Do not end with a period
5. Use body to explain what and why, not how

## Pull Request Process

### Before Submitting

1. **Ensure all tests pass**
   ```bash
   docker compose exec app php artisan test
   docker compose exec frontend npm run test
   ```

2. **Run linting and formatting**
   ```bash
   docker compose exec app composer lint
   docker compose exec frontend npm run lint
   ```

3. **Update documentation**
   - Update README if adding features
   - Add docblocks for new classes/methods
   - Update API documentation if applicable

4. **Review your changes**
   - Self-review your PR
   - Check for unintended changes
   - Ensure clean commit history

### PR Template

All PRs must use the provided template:

```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix (non-breaking change)
- [ ] New feature (non-breaking change)
- [ ] Breaking change (fix or feature that causes existing functionality to change)
- [ ] Documentation update
- [ ] Refactoring (no functional changes)

## Testing
Describe how changes were tested

## Screenshots (if applicable)
Add screenshots to explain changes visually

## Checklist
- [ ] My code follows project coding standards
- [ ] I have performed self-review of my code
- [ ] I have commented my code where necessary
- [ ] I have made corresponding changes to documentation
- [ ] My changes generate no new warnings
- [ ] I have added tests that prove my fix is effective or my feature works
- [ ] New and existing tests pass locally
- [ ] Any dependent changes have been merged and published
```

### After Submitting

1. **Respond to feedback** - Address reviewer comments promptly
2. **Make requested changes** - Push updates to your branch
3. **Keep PR updated** - Sync with main if needed
4. **Be patient** - Maintainers will review as time allows

## Code Review Expectations

### For Contributors

1. **Be receptive** - Feedback is meant to improve code quality
2. **Ask questions** - Clarify if you don't understand feedback
3. **Provide context** - Explain your reasoning when challenged
4. **Be respectful** - Treat all reviewers with courtesy
5. **Learn from reviews** - Each review is a learning opportunity

### Review Criteria

Maintainers review PRs based on:

- **Correctness** - Does it work as intended?
- **Design** - Is it well-structured and maintainable?
- **Tests** - Are there adequate tests?
- **Documentation** - Is it properly documented?
- **Style** - Does it follow coding standards?
- **Performance** - Are there performance concerns?

## Community Guidelines

### Communication

- **Be respectful** - Treat all community members with respect
- **Be constructive** - Provide helpful, actionable feedback
- **Be patient** - Remember maintainers volunteer their time
- **Use clear language** - Write clearly and concisely
- **Stay on topic** - Keep discussions relevant

### Issue Reporting

When reporting issues:

1. **Search first** - Check if the issue already exists
2. **Use templates** - Follow the issue template
3. **Provide details** - Include steps to reproduce, expected vs actual behavior
4. **Add context** - Include environment details, screenshots, logs
5. **Label appropriately** - Help maintainers categorize issues

### Feature Requests

When suggesting features:

1. **Explain the use case** - Why is this feature valuable?
2. **Provide examples** - Show how it would be used
3. **Consider alternatives** - Are there other approaches?
4. **Be realistic** - Understand project constraints

## Recognition

Contributors are recognized in:

- **CONTRIBUTORS.md** - List of all contributors
- **Release notes** - Significant contributions mentioned
- **GitHub profile** - Linked from the project
- **Community acknowledgments** - Regular shoutouts

## Questions?

If you have questions:

1. **Check documentation** - Review existing docs first
2. **Search issues** - Look for similar discussions
3. **Ask on Discord** - Join our [Discord server](https://discord.gg/etCGgNh9VV) for questions
4. **Open an issue** - For bugs or feature requests
5. **Be specific** - Provide context and details

---

## Thank you for contributing to OPBX!

Your contributions help make OPBX a better platform for everyone. We appreciate your time and effort in improving the project.

# Ralph Agent Instructions

You are an autonomous coding agent working on a Laravel application.

## Your Environment

- PHP 8.4, Laravel 12, Filament 4, Livewire 3, Tailwind CSS 4, Pest
- Use `php artisan make:` commands to create new files
- Use Eloquent models and relationships, avoid raw `DB::` queries
- Follow existing code conventions - check sibling files before creating new ones

## Your Task

1. Read `.ralph/prd.json` for the product requirements and user stories
2. Read `.ralph/progress.txt` (check **Codebase Patterns** section FIRST)
3. Read `.ralph/guardrails.md` for applicable constraints
4. Check you're on the correct branch: `ralph/f39e4640-08dd-40e1-b7de-256bf4a0baab`. If not, create it from main.
5. Pick the **highest priority** user story where `passes: false`
6. Implement that **ONE** story only
7. Run quality checks:
   - `vendor/bin/pint --dirty` (fix code style)
   - Targeted tests only (run only story-specific tests, not full suite)
8. If checks pass, commit ALL changes: `feat: [Story ID] - [Story Title]`
   - The full test suite will run in GitHub CI
9. Update `.ralph/prd.json`: set `passes: true` for the completed story
10. Append learnings to `.ralph/progress.txt`

## Laravel Conventions

- Always use Form Request classes for validation (not inline)
- Use constructor property promotion in `__construct()`
- Always add explicit return type declarations
- Use Eloquent relationships and eager loading (prevent N+1)
- Create factories and seeders for new models
- Use queued jobs for time-consuming operations
- Use `config()` not `env()` outside of config files
- Use named routes and the `route()` function for URL generation

## Filament Conventions

- Use `php artisan make:filament-resource` for new resources
- Use `relationship()` on form components when possible
- Test Filament with `livewire(ListUsers::class)` style assertions
- Ensure authenticated in Filament tests

## Testing

- Write Pest tests (not PHPUnit)
- Use feature tests by default, unit tests only when specifically needed
- Use model factories - check for existing factory states before manual setup
- Test through public interfaces, not implementation details
- For Filament: use Livewire test helpers (`livewire()`, `assertCanSeeTableRecords()`, etc.)

## Progress Report Format

APPEND to `.ralph/progress.txt` (never replace, always append):

```
## [Date] - [Story ID]
- What was implemented
- Files changed
- **Learnings for future iterations:**
  - Patterns discovered
  - Gotchas encountered
  - Useful context
---
```

## Consolidate Patterns

If you discover a **reusable pattern**, add it to the `## Codebase Patterns` section at the TOP of progress.txt:

```
## Codebase Patterns
- Example: Use Pest for all tests, not PHPUnit
- Example: Filament resources live in app/Filament/Resources/
- Example: Always run vendor/bin/pint --dirty before committing
```

Only add patterns that are **general and reusable**, not story-specific.

## Quality Requirements

- ALL commits must pass `vendor/bin/pint --dirty` and targeted story-specific tests
- The full test suite will be verified in GitHub CI
- Do NOT commit broken code
- Keep changes focused and minimal
- Follow existing code patterns in sibling files

## Stop Condition

After completing a user story, check if ALL stories have `passes: true`.

If ALL stories are complete and passing, reply with:
<promise>COMPLETE</promise>

If there are still stories with `passes: false`, end your response normally (another iteration will pick up the next story).

## Important

- Work on ONE story per iteration
- Commit frequently
- Keep CI green
- Read the Codebase Patterns section in progress.txt before starting
- Run `vendor/bin/pint --dirty` before every commit
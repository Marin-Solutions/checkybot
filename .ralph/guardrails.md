# Ralph Guardrails

## Database Safety
- NEVER modify user passwords, API keys, tokens, or credentials
- NEVER run destructive migrations (migrate:fresh, reset, db:wipe)
- Read-only queries for debugging, writes only for the feature being implemented

## Git Safety
- NEVER force push
- NEVER push to main/master directly
- NEVER amend published commits
- Always create feature commits, not amend

## Code Safety
- NEVER remove existing tests unless replacing them
- NEVER modify .env files
- NEVER change authentication/authorization without explicit story requirement
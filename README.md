# ForgeFlow Backend

ForgeFlow Backend is a Laravel API for multi-tenant workflow orchestration. It manages workflow definitions, versioning, DAG execution, scheduled and webhook triggers, approval steps, execution logs, and AI-assisted workflow generation/failure analysis.

## Current Branches

Use the existing branches below for the current project history:

```bash
git switch master
git switch ci/github-actions-backend
git switch chore/docker-backend
git switch forgeflow-mvp-backend
```

## Tech Stack

- PHP `^8.4.1`
- Laravel `13.x`
- PostgreSQL 16+
- Laravel database queue driver
- Laravel scheduler / custom workflow scheduler command
- Laravel AI SDK with Gemini 2.5 Flash
- Laravel Sanctum package plus custom bearer token validation
- Pest + Laravel test runner
- Laravel Pint for code style
- Docker support through the infra repository

## Architecture

- **API layer**: versioned REST API under `/api/v1`, bearer-token auth, tenant scoping, RBAC permissions, validation requests, and JSON resources.
- **Workflow engine**: validates workflow JSON, performs DAG dependency resolution, runs HTTP/script/delay/condition/approval steps, supports retries, branching, parallel-ready independent steps, and approval resume/reject handling.
- **Runtime workers**: workflow runs are dispatched to the queue; scheduled triggers are processed by `php artisan workflow:scheduler:run` or the Docker scheduler loop.
- **Monitoring**: run detail uses SSE at `/api/v1/runs/{run}/events`; list pages use normal paginated APIs.
- **AI services**: `/api/v1/ai/workflow-drafts` generates validated workflow JSON; `/api/v1/runs/{run}/ai-analysis` analyzes failed or timed-out runs using real run context.
- **Audit and compliance**: workflow, scheduler, approval, auth, and user actions are recorded in audit logs.

## Local Setup

Prerequisites:

- PHP 8.4.1 or newer
- Composer 2.x
- PostgreSQL 16+

Install and run:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Demo account after running the database seeders:

```text
Email: admin@flowforge.test
Password: password
```

Run the queue worker in another terminal:

```bash
php artisan queue:work --tries=1 --timeout=300 --sleep=1
```

Run due scheduled workflow triggers in another terminal:

```bash
php artisan workflow:scheduler:run
```

For continuous local scheduler execution, run that command once per minute using a shell loop or use the Docker scheduler service from the infra repository.

## Environment Variables

Important backend variables:

```env
APP_URL=http://127.0.0.1:8000
APP_TIMEZONE=Asia/Jakarta

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=forgeflow_db
DB_USERNAME=postgres
DB_PASSWORD=secret

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

AI_PROVIDER=openrouter
AI_MODEL=anthropic/claude-3.5-sonnet
OPENROUTER_API_KEY=your-openrouter-api-key
GEMINI_API_KEY=
OPENAI_API_KEY=
ANTHROPIC_API_KEY=
AI_WORKFLOW_PROMPT_MAX_CHARS=8000
AI_WORKFLOW_MAX_STEPS=20
AI_WORKFLOW_TIMEOUT=300
AI_FAILURE_LOG_LIMIT=100
```

`OPENROUTER_API_KEY` is required when `AI_PROVIDER=openrouter`. ForgeFlow also supports other Laravel AI SDK providers such as `gemini`, `openai`, `anthropic`, and `ollama`; set `AI_PROVIDER`, `AI_MODEL`, and the matching provider key in `.env`. Non-AI workflow execution can run without an AI key.

## API Highlights

Authentication:

- `POST /api/v1/auth/register` creates a tenant and the first admin user.
- `POST /api/v1/auth/login` returns an API token and user/tenant context.
- `GET /api/v1/auth/me` returns the current authenticated user.
- `POST /api/v1/auth/logout` revokes the current token.

Workflow management:

- `GET /api/v1/workflows` lists workflows with pagination and filters.
- `POST /api/v1/workflows` creates a workflow and initial version.
- `GET /api/v1/workflows/{workflow}` shows detail, active version, definition, and recent runtime data.
- `PATCH /api/v1/workflows/{workflow}` creates a new workflow version.
- `POST /api/v1/workflows/{workflow}/runs` starts a manual run.
- `GET /api/v1/workflows/{workflow}/versions` lists version history.
- `POST /api/v1/workflows/{workflow}/versions/{version}/rollback` rolls back to a previous version.

Runtime and approvals:

- `GET /api/v1/runs` lists workflow runs with pagination and filters.
- `GET /api/v1/runs/{run}` shows run detail, steps, logs, approvals, and AI analysis.
- `GET /api/v1/runs/{run}/events` streams live run status with SSE.
- `GET /api/v1/runs/{run}/logs` returns logs for a run.
- `GET /api/v1/runs/{run}/approvals` lists pending/completed approval records.
- `POST /api/v1/runs/{run}/approvals/{approval}/approve` approves a waiting step.
- `POST /api/v1/runs/{run}/approvals/{approval}/reject` rejects a waiting step.

Triggers and AI:

- `POST /api/webhooks/workflows/{workflow}` receives incoming webhook triggers.
- `GET /api/v1/scheduled-triggers` lists scheduled triggers.
- `POST /api/v1/scheduled-triggers` creates a scheduled trigger.
- `PATCH /api/v1/scheduled-triggers/{trigger}` updates, pauses, or resumes a trigger.
- `POST /api/v1/ai/workflow-drafts` generates workflow JSON from a prompt.
- `POST /api/v1/runs/{run}/ai-analysis` generates failure analysis for failed/timed-out runs.

Mock APIs for workflow testing:

- `GET /api/mock/orders/{orderId}` returns mock order data.
- `POST /api/mock/notifications` stores a mock notification request.
- `POST /api/mock/orders/{orderId}/status` updates mock order status.
- `GET /api/mock/time` returns mock server time data.

## Workflow Definition Support

Supported triggers:

- `manual`
- `webhook`
- `scheduled`

Supported step types:

- `http`
- `delay`
- `condition`
- `script`
- `approval`

The workflow validator checks step IDs, dependencies, cycles, trigger type, step type, and required config fields. Condition steps support `equals`, `not_equals`, `contains`, `greater_than`, and `less_than` operators.

## AI Prompt Engineering

ForgeFlow uses two LLM-assisted features: workflow draft generation and failure analysis. Both are implemented through Laravel AI SDK agents with structured JSON output, then validated by backend services before the response is persisted or returned to the frontend.

- **Workflow drafts** use a system prompt that constrains output to ForgeFlow's workflow schema: triggers must be `manual`, `webhook`, or `scheduled`; step types must be `http`, `delay`, `condition`, `script`, or `approval`; and generated definitions must be practical for the mock API/testing endpoints.
- **Failure analysis** uses only supplied run context: workflow definition, run input/output, step runs, execution logs, approval state, and error messages. The prompt instructs the model to tie `rootCause` and `suggestedFix` to concrete evidence instead of guessing.
- **Token limits** are controlled with `AI_WORKFLOW_PROMPT_MAX_CHARS`, `AI_WORKFLOW_MAX_STEPS`, `AI_WORKFLOW_TIMEOUT`, and `AI_FAILURE_LOG_LIMIT` so large prompts and noisy logs do not exceed provider limits.
- **Malformed output protection** is handled by requiring structured JSON, normalizing generated fields, running the same workflow validator used for manual JSON submissions, attempting one repair prompt, and falling back to a conservative draft when possible.
- **Provider selection** is environment-driven. `AI_PROVIDER=openrouter` with `AI_MODEL=anthropic/claude-3.5-sonnet` is the default, and the same runtime config is used by workflow drafts and failure analysis.
- **Security boundary**: provider keys stay in backend environment variables. The frontend never calls the LLM provider directly and never receives provider secrets.

## Query Optimization

The primary run-history optimization is documented in [`docs/query-optimization.md`](docs/query-optimization.md). It covers the tenant-scoped `GET /api/v1/runs` query, the `idx_workflow_runs_tenant_created` composite index, representative `EXPLAIN ANALYZE` output, and the read/write trade-off.

## Testing and Quality

Run the full backend suite:

```bash
php artisan test
```

Run code style checks:

```bash
./vendor/bin/pint --test
```

Validate Composer metadata:

```bash
composer validate --no-check-publish --strict
```

The current CI branch is `ci/github-actions-backend`. The GitHub Actions workflow runs Composer validation, installs dependencies with PHP 8.4, checks Pint style, runs `php artisan test`, and builds a Docker image artifact.

## Docker

Docker orchestration lives in the separate infra repository:

```bash
cd ../infra
cp .env.example .env
docker compose up -d --build
docker compose ps
```

Local Docker access is through the frontend gateway at:

```text
http://localhost:8080
```

The backend API is routed through the same origin under `/api/*`.

## Trade-offs

- Database queues keep the MVP simple but are less scalable than Redis/Horizon for high job volume.
- PostgreSQL execution logs are easy to query with workflow data but can grow quickly under heavy workloads.
- SSE is limited to run detail monitoring because list-page SSE caused cancelled connections and slow first loads.
- The scheduler loop is simple and production-friendly for one server, but distributed scheduling would require a stronger coordination mechanism.
- AI output is validated and repaired before use because LLM output can be incomplete or invalid.

## Future Improvements

- Redis + Laravel Horizon for scalable queues and queue monitoring.
- Dedicated log storage such as Loki or Elasticsearch.
- OpenTelemetry tracing and Prometheus metrics.
- More tenant-level rate limiting and audit export endpoints.
- Encrypted workflow secrets for HTTP headers and body values.
- GraphQL or richer query APIs if frontend data requirements grow.

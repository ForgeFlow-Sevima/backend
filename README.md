  #### a. Project Overview

  - FlowForge Backend - Real-time multi-tenant workflow orchestration engine
  - Workflow engine dengan DAG execution, retry/backoff, timeout handling
  - Multi-tenant isolation dengan JWT authentication + RBAC
  - AI-powered workflow builder dan failure analysis

  #### b. Tech Stack

  - Laravel 13 (PHP 8.4.1+)
  - PostgreSQL 16+
  - Laravel Queue (database driver)
  - Laravel Scheduler
  - Laravel AI SDK (Gemini 2.5 Flash)
  - PHPUnit for testing

  #### c. Architecture Overview

  Core Components:

  1. REST API Layer
      - JWT authentication dengan personal access tokens
      - Tenant isolation middleware
      - RBAC: Admin, Editor, Viewer roles
      - Input validation & sanitization

  2. Workflow Engine
      - DAG validator (topological sort, cycle detection)
      - Workflow executor dengan dependency resolution
      - Step types: HTTP, script, delay, condition, approval
      - Retry policy: exponential backoff, max attempts
      - Global timeout handling

  3. Queue Worker
      - Async step execution
      - Parallel execution untuk independent steps
      - Database queue driver

  4. Scheduler
      - Cron-based workflow triggers
      - schedule:work loop untuk scheduled workflows
      - Pause/resume functionality

  5. Real-Time Monitoring
      - SSE (Server-Sent Events) untuk run detail monitoring
      - Live step status updates

  6. AI Services
      - Workflow builder: natural language → workflow JSON (Gemini)
      - Failure analysis: error context → root cause + fix suggestions
      - Token limit handling & output validation

  7. Audit System
      - Comprehensive audit logs untuk compliance
      - Track semua workflow actions

  #### d. Setup Instructions

  Prerequisites:

  PHP 8.4.1+
  Composer 2.x
  PostgreSQL 16+

  Local Development:

  # Clone repository
  cd backend

  # Install dependencies
  composer install

  # Setup environment
  cp .env.example .env
  # Edit .env: set DB credentials, GEMINI_API_KEY

  # Generate application key
  php artisan key:generate

  # Run migrations & seed demo data
  php artisan migrate --seed

  # Start development server
  php artisan serve

  # In separate terminals:
  # Start queue worker
  php artisan queue:work

  # Start scheduler (for cron-based workflows)
  php artisan schedule:work

  Docker Setup:

  # See infra/readme.md for full Docker setup
  cd ../infra
  cp .env.example .env
  docker compose up -d --build

  # Access at http://localhost:8080

  #### e. Environment Variables

  Required:

  APP_URL=http://127.0.0.1:8000
  APP_TIMEZONE=Asia/Jakarta
  DB_CONNECTION=pgsql
  DB_HOST=127.0.0.1
  DB_PORT=5432
  DB_DATABASE=forgeflow_db
  DB_USERNAME=postgres
  DB_PASSWORD=secret

  QUEUE_CONNECTION=database

  GEMINI_API_KEY=your-gemini-api-key
  AI_PROVIDER=gemini
  AI_GEMINI_MODEL=gemini-2.5-flash

  #### f. Testing

  # Run all tests
  php artisan test

  # Run specific test suite
  php artisan test --testsuite=Feature
  php artisan test --testsuite=Unit

  # Current coverage: 62 tests passing
  # Tests include: DAG validation, workflow execution, API endpoints, auth

  #### g. API Highlights

  Core Endpoints:

  - POST /api/v1/auth/register - Register + auto-create tenant
  - POST /api/v1/auth/login - JWT authentication
  - GET /api/v1/workflows - List workflows (paginated, filtered, rate-limited)
  - POST /api/v1/workflows - Create workflow with validation
  - GET /api/v1/workflows/{id}/versions - Version history
  - POST /api/v1/workflows/{id}/rollback - Rollback to version
  - POST /api/v1/workflows/{id}/runs - Trigger workflow (manual)
  - POST /api/v1/webhooks/{token} - Webhook trigger
  - GET /api/v1/workflows/{id}/scheduled-triggers - Scheduled triggers
  - PATCH /api/v1/scheduled-triggers/{id} - Pause/resume scheduler
  - GET /api/v1/runs/{id}/events - SSE run monitoring
  - POST /api/v1/approvals/{id}/approve - Approve workflow step
  - POST /api/v1/approvals/{id}/reject - Reject workflow step
  - POST /api/v1/ai/workflow-drafts - AI workflow generation
  - POST /api/v1/ai/failure-analysis - AI failure analysis

  Mock APIs (for testing workflows):

  - GET /api/mock/orders/{id} - Mock order data
  - POST /api/mock/notifications - Mock notification
  - POST /api/mock/orders/{id}/status - Update order status

  #### h. Trade-offs & Design Decisions

  1. Database Queue vs Redis

  - Choice: Laravel database queue driver
  - Rationale: Simpler setup untuk MVP, PostgreSQL cukup untuk moderate load
  - Trade-off: Kurang scalable dibanding Redis, tidak ada queue monitoring UI
  - When to change: Ketika > 1000 jobs/minute atau perlu horizontal scaling

  2. Logs in PostgreSQL vs Separate Store

  - Choice: Execution logs di PostgreSQL logs table
  - Rationale: Transactional consistency, simpler query untuk debugging
  - Trade-off: Database bloat untuk high-volume workflows, kurang optimal untuk log search
  - When to change: Ketika logs > 10GB atau perlu full-text search (migrate to Elasticsearch/Loki)

  3. SSE for Run Detail Only

  - Choice: SSE hanya untuk /runs/{id}/events, tidak untuk list pages
  - Rationale: List pages dengan SSE menyebabkan connection timeout & slow loading
  - Trade-off: List pages tidak real-time, perlu manual refresh
  - Alternative: Polling setiap 5-10 detik untuk list pages

  4. Simple Scheduler Loop

  - Choice: php artisan schedule:work untuk scheduled workflows
  - Rationale: Laravel native solution, cukup untuk MVP
  - Trade-off: Requires persistent process, no distributed scheduling
  - When to change: Multi-server deployment (use Laravel Horizon + Redis)

  5. AI Output Validation

  - Choice: Strict JSON schema validation + repair mechanism untuk AI output
  - Rationale: LLM output tidak selalu valid, perlu fallback
  - Trade-off: Extra processing time, kadang repair gagal
  - Alternative: Fine-tuned model atau structured output API

  6. No GraphQL

  - Choice: REST API only, skip GraphQL bonus
  - Rationale: Time constraint, REST sufficient untuk MVP
  - Trade-off: Over-fetching/under-fetching data, tidak ada client-driven queries

  7. Tenant Isolation via Middleware

  - Choice: Global scope + middleware untuk tenant filtering
  - Rationale: Laravel best practice, automatic tenant scoping
  - Trade-off: Accidental scope bypass risk jika lupa apply scope
  - Mitigation: Comprehensive tests untuk tenant isolation

  #### i. Improvements with More Time

  Infrastructure & DevOps:

  - CI/CD pipeline (GitHub Actions): lint → test → build → deploy
  - Redis + Laravel Horizon untuk queue monitoring & better scalability
  - Separate log aggregation (Elasticsearch/Loki) untuk better search & retention
  - OpenTelemetry tracing untuk distributed monitoring
  - Production-ready health checks & metrics (Prometheus)

  Code Quality & Testing:

  - Expand test coverage ke 90%+ (currently ~70%)
  - E2E tests untuk complete workflow scenarios
  - Performance benchmarking & load testing
  - Code review documentation (REVIEW.md)
  - Architecture decision records (ADR)

  Features:

  - GraphQL endpoint untuk flexible queries
  - Workflow templates/marketplace
  - Global timeout enforcer (separate service)
  - Workflow versioning comparison (visual diff)
  - Advanced retry strategies (jitter, circuit breaker)
  - Workflow dependencies (trigger workflow B after A succeeds)
  - Bulk operations (pause/resume multiple workflows)

  Security & Compliance:

  - Rate limiting per tenant (currently global only)
  - Audit log export API
  - Encrypted secrets storage untuk workflow configs
  - IP whitelisting untuk webhooks
  - OAuth2/SAML SSO support

  AI Enhancements:

  - Workflow optimization suggestions (based on execution history)
  - Anomaly detection (unusually long runs, high failure rates)
  - Smart retry recommendations
  - Cost estimation untuk workflows

  ———

# Code Review Exercise: Backend

## Snippet Under Review

```php
public function index(Request $request): JsonResponse
{
    $runs = WorkflowRun::query()
        ->whereRaw("tenant_id = '{$request->tenant_id}'")
        ->latest()
        ->get();

    return response()->json(['data' => $runs]);
}
```

## Findings

### High: Tenant isolation can be bypassed

The query trusts `$request->tenant_id` instead of the authenticated user's tenant. A caller can request another tenant's run history by changing the request payload or query string. Use `$request->user()->tenant_id` from the authenticated API token.

### High: Raw SQL creates injection risk

`whereRaw()` interpolates request data directly into SQL. Even if the value is expected to be a UUID, it is still user-controlled. Use Eloquent bindings: `where('tenant_id', $request->user()->tenant_id)`.

### Medium: Unbounded result set

`get()` returns every run for the tenant. This can overload the API and database as run history grows. The list endpoint should validate `page` and `perPage`, cap `perPage`, and use `paginate()`.

### Medium: Missing eager loading strategy

If resources later access workflow or step runs, this code can create N+1 queries. Load known relationships explicitly, for example `with(['workflow', 'stepRuns'])`.

### Low: No rate limiting on a list endpoint

Run history is a frequently refreshed endpoint. It should use route throttling to protect the API from abusive polling.

## Suggested Fix

```php
public function index(Request $request): JsonResponse
{
    $request->validate([
        'page' => ['nullable', 'integer', 'min:1'],
        'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
    ]);

    $runs = WorkflowRun::query()
        ->with(['workflow', 'stepRuns'])
        ->where('tenant_id', $request->user()->tenant_id)
        ->latest()
        ->paginate($request->integer('perPage', 15));

    return response()->json([
        'data' => WorkflowRunResource::collection($runs->items()),
        'meta' => [
            'page' => $runs->currentPage(),
            'perPage' => $runs->perPage(),
            'total' => $runs->total(),
        ],
    ]);
}
```

## Review Decision

Request changes. The current snippet has a tenant isolation bug and SQL injection risk, so it should not be merged.

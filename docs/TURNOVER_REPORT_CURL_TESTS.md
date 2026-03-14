# Turnover Report API — cURL tests

**Base URL:** `http://localhost:8000`  
**Endpoint:** `GET /api/admin/reports/turnover`  
(Note: route is under `admin` prefix, so full path is `/api/admin/reports/turnover`.)

Replace `YOUR_TOKEN_HERE` with a valid JWT (e.g. from `POST /api/auth/login`).

---

## 1. Valid request

```bash
curl -X GET "http://localhost:8000/api/admin/reports/turnover?startDate=2025-01-01&endDate=2025-12-31" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

**Expected:** HTTP 200, JSON array (possibly empty `[]`), never `null`.

---

## 2. Missing startDate

```bash
curl -X GET "http://localhost:8000/api/admin/reports/turnover?endDate=2025-12-31" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

**Expected:** HTTP 400, body: `{"error":"startDate is required"}`

---

## 3. Invalid date format

```bash
curl -X GET "http://localhost:8000/api/admin/reports/turnover?startDate=01-01-2025&endDate=31-12-2025" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

**Expected:** HTTP 400, body: `{"error":"startDate must be a valid date"}` (or similar for invalid format)

---

## 4. startDate after endDate

```bash
curl -X GET "http://localhost:8000/api/admin/reports/turnover?startDate=2025-12-31&endDate=2025-01-01" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

**Expected:** HTTP 400, body: `{"error":"startDate must be before or equal to endDate"}`

---

## Automated tests

Run the feature tests (after `composer install`):

```bash
php artisan test tests/Feature/TurnoverReportTest.php
```

Tests bypass JWT and hit the controller directly to assert status codes and JSON shape.

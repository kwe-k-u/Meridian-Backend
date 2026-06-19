Here is a comprehensive, production-ready markdown API documentation file mapping all the authentication and registration flows we have constructed for your system.

---

```markdown
# Meridian OS - Authentication & Tenant Registration API Documentation

This document outlines the authentication, onboarding, and identity management endpoints for Meridian OS. All endpoints return standardized JSON structures.

---

## 1. Company & Tenant Registration
Provisions a new isolated corporate tenant (`Company`) alongside its primary `Owner` profile in an atomic transaction scope.

* **URL:** `/api/v1/auth/register-company`
* **Method:** `POST`
* **Headers:** * `Content-Type: application/json`
  * `Accept: application/json`

### Request Payload & Validations

| Field | Type | Rules | Description |
| :--- | :--- | :--- | :--- |
| `email` | String | Required, Valid Email, Unique (`users.email`) | Corporate email handle. |
| `company_name` | String | Required, Max: 100 chars | Public corporate legal entity name. |
| `country` | String | Required, Max: 100 chars | Country/primary region of operation context. |
| `business_type` | String | Required, Enum: `sole_proprietorship`, `llc`, `corporation`, `partnership`, `agency`, `other` | Backed by `App\Enums\BusinessType`. |
| `username` | String | Required, Max: 50 chars, Unique (`users.display_name`) | Distinct alphanumeric handle for dashboard displays. |
| `password` | String | Required, Min: 8 chars, Confirmed | Secure registration password. |
| `password_confirmation` | String | Required (if `password` is present) | Must match `password` identically. |

### Sample Payload
```json
{
  "email": "finance@bofinvestments.com",
  "company_name": "Bof Investments",
  "country": "Ghana",
  "business_type": "llc",
  "username": "bof_admin",
  "password": "SecurePassword123!",
  "password_confirmation": "SecurePassword123!"
}

```

### Success Response (`21 Created`)

```json
{
  "message": "Company and owner registration completed successfully.",
  "access_token": "1|mpqX7Y2M9PQ...",
  "token_type": "Bearer",
  "user": {
    "user_id": "USR_66723A4BX7Y2",
    "email": "finance@bofinvestments.com",
    "display_name": "bof_admin",
    "status": "active",
    "last_login": "2026-06-19T02:03:00.000000Z",
    "created_at": "2026-06-19T02:03:00.000000Z",
    "updated_at": "2026-06-19T02:03:00.000000Z"
  },
  "company": {
    "company_id": "CMP_66723A4CH1N4",
    "company_name": "Bof Investments",
    "city_of_operation": "Ghana",
    "status": true,
    "created_at": "2026-06-19T02:03:00.000000Z",
    "updated_at": "2026-06-19T02:03:00.000000Z"
  }
}

```

---

## 2. Unified Authentication (Password & Google OAuth Sign-In)

Processes standard password credentials or handles secure validation handshakes for clients deploying Google Sign-In managed by Firebase.

* **URL:** `/api/v1/auth/login`
* **Method:** `POST`

### Variant A: Traditional Credentials Payload

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `username` | String | Required | Accepts either the registered corporate email or user handle string. |
| `password` | String | Required, Min: 6 chars | Alphanumeric account password. |

```json
{
  "username": "finance@bofinvestments.com",
  "password": "SecurePassword123!"
}

```

### Variant B: Google Sign-In (Firebase JWT) Payload

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `provider_token` | String | Required | The cryptographically sound ID Token (JWT) sent down from the client client application framework. |

```json
{
  "provider_token": "eyJhbGciOiJSUzI1NiIsImtpZCI6..."
}

```

### Success Response (`200 OK`)

```json
{
  "access_token": "2|v8TWH1N4V8TW...",
  "token_type": "Bearer",
  "user": {
    "user_id": "USR_66723A4BX7Y2",
    "email": "finance@bofinvestments.com",
    "display_name": "bof_admin",
    "avatar_url": "[https://lh3.googleusercontent.com/](https://lh3.googleusercontent.com/)...",
    "status": "active",
    "last_login": "2026-06-19T02:05:12.000000Z",
    "companies": [
      {
        "company_id": "CMP_66723A4CH1N4",
        "company_name": "Bof Investments",
        "status": true,
        "pivot": {
          "user_id": "USR_66723A4BX7Y2",
          "company_id": "CMP_66723A4CH1N4",
          "role": "owner",
          "is_default": 1
        }
      }
    ]
  }
}

```

---

## 3. Password Reset - Link Generation Request

Blinds malicious actors scanning for valid platform records while distributing a single-use token window for users who have lost credentials.

* **URL:** `/api/v1/auth/forgot-password`
* **Method:** `POST`

### Request Payload & Validations

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `email` | String | Required, Valid Email, Max: 255 chars | The target recovery account email address. |

```json
{
  "email": "finance@bofinvestments.com"
}

```

## Success Response (`200 OK`)

*Note: This output format fires uniformly even if the payload email is not found within the database to mitigate security user-enumeration vectors.*

```json
{
  "message": "If your email is registered in our database, you will receive a password reset link shortly.",
}

```

## 4. Password Reset - Core Modification Execution

Validates single-use token integrity and expiration criteria before mutating standard auth attributes.

* **URL:** `/api/v1/auth/reset-password`
* **Method:** `POST`

### Request Payload & Validations

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `token` | String | Required | The raw token string parsed out from the incoming frontend recovery landing URL. |
| `email` | String | Required, Email, Exists (`users.email`) | Explicit database reference matching the reset frame. |
| `password` | String | Required, Min: 8 chars, Confirmed | The new target replacement login password. |
| `password_confirmation` | String | Required | Double entry confirmation verification verification check. |

```json
{
  "token": "e16f316b8dca2a84fb...",
  "email": "finance@bofinvestments.com",
  "password": "NewUltraSecurePassword2026!",
  "password_confirmation": "NewUltraSecurePassword2026!"
}

```

## Success Response (`200 OK`)

```json
{
  "message": "Your password has been successfully reset. You can now log in with your new credentials."
}

```

---

## Standard API Error Handling Profiles

The API uses standardized structures for validation anomalies, bad signatures, or authentication rejections.

### HTTP `422 Unprocessable Entity` (Form Validation Failure)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": [
      "The email has already been taken."
    ],
    "password": [
      "The password confirmation does not match."
    ]
  }
}

```

### HTTP `401 Unauthorized` (Bad Password / Expired Google Session Token)

```json
{
  "error": "Unauthorized",
  "message": "The credentials provided do not match our records."
}

```

### HTTP `403 Forbidden` (Banned / Suspended Workspace Profile)

```json
{
  "error": "Forbidden",
  "message": "Your account has been deactivated. Please contact support."
}
de
```

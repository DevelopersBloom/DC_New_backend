# Task: one-time password on user creation (force password change at first login)

## Goal
When an admin creates a user, the password they enter/generate is a **one-time temporary password**.
At first login the user must set their own password (meeting the strong-password rules) before using the system.

## Already in place
- `PUT /api/admin/change-password` (`AdminControllerNew::changePassword`, `ChangeOwnPasswordRequest`): any authenticated user changes their own password. Requires `current_password`, `password`, `password_confirmation`; rules: min 10, upper+lower case, digit, symbol, different from current.
- Admin reset (`ResetUserPasswordRequest`) uses the same strong rules.
- Frontend: key icon in the sidebar opens `ChangePasswordModal`.

## Backend work
1. Migration: `users.must_change_password` boolean, default `false` (existing users unaffected).
2. `User` model: cast `must_change_password` to boolean (keep it out of `$fillable`; use `forceFill`).
3. `UserService::create`: set `must_change_password = true`.
4. `AdminControllerNew::changePassword`: set `must_change_password = false` together with the new password.
5. Middleware (e.g. `EnsurePasswordChanged`, alias `password.changed`): if the authenticated user has `must_change_password`, return `403` with `{"code": "password_change_required", "message": ...}` for every route except `PUT admin/change-password` (name that route, e.g. `admin.change-password`, and use `routeIs`). Apply it to both `jwt.auth` groups (`routes/api.php` and `routes/web.php`). `auth/get-user`, `auth/logout`, `auth/refresh` stay open (they are under `auth:api`).
6. `LoginController::userPayload` already returns the model attributes, so `must_change_password` reaches the frontend once it exists on the model.
7. Optional: also set the flag in `UserService::resetPassword` so admin-issued passwords are always temporary.
8. `StoreUserRequest`: temporary password rule is relaxed on purpose (min 8, no strength rules), since it only lives until first login.

## Frontend work (after backend ships)
- `PasswordField` gets a `temporary` mode for the create form: easy generated password (8 chars, lowercase + digits, no look-alike characters), relaxed rules (min 8), hint explaining it is one-time.
- Sidebar: if `user.must_change_password`, render `ChangePasswordModal` in `forced` mode (no cancel, no click-outside close); on success dispatch an auth action that sets `user.must_change_password = false`.

## Acceptance
- New user logs in with the temporary password -> cannot dismiss the modal; any other API call returns 403 `password_change_required`.
- After setting a valid new password the flag is cleared and the app works normally; the old temporary password no longer logs in.
- Existing users are never prompted.

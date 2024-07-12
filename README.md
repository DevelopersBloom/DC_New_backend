# Laravel Backend Setup

## Getting Started

Follow these steps to set up the backend for the project.

### 1. Pull the latest changes from the repository:
```bash
git pull origin main
```

### 2. Copy the `.env.example` file to `.env` and configure your database settings:
```bash
cp .env.example .env
```
Edit the `.env` file to include your database configuration:
```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password
```

### 3. Install necessary dependencies:
```bash
composer install
npm install
```

### 4. Run migrations and seed the database:
```bash
php artisan migrate --seed
```

### 5. Run custom 'run-once' command for database data seeding:
```bash
php artisan run-once
```

### 6. Generate a JWT secret key (if not already done):
```bash
php artisan jwt:secret
```

### 7. Start the backend server:
```bash
php artisan serve
```

### 8. Build frontend assets (if applicable):
```bash
npm run dev
```

### 9. Test API endpoints using tools like Postman or by integrating with the frontend application:
Ensure the following CORS settings in `config/cors.php` to allow requests from the frontend:
```php
'allowed_origins' => ['http://localhost:3000'],
```

## API Endpoints

Here are the available API endpoints:

### Authentication
- **Register**: `POST /api/auth/register`
- **Login**: `POST /api/auth/login`
- **Get User (Protected)**: `GET /api/auth/get-user`
- **Logout (Protected)**: `POST /api/auth/logout`
- **Refresh Token (Protected)**: `POST /api/auth/refresh`

### Testing Protected Endpoints
Make sure to include the `Authorization: Bearer {token}` header in your requests to protected endpoints.

## Additional Notes
- Ensure the frontend makes authenticated requests with the `Authorization: Bearer {token}` header.
- Keep an eye on CORS issues if the frontend is served from a different origin.

---

Feel free to reach out if you encounter any issues during setup.

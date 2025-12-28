# GitHub OAuth Integration

OAuth mechanism for connecting svetliacms to GitHub authentication.

## Endpoints

All endpoints are accessible at `https://efa44.org/oauth/`

### 1. **authorize.php** - Start OAuth Flow
Redirects user to GitHub login.

**Method:** `GET`
**Parameters:**
- `scope` (optional): OAuth scopes (default: `user:email`)

**Example:**
```
https://efa44.org/oauth/authorize.php?scope=user:email
```

### 2. **callback.php** - Handle GitHub Redirect
Exchanges authorization code for access token. This endpoint receives the redirect from GitHub after user authorization.

**Method:** `POST`
**Body:**
```json
{
    "code": "github_authorization_code",
    "state": "state_token"
}
```

**Response:**
```json
{
    "success": true,
    "access_token": "gho_xxxxx",
    "user": {
        "id": 123456,
        "login": "username",
        "name": "User Name",
        "avatar_url": "https://...",
        "email": "user@example.com"
    }
}
```

### 3. **verify.php** - Verify Token
Validates an existing access token and returns user information.

**Method:** `POST`
**Headers:**
- `Authorization: Bearer <access_token>` OR
- `Content-Type: application/json`

**Body (alternative to header):**
```json
{
    "access_token": "gho_xxxxx"
}
```

**Response:**
```json
{
    "success": true,
    "user": {
        "id": 123456,
        "login": "username",
        "name": "User Name",
        "avatar_url": "https://...",
        "email": "user@example.com"
    }
}
```

### 4. **logout.php** - Revoke Token
Revokes an access token.

**Method:** `POST`
**Headers:**
- `Authorization: Bearer <access_token>` OR
- `Content-Type: application/json`

**Body (alternative to header):**
```json
{
    "access_token": "gho_xxxxx"
}
```

**Response:**
```json
{
    "success": true
}
```

### 5. **health.php** - System Status
Checks if OAuth system is properly configured and running.

**Method:** `GET`

**Response:**
```json
{
    "status": "ok",
    "message": "OAuth system is running",
    "endpoints": { ... }
}
```

## Environment Variables Required

These must be set in the PHP server environment (not in .env file):

- `GITHUB_CLIENT_ID`: Your GitHub OAuth app client ID
- `GITHUB_CLIENT_SECRET`: Your GitHub OAuth app client secret
- `REDIRECT_URI`: The URL GitHub redirects to after authorization (e.g., `https://efa44.org/oauth/callback.php`)
- `CMS_ORIGIN`: The origin of your CMS application
- `ALLOWED_ORIGINS`: Comma-separated list of allowed origins for CORS

## CORS Support

All endpoints support CORS with origins specified in `ALLOWED_ORIGINS` environment variable.

## Usage Flow

1. **CMS initiates login:**
   ```javascript
   window.location.href = 'https://efa44.org/oauth/authorize.php?scope=user:email';
   ```

2. **User logs in to GitHub** and grants permission

3. **GitHub redirects to callback** with authorization code

4. **CMS exchanges code for token:**
   ```javascript
   const response = await fetch('https://efa44.org/oauth/callback.php', {
       method: 'POST',
       headers: { 'Content-Type': 'application/json' },
       body: JSON.stringify({ code, state })
   });
   ```

5. **Store access token** in CMS session/storage

6. **Verify token** on subsequent requests:
   ```javascript
   const response = await fetch('https://efa44.org/oauth/verify.php', {
       method: 'POST',
       headers: { 'Authorization': `Bearer ${accessToken}` }
   });
   ```

7. **On logout**, revoke the token:
   ```javascript
   await fetch('https://efa44.org/oauth/logout.php', {
       method: 'POST',
       headers: { 'Authorization': `Bearer ${accessToken}` }
   });
   ```

## GitHub OAuth App Setup

To use this OAuth implementation, you need to create a GitHub OAuth App:

1. Go to https://github.com/settings/developers
2. Click "New OAuth App"
3. Configure:
   - **Application name**: Your app name
   - **Homepage URL**: `https://efa44.org`
   - **Authorization callback URL**: `https://efa44.org/oauth/callback.php`
4. Copy the Client ID and Client Secret to your environment variables

## Error Handling

All endpoints return appropriate HTTP status codes:
- `200` - Success
- `204` - Success (no content)
- `400` - Bad request
- `401` - Unauthorized/Invalid token
- `405` - Method not allowed
- `500` - Server error

Error responses include JSON with an `error` field explaining the issue.

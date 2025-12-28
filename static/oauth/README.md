# Sveltia CMS OAuth Handler

This is a PHP-based OAuth authentication handler for connecting Sveltia CMS to GitHub.

## Setup

### Environment Variables

The following environment variables must be configured in your PHP server:

- `GITHUB_CLIENT_ID` - Your GitHub OAuth App Client ID
- `GITHUB_CLIENT_SECRET` - Your GitHub OAuth App Client Secret
- `REDIRECT_URI` - The callback URI (e.g., `https://efa44.org/oauth/callback`)
- `CMS_ORIGIN` - The origin of your CMS (e.g., `https://efa44.org`)
- `ALLOWED_ORIGINS` - Comma-separated list of allowed origins (optional, supports wildcards with `*`)

### GitHub OAuth App Configuration

1. Go to GitHub Settings → Developer settings → OAuth Apps → New OAuth App
2. Configure the application:
   - **Authorization callback URL**: `https://efa44.org/oauth/callback`
3. Copy the Client ID and Client Secret
4. Set them as environment variables in your PHP server

## How It Works

The OAuth flow has two main steps:

### 1. Authorization Request (`/oauth/auth` or `/oauth/authorize`)

- Sveltia CMS initiates the flow by opening a popup window to `/oauth/auth`
- Query parameters:
  - `provider` - `github` (required)
  - `site_id` - The site identifier (optional)
- The handler:
  - Validates the provider and domain
  - Generates a CSRF token
  - Redirects to GitHub's OAuth authorization endpoint
  - Stores the CSRF token in an HttpOnly cookie (expires in 10 minutes)

### 2. Callback Handler (`/oauth/callback` or `/oauth/redirect`)

- GitHub redirects back to this endpoint with:
  - `code` - Authorization code
  - `state` - CSRF token for validation
- The handler:
  - Validates the CSRF token
  - Exchanges the code for an access token with GitHub API
  - Returns the token to Sveltia CMS via postMessage
  - Clears the CSRF token cookie

## Response Format

The handler communicates with Sveltia CMS using HTML/JavaScript postMessage:

### Success Response
```json
{
  "provider": "github",
  "token": "gho_xxxxxxxxxxxxx"
}
```

### Error Response
```json
{
  "provider": "github",
  "error": "Error message",
  "errorCode": "ERROR_CODE"
}
```

## Error Codes

- `UNSUPPORTED_BACKEND` - Provider is not supported
- `UNSUPPORTED_DOMAIN` - Domain is not allowed
- `MISCONFIGURED_CLIENT` - Client ID or secret not configured
- `AUTH_CODE_REQUEST_FAILED` - Failed to receive authorization code
- `CSRF_DETECTED` - CSRF validation failed
- `TOKEN_REQUEST_FAILED` - Failed to exchange code for token
- `MALFORMED_RESPONSE` - Server returned invalid data

## Security Features

- **CSRF Protection**: Uses random tokens stored in HttpOnly cookies
- **Cookie Security**: Tokens marked as HttpOnly, Secure, and SameSite=Lax
- **Domain Validation**: Optional validation against allowed origins
- **Token Isolation**: Tokens are scoped to provider and expiration time
- **Secure Communication**: Uses postMessage for secure window communication

## Sveltia CMS Configuration

In your Sveltia CMS configuration, set the backend to GitHub and ensure the OAuth handler URL points to your `/oauth` endpoint.

For local development, you may need to use a tunnel service like ngrok to provide an HTTPS URL for GitHub's redirect URI.

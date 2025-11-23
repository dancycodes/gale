# Security Policy

## Supported Versions

We release patches for security vulnerabilities:

| Version | Supported          |
| ------- | ------------------ |
| 0.x     | :white_check_mark: |

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

We take the security of Laravel Gale seriously. If you discover a security vulnerability:

### 1. DO NOT Disclose Publicly

Please do not create a public GitHub issue for the vulnerability.

### 2. Email Us Directly

Send a detailed report to: **dancycodes@gmail.com**

Include:
- Description of the vulnerability
- Steps to reproduce the issue
- Potential impact
- Suggested fix (if you have one)

### 3. Wait for Confirmation

You should receive an acknowledgment within 48 hours. We will:
1. Confirm receipt of your vulnerability report
2. Assign a severity level
3. Develop and test a fix
4. Prepare a security advisory
5. Release a patch

## Security Best Practices

When using Laravel Gale:

### 1. Server-Side Validation

Always validate data on the server:

```php
public function update(Request $request)
{
    $validated = $request->validate([
        'email' => 'required|email|unique:users',
        'role' => 'required|in:user,admin'
    ]);

    // Use validated data...
}
```

### 2. Authorization Checks

Always verify permissions before performing sensitive operations:

```php
public function delete($id)
{
    $post = Post::findOrFail($id);

    if (!auth()->user()->can('delete', $post)) {
        abort(403);
    }

    $post->delete();

    return gale()->state(['deleted' => true]);
}
```

### 3. CSRF Protection

Always use CSRF-protected actions for mutating operations:

```blade
<!-- Correct: Uses $postx with automatic CSRF -->
<button @click="$postx('/delete')">Delete</button>

<!-- Wrong: Uses $post without CSRF -->
<button @click="$post('/delete')">Delete</button>
```

### 4. XSS Prevention

Gale uses Blade's automatic escaping, but be careful with raw HTML:

```php
// Safe: Blade escaping
return gale()->view('partial', ['username' => $username]);

// Better: Use views for dynamic content
return gale()->view('partial', compact('username'));
```

### 5. Rate Limiting

Implement rate limiting for Gale endpoints:

```php
Route::post('/search', [SearchController::class, 'search'])
    ->middleware('throttle:60,1');
```

## Security Advisories

Security advisories will be published in this repository's [Security Advisories](https://github.com/dancycodes/gale/security/advisories).

## Contact

For security concerns: **dancycodes@gmail.com**

---

Thank you for helping keep Laravel Gale and its users safe!

---
name: api-security-best-practices
description: "Implement secure API design patterns including authentication, authorization, input validation, rate limiting, and protection against common API vulnerabilities"
risk: unknown
source: community
date_added: "2026-02-27"
---

## Overview
Guide developers in building secure APIs by implementing authentication, authorization, input validation, rate limiting, and protection against common vulnerabilities. This skill covers security patterns for REST, GraphQL, and WebSocket APIs.

## When to Use This Skill
- Use when designing new API endpoints
- Use when securing existing APIs
- Use when implementing authentication and authorization
- Use when protecting against API attacks (injection, DDoS, etc.)
- Use when conducting API security reviews
- Use when preparing for security audits
- Use when implementing rate limiting and throttling
- Use when handling sensitive data in APIs

### Step 1: Authentication & Authorization
I'll help you implement secure authentication:
- Choose authentication method (JWT, OAuth 2.0, API keys)
- Implement token-based authentication
- Set up role-based access control (RBAC)
- Secure session management
- Implement multi-factor authentication (MFA)

### Step 2: Input Validation & Sanitization
Protect against injection attacks:
- Validate all input data
- Sanitize user inputs
- Use parameterized queries
- Implement request schema validation
- Prevent SQL injection, XSS, and command injection

### Step 3: Rate Limiting & Throttling
Prevent abuse and DDoS attacks:
- Implement rate limiting per user/IP
- Set up API throttling
- Configure request quotas
- Handle rate limit errors gracefully
- Monitor for suspicious activity

### Step 4: Data Protection
Secure sensitive data:
- Encrypt data in transit (HTTPS/TLS)
- Encrypt sensitive data at rest
- Implement proper error handling (no data leaks)
- Sanitize error messages
- Use secure headers

### Step 5: API Security Testing
Verify security implementation:
- Test authentication and authorization
- Perform penetration testing
- Check for common vulnerabilities (OWASP API Top 10)
- Validate input handling
- Test rate limiting

```markdown
## Secure JWT Authentication Implementation

### Authentication Flow

1. User logs in with credentials
2. Server validates credentials
3. Server generates JWT token
4. Client stores token securely
5. Client sends token with each request
6. Server validates token

### Implementation

#### 1. Generate Secure JWT Tokens

\`\`\`javascript
// auth.js
const jwt = require('jsonwebtoken');
const bcrypt = require('bcrypt');

// Login endpoint
app.post('/api/auth/login', async (req, res) => {
  try {
    const { email, password } = req.body;
    
    // Validate input
    if (!email || !password) {
      return res.status(400).json({ 
        error: 'Email and password are required' 
      });
    }
    
    // Find user
    const user = await db.user.findUnique({ 
      where: { email } 
    });
    
    if (!user) {
      // Don't reveal if user exists
      return res.status(401).json({ 
        error: 'Invalid credentials' 
      });
    }
    
    // Verify password
    const validPassword = await bcrypt.compare(
      password, 
      user.passwordHash
    );
    
    if (!validPassword) {
      return res.status(401).json({ 
        error: 'Invalid credentials' 
      });
    }
    
    // Generate JWT token
    const token = jwt.sign(
      { 
        userId: user.id,
        email: user.email,
        role: user.role
      },
      process.env.JWT_SECRET,
      { 
        expiresIn: '1h',
        issuer: 'your-app',
        audience: 'your-app-users'
      }
    );
    
    // Generate refresh token
    const refreshToken = jwt.sign(
      { userId: user.id },
      process.env.JWT_REFRESH_SECRET,
      { expiresIn: '7d' }
    );
    
    // Store refresh token in database
    await db.refreshToken.create({
      data: {
        token: refreshToken,
        userId: user.id,
        expiresAt: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000)
      }
    });
    
    res.json({
      token,
      refreshToken,
      expiresIn: 3600
    });
    
  } catch (error) {
    console.error('Login error:', error);
    res.status(500).json({ 
      error: 'An error occurred during login' 
    });
  }
});
\`\`\`

#### 2. Verify JWT Tokens (Middleware)

\`\`\`javascript
// middleware/auth.js
const jwt = require('jsonwebtoken');

function authenticateToken(req, res, next) {
  // Get token from header
  const authHeader = req.headers['authorization'];
  const token = authHeader && authHeader.split(' ')[1]; // Bearer TOKEN
  
  if (!token) {
    return res.status(401).json({ 
      error: 'Access token required' 
    });
  }
  
  // Verify token
  jwt.verify(
    token, 
    process.env.JWT_SECRET,
    { 
      issuer: 'your-app',
      audience: 'your-app-users'
    },
    (err, user) => {
      if (err) {
        if (err.name === 'TokenExpiredError') {
          return res.status(401).json({ 
            error: 'Token expired' 
          });
        }
        return res.status(403).json({ 
          error: 'Invalid token' 
        });
      }
      
      // Attach user to request
      req.user = user;
      next();
    }
  );
}

module.exports = { authenticateToken };
\`\`\`

#### 3. Protect Routes

\`\`\`javascript
const { authenticateToken } = require('./middleware/auth');

// Protected route
app.get('/api/user/profile', authenticateToken, async (req, res) => {
  try {
    const user = await db.user.findUnique({
      where: { id: req.user.userId },
      select: {
        id: true,
        email: true,
        name: true,
        // Don't return passwordHash
      }
    });
    
    res.json(user);
  } catch (error) {
    res.status(500).json({ error: 'Server error' });
  }
});
\`\`\`

#### 4. Implement Token Refresh

\`\`\`javascript
app.post('/api/auth/refresh', async (req, res) => {
  const { refreshToken } = req.body;
  
  if (!refreshToken) {
    return res.status(401).json({ 
      error: 'Refresh token required' 
    });
  }
  
  try {
    // Verify refresh token
    const decoded = jwt.verify(
      refreshToken, 
      process.env.JWT_REFRESH_SECRET
    );
    
    // Check if refresh token exists in database
    const storedToken = await db.refreshToken.findFirst({
      where: {
        token: refreshToken,
        userId: decoded.userId,
        expiresAt: { gt: new Date() }
      }
    });
    
    if (!storedToken) {
      return res.status(403).json({ 
        error: 'Invalid refresh token' 
      });
    }
    
    // Generate new access token
    const user = await db.user.findUnique({
      where: { id: decoded.userId }
    });
    
    const newToken = jwt.sign(
      { 
        userId: user.id,
        email: user.email,
        role: user.role
      },
      process.env.JWT_SECRET,
      { expiresIn: '1h' }
    );
    
    res.json({
      token: newToken,
      expiresIn: 3600
    });
    
  } catch (error) {
    res.status(403).json({ 
      error: 'Invalid refresh token' 
    });
  }
});
\`\`\`

### Security Best Practices

- ✅ Use strong JWT secrets (256-bit minimum)
- ✅ Set short expiration times (1 hour for access tokens)
- ✅ Implement refresh tokens for long-lived sessions
- ✅ Store refresh tokens in database (can be revoked)
- ✅ Use HTTPS only
- ✅ Don't store sensitive data in JWT payload
- ✅ Validate token issuer and audience
- ✅ Implement token blacklisting for logout
```

### ✅ Do This
- **Use HTTPS Everywhere** - Never send sensitive data over HTTP
- **Implement Authentication** - Require authentication for protected endpoints
- **Validate All Inputs** - Never trust user input
- **Use Parameterized Queries** - Prevent SQL injection
- **Implement Rate Limiting** - Protect against brute force and DDoS
- **Hash Passwords** - Use bcrypt with salt rounds >= 10
- **Use Short-Lived Tokens** - JWT access tokens should expire quickly
- **Implement CORS Properly** - Only allow trusted origins
- **Log Security Events** - Monitor for suspicious activity
- **Keep Dependencies Updated** - Regularly update packages
- **Use Security Headers** - Implement Helmet.js
- **Sanitize Error Messages** - Don't leak sensitive information

### ❌ Don't Do This
- **Don't Store Passwords in Plain Text** - Always hash passwords
- **Don't Use Weak Secrets** - Use strong, random JWT secrets
- **Don't Trust User Input** - Always validate and sanitize
- **Don't Expose Stack Traces** - Hide error details in production
- **Don't Use String Concatenation for SQL** - Use parameterized queries
- **Don't Store Sensitive Data in JWT** - JWTs are not encrypted
- **Don't Ignore Security Updates** - Update dependencies regularly
- **Don't Use Default Credentials** - Change all default passwords
- **Don't Disable CORS Completely** - Configure it properly instead
- **Don't Log Sensitive Data** - Sanitize logs

## When to Use
This skill is applicable to execute the workflow or actions described in the overview.

## Limitations
- Use this skill only when the task clearly matches the scope described above.
- Do not treat the output as a substitute for environment-specific validation, testing, or expert review.
- Stop and ask for clarification if required inputs, permissions, safety boundaries, or success criteria are missing.

<?php
/**
 * FitPaisa — Módulo JWT (JSON Web Token)
 *
 * Implementación pura de JWT HS256 sin dependencias externas,
 * adaptada para el entorno serverless de Vercel PHP.
 *
 * El secreto de firma se lee de la variable de entorno JWT_SECRET.
 * Si no está configurada, se genera un fallback derivado de las credenciales
 * de BD (siempre consistente dentro del mismo deployment).
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 * @version  1.0.0
 */

declare(strict_types=1);

/** Tiempo de expiración del token: 2 horas (en segundos) */
const JWT_TTL = 7200;

/**
 * Retorna el secreto de firma JWT desde el entorno.
 *
 * @return string Secreto de al menos 32 caracteres.
 */
function jwt_secret(): string
{
    $secret = getenv('JWT_SECRET');
    if ($secret && strlen($secret) >= 32) {
        return $secret;
    }

    /* Ya no permitiremos fallbacks derivados por seguridad. 
     * El administrador DEBE configurar JWT_SECRET en el entorno. */
    error_log('[FitPaisa][SECURITY] JWT_SECRET no configurado o muy corto.');
    fp_error(500, 'Error de configuración de seguridad. Contacta al soporte.');
}

/**
 * Codifica datos en Base64 URL-safe (sin padding).
 *
 * @param string $data Datos a codificar.
 * @return string      Base64 URL-safe.
 */
function jwt_b64_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Decodifica una cadena Base64 URL-safe.
 *
 * @param string $data Cadena a decodificar.
 * @return string      Datos decodificados.
 */
function jwt_b64_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Genera un token JWT firmado con HS256.
 *
 * El payload incluye automáticamente:
 *  - iat (issued at): timestamp de creación
 *  - exp (expiration): timestamp de expiración (iat + JWT_TTL)
 *
 * @param array $payload Datos del usuario: {user_id, email, role}.
 * @return string Token JWT completo (header.payload.signature).
 */
function jwt_create(array $payload): string
{
    $now     = time();
    $header  = jwt_b64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = array_merge($payload, ['iat' => $now, 'exp' => $now + JWT_TTL]);
    $body    = jwt_b64_encode(json_encode($payload));

    $signature = jwt_b64_encode(
        hash_hmac('sha256', "{$header}.{$body}", jwt_secret(), true)
    );

    return "{$header}.{$body}.{$signature}";
}

/**
 * Verifica y decodifica un token JWT.
 *
 * Comprueba:
 *  1. Estructura correcta (3 partes separadas por punto)
 *  2. Firma HMAC válida (resistente a timing attacks con hash_equals)
 *  3. Token no expirado
 *
 * @param string $token Token JWT a verificar.
 * @return array|null   Payload decodificado o null si el token es inválido/expirado.
 */
function jwt_verify(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$header, $body, $signature] = $parts;

    /* Recalcular la firma esperada */
    $expected = jwt_b64_encode(
        hash_hmac('sha256', "{$header}.{$body}", jwt_secret(), true)
    );

    /* Comparación segura contra timing attacks */
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $payload = json_decode(jwt_b64_decode($body), true);

    if (!is_array($payload)) {
        return null;
    }

    /* Verificar expiración */
    if (!isset($payload['exp']) || $payload['exp'] < time()) {
        return null;
    }

    return $payload;
}

/**
 * Extrae y verifica el token JWT de la cabecera Authorization: Bearer <token>.
 *
 * @return array|null Payload del token o null si no hay token válido.
 */
function jwt_from_request(): ?array
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? '';

    if (!str_starts_with($authHeader, 'Bearer ')) {
        return null;
    }

    $token = substr($authHeader, 7);
    return jwt_verify(trim($token));
}

/**
 * Middleware de autenticación: aborta con 401 si el token no es válido.
 *
 * @param  string|null $requiredRole Rol requerido ('USER', 'COACH', 'ADMIN') o null para cualquiera.
 * @return array Payload del token validado.
 */
function jwt_require(?string $requiredRole = null): array
{
    /* Necesitamos fp_error del módulo _db.php */
    if (!function_exists('fp_error')) {
        require_once __DIR__ . '/_db.php';
    }

    $payload = jwt_from_request();

    if ($payload === null) {
        fp_error(401, 'No autenticado. Inicia sesión para continuar.');
    }

    if ($requiredRole !== null && ($payload['role'] ?? '') !== $requiredRole) {
        fp_error(403, 'No tienes permisos para realizar esta acción.');
    }

    return $payload;
}

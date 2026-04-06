<?php
// jwt_helper.php
// Implementación mínima de JWT sin dependencias (Composer)
// ¡En un entorno real de alta seguridad es recomendable usar Firebase PHP JWT!

define('JWT_SECRET', 'FitPaisa_Super_Secret_Key_1234$!');

function base64UrlEncode($data) {
    return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
}

function base64UrlDecode($data) {
    $remainder = strlen($data) % 4;
    if ($remainder !== 0) {
        $padlen = 4 - $remainder;
        $data .= str_repeat('=', $padlen);
    }
    return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
}

/**
 * Genera un token JWT
 * 
 * @param array $payload Datos a incluir en el token (deben ser un array asociativo)
 * @param int $duration Duración en segundos (por defecto 2 horas = 7200)
 */
function create_jwt($payload, $duration = 7200) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload['exp'] = time() + $duration; // Expiración
    $payload['iat'] = time(); // Fecha de emisión
    
    $base64UrlHeader = base64UrlEncode($header);
    $base64UrlPayload = base64UrlEncode(json_encode($payload));
    
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, JWT_SECRET, true);
    $base64UrlSignature = base64UrlEncode($signature);
    
    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
}

/**
 * Valida un token JWT y devuelve el payload o falso
 */
function validate_jwt($jwt) {
    $tokenParts = explode('.', $jwt);
    if (count($tokenParts) != 3) {
        return false; // Token mal formado
    }
    
    $header = base64UrlDecode($tokenParts[0]);
    $payload = base64UrlDecode($tokenParts[1]);
    $signatureProvided = $tokenParts[2];
    
    // Verificar firma
    $base64UrlHeader = base64UrlEncode($header);
    $base64UrlPayload = base64UrlEncode($payload);
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, JWT_SECRET, true);
    $base64UrlSignature = base64UrlEncode($signature);
    
    if (hash_equals($base64UrlSignature, $signatureProvided)) {
        $data = json_decode($payload, true);
        
        // Comprobar expiración
        if (isset($data['exp']) && $data['exp'] < time()) {
            return false; // Token expirado
        }
        return $data; // Token válido
    }
    return false; // Firma inválida
}

/**
 * Extrae el JWT de la cabecera HTTP (Authorization: Bearer <token>)
 */
function get_bearer_token() {
    $headers = null;
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER["Authorization"]);
    } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) { // Nginx o fast CGI
        $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }
    
    if (!empty($headers)) {
        if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
            return $matches[1];
        }
    }
    return null;
}
?>

<?php
/**
 * FitPaisa — Endpoint de Mensajería Interna
 *
 * Comunicación bidireccional entre usuarios y entrenadores.
 * Implementa el LLD §4.6 con eliminación lógica y marcado de lectura.
 *
 * Rutas:
 *   POST /api/messages.php?action=send       → Enviar mensaje
 *   GET  /api/messages.php?action=inbox      → Lista de conversaciones
 *   GET  /api/messages.php?action=thread&with=N → Hilo con otro usuario
 *   POST /api/messages.php?action=read       → Marcar mensajes como leídos
 *   GET  /api/messages.php?action=unread     → Contador de no leídos
 *   POST /api/messages.php?action=delete     → Eliminación lógica
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_jwt.php';

fp_cors();

$payload = jwt_require();
$action  = fp_sanitize($_GET['action'] ?? 'inbox', 32);

match ($action) {
    'send'   => handle_send($payload),
    'inbox'  => handle_inbox($payload),
    'thread' => handle_thread($payload),
    'read'   => handle_mark_read($payload),
    'unread' => handle_unread_count($payload),
    'delete' => handle_delete($payload),
    default  => fp_error(400, "Acción '{$action}' no reconocida."),
};

/* ══════════════════════════════════════════════════════════════════════
   ENVIAR MENSAJE
   ══════════════════════════════════════════════════════════════════════ */
function handle_send(array $payload): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fp_error(405, 'Método no permitido.');
    }

    $body       = fp_json_body();
    $receiverId = (int) ($body['receiver_id'] ?? 0);
    $content    = fp_sanitize($body['content'] ?? '', 2000);

    if ($receiverId <= 0) {
        fp_error(400, 'ID de destinatario inválido.');
    }
    if ($receiverId === $payload['user_id']) {
        fp_error(400, 'No puedes enviarte mensajes a ti mismo.');
    }
    if (empty($content)) {
        fp_error(400, 'El mensaje no puede estar vacío.');
    }

    /* Verificar que el destinatario existe */
    $receiver = fp_query(
        'SELECT user_id, role FROM users WHERE user_id = :uid AND is_active = TRUE',
        [':uid' => $receiverId]
    )->fetch();

    if (!$receiver) {
        fp_error(404, 'Destinatario no encontrado.');
    }

    $stmt = fp_query(
        'INSERT INTO messages (sender_id, receiver_id, content, sent_at)
         VALUES (:sid, :rid, :content, NOW())
         RETURNING message_id, sent_at',
        [
            ':sid'     => $payload['user_id'],
            ':rid'     => $receiverId,
            ':content' => $content,
        ]
    );

    $msg = $stmt->fetch();
    fp_success([
        'message'    => 'Mensaje enviado.',
        'message_id' => $msg['message_id'],
        'sent_at'    => $msg['sent_at'],
    ], 201);
}

/* ══════════════════════════════════════════════════════════════════════
   INBOX — Lista de conversaciones únicas
   ══════════════════════════════════════════════════════════════════════ */
function handle_inbox(array $payload): never
{
    $uid = $payload['user_id'];

    /* Obtener el último mensaje de cada conversación */
    $threads = fp_query(
        'SELECT DISTINCT ON (other_id)
                other_id,
                other_name,
                other_role,
                last_content,
                last_sent,
                unread_count
         FROM (
             SELECT
                 CASE WHEN m.sender_id = :uid THEN m.receiver_id ELSE m.sender_id END AS other_id,
                 CASE WHEN m.sender_id = :uid THEN r.full_name   ELSE s.full_name  END AS other_name,
                 CASE WHEN m.sender_id = :uid THEN r.role        ELSE s.role       END AS other_role,
                 m.content AS last_content,
                 m.sent_at AS last_sent,
                 (SELECT COUNT(*) FROM messages m2
                  WHERE m2.receiver_id = :uid AND m2.sender_id = (
                      CASE WHEN m.sender_id = :uid THEN m.receiver_id ELSE m.sender_id END
                  ) AND m2.read_at IS NULL AND m2.is_deleted = FALSE) AS unread_count
             FROM messages m
             JOIN users s ON s.user_id = m.sender_id
             JOIN users r ON r.user_id = m.receiver_id
             WHERE (m.sender_id = :uid OR m.receiver_id = :uid) AND m.is_deleted = FALSE
             ORDER BY m.sent_at DESC
         ) sub
         ORDER BY other_id, last_sent DESC',
        [':uid' => $uid]
    )->fetchAll();

    fp_success(['threads' => $threads]);
}

/* ══════════════════════════════════════════════════════════════════════
   HILO DE CONVERSACIÓN
   ══════════════════════════════════════════════════════════════════════ */
function handle_thread(array $payload): never
{
    $otherUserId = (int) ($_GET['with'] ?? 0);
    if ($otherUserId <= 0) {
        fp_error(400, 'ID de usuario inválido.');
    }

    $limit  = min((int) ($_GET['limit'] ?? 50), 200);
    $offset = max((int) ($_GET['offset'] ?? 0), 0);

    $messages = fp_query(
        'SELECT m.message_id, m.sender_id, m.receiver_id, m.content,
                m.sent_at, m.read_at,
                s.full_name AS sender_name
         FROM messages m
         JOIN users s ON s.user_id = m.sender_id
         WHERE ((m.sender_id = :uid AND m.receiver_id = :oid)
             OR (m.sender_id = :oid AND m.receiver_id = :uid))
           AND m.is_deleted = FALSE
         ORDER BY m.sent_at DESC
         LIMIT :lim OFFSET :off',
        [
            ':uid' => $payload['user_id'],
            ':oid' => $otherUserId,
            ':lim' => $limit,
            ':off' => $offset,
        ]
    )->fetchAll();

    fp_success(['messages' => array_reverse($messages), 'with_user_id' => $otherUserId]);
}

/* ══════════════════════════════════════════════════════════════════════
   MARCAR COMO LEÍDOS
   ══════════════════════════════════════════════════════════════════════ */
function handle_mark_read(array $payload): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fp_error(405, 'Método no permitido.');
    }

    $body        = fp_json_body();
    $senderId    = (int) ($body['sender_id'] ?? 0);

    if ($senderId <= 0) {
        fp_error(400, 'ID de remitente inválido.');
    }

    $updated = fp_query(
        'UPDATE messages
         SET read_at = NOW()
         WHERE receiver_id = :uid AND sender_id = :sid AND read_at IS NULL AND is_deleted = FALSE',
        [':uid' => $payload['user_id'], ':sid' => $senderId]
    )->rowCount();

    fp_success(['marked_read' => $updated]);
}

/* ══════════════════════════════════════════════════════════════════════
   CONTADOR DE NO LEÍDOS
   ══════════════════════════════════════════════════════════════════════ */
function handle_unread_count(array $payload): never
{
    $count = fp_query(
        'SELECT COUNT(*) FROM messages
         WHERE receiver_id = :uid AND read_at IS NULL AND is_deleted = FALSE',
        [':uid' => $payload['user_id']]
    )->fetchColumn();

    fp_success(['unread' => (int) $count]);
}

/* ══════════════════════════════════════════════════════════════════════
   ELIMINAR (lógico — solo el remitente)
   ══════════════════════════════════════════════════════════════════════ */
function handle_delete(array $payload): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        fp_error(405, 'Método no permitido.');
    }

    $body      = fp_json_body();
    $messageId = (int) ($body['message_id'] ?? 0);

    if ($messageId <= 0) {
        fp_error(400, 'ID de mensaje inválido.');
    }

    $updated = fp_query(
        'UPDATE messages SET is_deleted = TRUE
         WHERE message_id = :mid AND sender_id = :uid AND is_deleted = FALSE',
        [':mid' => $messageId, ':uid' => $payload['user_id']]
    )->rowCount();

    if ($updated === 0) {
        fp_error(404, 'Mensaje no encontrado o ya eliminado.');
    }

    fp_success(['message' => 'Mensaje eliminado.']);
}

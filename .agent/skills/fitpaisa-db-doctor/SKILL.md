---
name: fitpaisa-db-doctor
description: Experto en diagnóstico y reparación de conexiones de base de datos Neon PostgreSQL para el ecosistema FitPaisa (PHP + Vercel). Incluye soporte para el catálogo híbrido con Open Food Facts, el sistema de correo Gmail SMTP, la gestión de usuarios privilegiados y el sistema de emails premium de soporte.
---

# FitPaisa DB Doctor Skill

Esta skill proporciona el conocimiento técnico necesario para identificar, diagnosticar y resolver problemas de conexión, migración de esquema y entregabilidad de correos en FitPaisa.

---

## 1. El Kernel de Conexión (`api/_db.php`)

FitPaisa utiliza un sistema de conexión centralizado. **Cualquier cambio en la lógica de BD debe realizarse PRIMERO en este archivo.**

### Reglas Críticas del Kernel:
- **Singleton PDO**: Siempre usa la función `fp_db()` para obtener la conexión.
- **Sincronización de Entornos**: El kernel cambia automáticamente la base de datos según `VERCEL_ENV`:
    - `production` → Usa variables con sufijo `_PROD` (o las estándar de Vercel).
    - `preview` / `local` / `development` → Forza el uso de la base de datos **`fitpaisa_testing`**.
- **SSL Obligatorio**: Todas las conexiones a Neon DEBEN incluir `sslmode=require` en el DSN.
- **DSN Canónico**: `"pgsql:host={$host};port={$port};dbname={$db};sslmode=require"`

---

## 2. Arquitectura de Bases de Datos

| Entorno     | Base de Datos       | Variable Clave      |
|-------------|---------------------|---------------------|
| Producción  | `neondb`            | `PGDATABASE_PROD`   |
| Testing     | `fitpaisa_testing`  | `PGDATABASE`        |

### Esquema Completo (Tablas v7.2.0)

| Tabla                | Descripción                                        |
|----------------------|----------------------------------------------------|
| `users`             | Usuarios del sistema (roles: USER, COACH, ADMIN)   |
| `profiles`          | Datos físicos del usuario (peso, talla, objetivos) |
| `food_catalog`      | Catálogo de alimentos (local + externo/OFF)        |
| `food_entries`      | Registro diario de nutrición                       |
| `workout_plans`     | Planes de entrenamiento                            |
| `exercises`         | Ejercicios de cada plan                            |
| `body_logs`         | Historial de medidas físicas                       |
| `messages`          | Mensajería interna coach-usuario                   |
| `subscriptions`     | Suscripciones Premium                              |
| `progress_photos`   | Fotos de progreso                                  |
| `password_resets`   | Tokens de recuperación de contraseña               |
| `notifications`     | Notificaciones del sistema                         |
| `rate_limits`       | Control de peticiones (Rate Limiting)              |

### Columnas Críticas en `users`:
```sql
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(30);
ALTER TABLE users ADD COLUMN IF NOT EXISTS login_attempts SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_until TIMESTAMPTZ;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login TIMESTAMPTZ;
```

### Columnas Críticas en `profiles` (parche completo v7.2.0):
```sql
-- ⚠️ CRÍTICO: CREATE TABLE IF NOT EXISTS NO modifica tablas existentes.
-- Si profiles fue recreada con esquema simplificado, ejecutar TODO este bloque:
ALTER TABLE profiles ADD COLUMN IF NOT EXISTS weight         DECIMAL(5,2)   NOT NULL DEFAULT 0.01;
ALTER TABLE profiles ADD COLUMN IF NOT EXISTS height         DECIMAL(5,2)   NOT NULL DEFAULT 0.01;
ALTER TABLE profiles ADD COLUMN IF NOT EXISTS age            SMALLINT       NOT NULL DEFAULT 25;
ALTER TABLE profiles ADD COLUMN IF NOT EXISTS gender         gender_type    NOT NULL DEFAULT 'OTHER';
ALTER TABLE profiles ADD COLUMN IF NOT EXISTS objective      objective_type NOT NULL DEFAULT 'MAINTAIN';
ALTER TABLE profiles ADD COLUMN IF NOT EXISTS activity_level activity_level NOT NULL DEFAULT 'MODERATE';
ALTER TABLE profiles ADD COLUMN IF NOT EXISTS target_weight  DECIMAL(5,2);
ALTER TABLE profiles ADD COLUMN IF NOT EXISTS target_time_weeks SMALLINT;
ALTER TABLE profiles ADD COLUMN IF NOT EXISTS timezone       VARCHAR(50);
ALTER TABLE profiles ADD COLUMN IF NOT EXISTS updated_at     TIMESTAMPTZ    NOT NULL DEFAULT NOW();
```

### Columnas Críticas en `food_catalog` (v7.0.0):
```sql
ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS external_id VARCHAR(100) UNIQUE;
ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS is_verified BOOLEAN DEFAULT TRUE;
ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS image_url TEXT;
```

---

## 3. Sistema de Correo (Gmail SMTP)

El sistema de correo fue **migrado de SendGrid a Gmail SMTP** el 2026-04-21.

### Configuración Actual:
- **Motor**: PHPMailer ^6.9 (vía Composer)
- **Remitente**: `fit.paisa.app@gmail.com`
- **Credenciales**: Contraseña de Aplicación de Google (16 letras)
- **Variables de Entorno**: `GMAIL_USER`, `GMAIL_APP_PASS`

### ⚠️ Firma de `fp_mail()` — CRÍTICO:
```php
// El 4º parámetro $code ES OPCIONAL. Llamar sin él es correcto.
function fp_mail(string $to, string $subject, string $html, string $code = ''): bool
```

### Variables de Entorno Requeridas en Vercel:

| Variable           | Entorno      | Valor                           |
|--------------------|--------------|---------------------------------|
| `GMAIL_USER`       | Ambos        | `fit.paisa.app@gmail.com`      |
| `GMAIL_APP_PASS`   | Ambos        | Contraseña de Aplicación Google |
| `JWT_SECRET`       | Ambos        | String largo aleatorio (≥32 ch) |
| `SETUP_TOKEN`      | Ambos        | Token de protección del setup   |
| `PGHOST_PROD`      | Production   | Host de Neon para producción   |
| `PGUSER_PROD`      | Production   | Usuario de Neon para producción |
| `PGPASSWORD_PROD`  | Production   | Contraseña producción          |
| `PGDATABASE_PROD`  | Production   | `neondb`                       |
| `PGHOST`           | Preview      | Host de Neon para testing      |
| `PGUSER`           | Preview      | Usuario de Neon para testing   |
| `DB_PASSWORD_NUEVA`| Preview      | Contraseña testing             |
| `PGDATABASE`       | Preview      | `fitpaisa_testing`             |

---

## 4. Sistema de Emails de Soporte (v7.2.0)

### Archivos involucrados:
| Archivo | Propósito |
|---------|-----------|
| `api/email-soporte.php` | Template HTML premium del email de confirmación |
| `api/contact.php` | Endpoint que genera el Ticket ID y envía los emails |

### Función del template:
```php
// Genera el HTML del email premium. Llamar desde contact.php.
require_once __DIR__ . '/email-soporte.php';
$html = fp_email_soporte($nombre, $correo, $mensaje, $ticketId);
```

### Generación del Ticket ID:
```php
$ticketId = 'FP-' . strtoupper(substr(uniqid('', true), -8));
// Ejemplo: FP-A3F7C2B1
```

### Diseño del email (Stitch — Kinetic Void):
- Background: `#080a0f` (obsidiana profunda)
- Acento: `#ff3b3b` (rojo cinético)
- Tipografía: Space Grotesk (headlines) + Inter (body)
- Card glassmorphic con detalles del ticket
- Badge rojo con Ticket ID único
- Botón CTA gradiente → `https://fit-paisa.vercel.app`

---

## 5. Gestión de Usuarios Privilegiados (Admin / Coach)

El script `api/seed-admin.php` crea o actualiza usuarios con rol especial.

### URL de uso:
```
https://[HOST]/api/seed-admin.php
  ?token=SETUP_TOKEN
  &role=ADMIN         (o COACH / USER)
  &email=email@host.com
  &password=TuPass123!
  &name=Nombre+Completo
```

### ⚠️ Prerequisito CRÍTICO:
La tabla `users` DEBE tener `is_active`. La tabla `profiles` DEBE tener las 10 columnas del parche.
Ejecutar SIEMPRE `setup-db.php` antes de llamar a `seed-admin.php`.

---

## 6. Catálogo Híbrido (Open Food Facts)

1. **Local**: Busca primero en `food_catalog` donde `is_verified = TRUE`.
2. **OFF Fallback**: Si hay < 5 resultados, consulta Open Food Facts API (timeout 2.5s).
3. **Persistencia Automática**: Al seleccionar un alimento externo, se guarda con `is_verified = FALSE`.

---

## 7. Diagnóstico de Errores Comunes

### "SQLSTATE[42703]: Undefined column updated_at of relation profiles"
- **Causa**: La tabla `profiles` fue recreada con esquema simplificado sin `updated_at` (ni otras 6 columnas).
- **Causa raíz**: `CREATE TABLE IF NOT EXISTS` no altera tablas existentes. El setup antiguo solo parchaba 3 columnas.
- **Fix**: Ejecutar el parche SQL completo de la Sección 2 en Neon Console.

### Error de respuesta vacía / "0 bytes"
- **Causa**: Error de sintaxis PHP en algún controlador.
- **Acción**: `php -l api/auth.php` para detectar el error exacto.

### "Error de conexión." (formulario de contacto o soporte)
- **Causa más probable**: `fp_mail()` tiene `$code` como parámetro obligatorio.
- **Fix**: `string $code = ''` en `_mailer.php`.

### Timer del modal descolocado
- **Causa**: Falta `overflow: hidden` en `.success-modal`.
- **Fix**: `overflow: hidden` + `padding-bottom: 44px` en `.success-modal` (index.html).

### Correos que no llegan
- **Diagnóstico**: `GET /api/test-mail.php?to=EMAIL`

---

## 8. Procedimiento de Reparación Estándar

1. Verificar kernel `api/_db.php` sin modificaciones accidentales.
2. Eliminar duplicidades de `fp_db()` — usar solo `require_once '_db.php'`.
3. Auditar DSN: `"pgsql:host={$host};port={$port};dbname={$db};sslmode=require"`.
4. Ejecutar `setup-db.php` para parchar columnas faltantes.
5. Diagnóstico: `/api/diag_db.php`.

---

## 9. URLs de Referencia

| Acción                    | URL |
|---------------------------|-----|
| Setup DB (Producción)     | `https://fit-paisa.vercel.app/api/setup-db.php?token=fitpaisa_setup_2026` |
| Setup DB (Testing)        | `https://fit-paisa-git-testing-fit-paisa.vercel.app/api/setup-db.php?token=fitpaisa_setup_2026` |
| Crear Admin (Testing)     | `https://fit-paisa-git-testing-fit-paisa.vercel.app/api/seed-admin.php?token=fitpaisa_setup_2026&role=ADMIN&email=...` |
| Test de Correo            | `https://fit-paisa.vercel.app/api/test-mail.php?to=EMAIL` |
| Diagnóstico DB            | `https://fit-paisa.vercel.app/api/diag_db.php` |

---

## 10. Flujo de Sincronización de Ramas

```powershell
# Verificar sintaxis
php -l api/auth.php

# Testing → Master (promover a producción)
git checkout master; git merge testing; git push origin master

# Master → Testing (propagar fixes de producción)
git checkout testing; git merge master; git push origin testing

git checkout master  # volver a trabajar en master
```

---

## 11. Recreación de la BD de Testing desde Cero

1. **En Neon Console**: Crear nueva BD `fitpaisa_testing`.
2. **Ejecutar ENUMs** desde la consola SQL (ver `api/setup-db.php`).
3. **Ejecutar `setup-db.php`** desde la URL de testing — parcheará todas las columnas.
4. **Crear usuarios privilegiados** via `seed-admin.php`.

> ⚠️ **NUNCA** usar SQL manual simplificado para crear las tablas. Siempre ejecutar `setup-db.php` que es idempotente y garantiza el esquema completo.

---
name: fitpaisa-db-doctor
description: Experto en diagnóstico y reparación de conexiones de base de datos Neon PostgreSQL para el ecosistema FitPaisa (PHP + Vercel). Incluye soporte para el catálogo híbrido con Open Food Facts, el sistema de correo Gmail SMTP y la gestión de usuarios privilegiados.
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

### Esquema Completo (Tablas v7.1.0)
Las siguientes tablas deben existir en AMBAS bases de datos:

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
-- La tabla users SIEMPRE debe tener estas columnas (la versión simplificada las omitía):
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(30);
ALTER TABLE users ADD COLUMN IF NOT EXISTS login_attempts SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_until TIMESTAMPTZ;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login TIMESTAMPTZ;
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
// El 4º parámetro $code ES OPCIONAL. Llamar sin él (p.ej. desde contact.php) es correcto.
function fp_mail(string $to, string $subject, string $html, string $code = ''): bool
```
> **Bug conocido (resuelto v7.1.0)**: Si `$code` es obligatorio, el formulario de contacto lanza un Fatal PHP Error porque `contact.php` llama a `fp_mail()` con solo 3 argumentos.

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

## 4. Gestión de Usuarios Privilegiados (Admin / Coach)

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
- Si el usuario ya existe: **actualiza** rol y contraseña.
- Si no existe: **crea** el usuario nuevo.

### ⚠️ Prerequisito:
La tabla `users` DEBE tener la columna `is_active`. Si se recreó desde el esquema simplificado, ejecutar el SQL de parche de la sección 2 antes de llamar a este script.

---

## 5. Catálogo Híbrido (Open Food Facts)

Desde v7.0.0 el sistema de alimentos opera en modo híbrido:

1. **Local**: Busca primero en `food_catalog` donde `is_verified = TRUE`.
2. **OFF Fallback**: Si hay < 5 resultados, consulta Open Food Facts API (timeout 2.5s).
3. **Persistencia Automática**: Al seleccionar un alimento externo, se guarda en `food_catalog` con `is_verified = FALSE`.

---

## 6. Diagnóstico de Errores Comunes

### Error de "0 bytes" / Respuesta Vacía (Fatal PHP Error)
- **Causa**: Error de sintaxis en algún controlador.
- **Acción**: `php -l api/auth.php` para detectar el error exacto.

### "Error de conexión. Inténtalo de nuevo." (formulario de contacto)
- **Causa más probable**: `fp_mail()` tiene `$code` como parámetro obligatorio.
- **Fix**: Asegúrate de que `_mailer.php` tenga `string $code = ''` (con valor por defecto).

### Timer del modal descolocado
- **Causa**: Falta `overflow: hidden` en `.success-modal`.
- **Fix**: Añadir `overflow: hidden` y `padding-bottom: 44px` al `.success-modal` en `index.html`.

### Correos que no llegan / van a SPAM
- **Causa**: Credenciales de Gmail incorrectas.
- **Diagnóstico**: `GET /api/test-mail.php?to=correo@gmail.com`

---

## 7. Procedimiento de Reparación Estándar

1. **Verificar el Kernel**: `api/_db.php` no debe estar modificado accidentalmente.
2. **Eliminar Duplicidades**: Si un archivo tiene su propio `fp_db()`, eliminarlo y usar `require_once '_db.php'`.
3. **Auditoría de DSN**: `"pgsql:host={$host};port={$port};dbname={$db};sslmode=require"`.
4. **Diagnóstico**: Acceder a `/api/diag_db.php` para auditoría en tiempo real.

---

## 8. URLs de Referencia

| Acción                    | URL |
|---------------------------|-----|
| Setup DB (Producción)     | `https://fit-paisa.vercel.app/api/setup-db.php?token=fitpaisa_setup_2026` |
| Setup DB (Testing)        | `https://fit-paisa-git-testing-fit-paisa.vercel.app/api/setup-db.php?token=fitpaisa_setup_2026` |
| Crear Admin (Testing)     | `https://fit-paisa-git-testing-fit-paisa.vercel.app/api/seed-admin.php?token=fitpaisa_setup_2026&role=ADMIN&email=...&password=...` |
| Test de Correo            | `https://fit-paisa.vercel.app/api/test-mail.php?to=EMAIL` |
| Diagnóstico DB            | `https://fit-paisa.vercel.app/api/diag_db.php` |

---

## 9. Flujo de Sincronización de Ramas

```powershell
# Verificar sintaxis PHP
php -l api/auth.php

# Sincronizar master → testing (propagar fixes de producción)
git checkout testing; git merge master; git push origin testing

# Sincronizar testing → master (promover a producción)
git checkout master; git merge testing; git push origin master
git checkout master  # volver a trabajar en master
```

---

## 10. Recreación de la BD de Testing desde Cero

Si la base de datos `fitpaisa_testing` se pierde o corrompe:

1. **En Neon Console**: Crear nueva base de datos `fitpaisa_testing`.
2. **Ejecutar ENUMs y tablas base** desde la consola SQL de Neon (ver el SQL completo en `api/setup-db.php`).
3. **Parche de columnas faltantes** en `users` (sección 2 de esta skill).
4. **Ejecutar setup-db.php** desde la URL de testing para poblar los datos base.
5. **Crear usuarios privilegiados** via `seed-admin.php` con los mismos datos que producción.

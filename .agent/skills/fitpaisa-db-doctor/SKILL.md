---
name: fitpaisa-db-doctor
description: Experto en diagnóstico y reparación de conexiones de base de datos Neon PostgreSQL para el ecosistema FitPaisa (PHP + Vercel). Incluye soporte para el catálogo híbrido con Open Food Facts y el sistema de correo Gmail SMTP.
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

### Esquema Completo (Tablas v7.0.0)
Las siguientes tablas deben existir en AMBAS bases de datos:

| Tabla                | Descripción |
|----------------------|-------------|
| `users`             | Usuarios del sistema (roles: USER, COACH, ADMIN) |
| `profiles`          | Datos físicos del usuario (peso, talla, objetivos) |
| `food_catalog`      | Catálogo de alimentos (local + externo/OFF) |
| `food_entries`      | Registro diario de nutrición |
| `workout_plans`     | Planes de entrenamiento |
| `exercises`         | Ejercicios de cada plan |
| `body_logs`         | Historial de medidas físicas |
| `messages`          | Mensajería interna coach-usuario |
| `subscriptions`     | Suscripciones Premium |
| `progress_photos`   | Fotos de progreso |
| `password_resets`   | Tokens de recuperación de contraseña |
| `notifications`     | Notificaciones del sistema |
| `rate_limits`       | Control de peticiones (Rate Limiting) |

### Columnas Críticas en `food_catalog` (v7.0.0):
```sql
external_id  VARCHAR(100) UNIQUE   -- Código de barras de Open Food Facts
is_verified  BOOLEAN DEFAULT TRUE  -- TRUE=local/verificado, FALSE=externo/OFF
image_url    TEXT                  -- URL de imagen del producto
```

---

## 3. Sistema de Correo (Gmail SMTP)

El sistema de correo fue **migrado de SendGrid a Gmail SMTP** el 2026-04-21 para garantizar entregabilidad 100% en Gmail.

### Configuración Actual:
- **Motor**: PHPMailer ^6.9 (vía Composer)
- **Remitente**: `fit.paisa.app@gmail.com`
- **Credenciales**: Contraseña de Aplicación de Google (16 letras)
- **Variables de Entorno**: `GMAIL_USER`, `GMAIL_APP_PASS`

### Variables de Entorno Requeridas en Vercel:

| Variable           | Entorno      | Valor                          |
|--------------------|--------------|--------------------------------|
| `GMAIL_USER`       | Ambos        | `fit.paisa.app@gmail.com`     |
| `GMAIL_APP_PASS`   | Ambos        | Contraseña de Aplicación Google |
| `JWT_SECRET`       | Ambos        | String largo aleatorio (≥32 chars) |
| `SETUP_TOKEN`      | Ambos        | Token de protección del setup  |
| `PGHOST_PROD`      | Production   | Host de Neon para producción  |
| `PGUSER_PROD`      | Production   | Usuario de Neon para producción |
| `PGPASSWORD_PROD`  | Production   | Contraseña producción         |
| `PGDATABASE_PROD`  | Production   | `neondb`                      |
| `PGHOST`           | Preview      | Host de Neon para testing     |
| `PGUSER`           | Preview      | Usuario de Neon para testing  |
| `DB_PASSWORD_NUEVA`| Preview      | Contraseña testing            |
| `PGDATABASE`       | Preview      | `fitpaisa_testing`            |

---

## 4. Catálogo Híbrido (Open Food Facts)

Desde v7.0.0 el sistema de alimentos opera en modo híbrido:

1. **Local**: Busca primero en `food_catalog` donde `is_verified = TRUE`.
2. **OFF Fallback**: Si hay < 5 resultados, consulta Open Food Facts API (timeout 2.5s).
3. **Persistencia Automática**: Al seleccionar un alimento externo, se guarda en `food_catalog` con `is_verified = FALSE`.

### Endpoint de Búsqueda:
- `GET /api/food.php?action=search&q={término}` → Búsqueda híbrida
- `POST /api/food.php?action=save_external` → Persiste alimento externo

---

## 5. Diagnóstico de Errores Comunes

### Error de "0 bytes" / Respuesta Vacía (Fatal PHP Error)
- **Causa**: Casi siempre es un error de sintaxis en `api/auth.php` o en algún controlador.
- **Acción**: `php -l api/auth.php` para detectar el error exacto.

### "Error de conexión. Intenta de nuevo."
- **Causa 1 (DSN)**: Variable `$port` o `$host` vacía.
- **Causa 2 (Credenciales)**: Variable `DB_PASSWORD_NUEVA` desactualizada en Vercel.
- **Acción**: Ejecuta `/api/diag_db.php` para ver el reporte en tiempo real.

### Correos que no llegan/van a SPAM
- **Causa**: Credenciales de Gmail incorrectas o contraseña de aplicación inválida.
- **Diagnóstico**: `GET /api/test-mail.php?to=correo@gmail.com`
- **Solución**: Generar nueva contraseña de aplicación en myaccount.google.com

### PHPMailer / Composer no disponible
- **Causa**: `vendor/autoload.php` no existe porque Composer no se ejecutó.
- **Solución**: Asegúrate de que `composer.json` está en la raíz y Vercel tiene instalación de Composer activa.

---

## 6. Procedimiento de Reparación Estándar

1. **Verificar el Kernel**: Asegúrate de que `api/_db.php` no haya sido modificado accidentalmente.
2. **Eliminar Duplicidades**: Si un archivo tiene su propio `fp_db()`, elimínalo y usa `require_once '_db.php'`.
3. **Auditoría de DSN**: El DSN debe ser exactamente `"pgsql:host={$host};port={$port};dbname={$db};sslmode=require"`.
4. **Diagnóstico**: Accede a `/api/diag_db.php` para auditoría en tiempo real.

---

## 7. URLs de Referencia

| Acción                         | URL |
|--------------------------------|-----|
| Setup DB (Producción)          | `https://fit-paisa.vercel.app/api/setup-db.php?token=fitpaisa_setup_2026` |
| Setup DB (Testing)             | `https://fit-paisa-git-testing-javigar31s-projects.vercel.app/api/setup-db.php?token=fitpaisa_setup_2026` |
| Test de Correo                 | `https://fit-paisa.vercel.app/api/test-mail.php?to=TU_EMAIL` |
| Diagnóstico DB                 | `https://fit-paisa.vercel.app/api/diag_db.php` |

---

## 8. Flujo de Sincronización de Ramas

```powershell
# Verificar sintaxis PHP
php -l api/auth.php

# Sincronizar master → testing
git checkout testing
git merge master
git push origin testing

# Sincronizar testing → master (cuando testing está validado)
git checkout master
git merge testing
git push origin master
```

---

## 9. Recreación de la BD de Testing desde Cero

Si la base de datos `fitpaisa_testing` se pierde o corrompe:

1. **En Neon Console**: Crear nueva base de datos `fitpaisa_testing`.
2. **Ejecutar esquema SQL**: Copiar el SQL del `api/setup-db.php` (sección de tablas y ENUMs).
3. **Ejecutar setup-db.php**: Visitar la URL de testing del setup para poblar los datos base.
4. **Verificar**: Comprobar que el login y la búsqueda de alimentos funcionan.

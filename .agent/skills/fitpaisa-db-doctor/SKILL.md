---
name: fitpaisa-db-doctor
description: Experto en diagnóstico y reparación de conexiones de base de datos Neon PostgreSQL para el ecosistema FitPaisa (PHP + Vercel).
---

# FitPaisa DB Doctor Skill

Esta skill proporciona el conocimiento técnico necesario para identificar, diagnosticar y resolver problemas de conexión a la base de datos en las aplicaciones de FitPaisa.

## 1. El Kernel de Conexión (`api/_db.php`)
FitPaisa utiliza un sistema de conexión centralizado. **Cualquier cambio en la lógica de BD debe realizarse PRIMERO en este archivo.**

### Reglas Críticas del Kernel:
- **Singleton PDO**: Siempre usa la función `fp_db()` para obtener la conexión.
- **Sincronización de Entornos**: El kernel cambia automáticamente la base de datos según `VERCEL_ENV`:
    - `production` -> Usa variables con sufijo `_PROD` (o las estándar de Vercel).
    - `preview` / `local` / `development` -> Forza el uso de la base de datos **`fitpaisa_testing`**.
- **SSL Obligatorio**: Todas las conexiones a Neon DEBEN incluir `sslmode=require` en el DSN.

## 2. Diagnóstico de Errores Comunes

### Error de "0 bytes" / Respuesta Vacía (Fatal PHP Error)
- **Causa**: Casi siempre es un error de sintaxis en `api/auth.php` o en algún controlador.
- **Acción**: Realiza un `grep` o revisión manual de los últimos cambios. Busca palabras clave como `match`, cierres de llaves `}` o puntos y coma `;` faltantes.

### "Error de conexión. Intenta de nuevo."
- **Causa 1 (DSN)**: Variable `$port` o `$host` vacía.
- **Causa 2 (Credenciales)**: Variable `DB_PASSWORD_NUEVA` desactualizada en Vercel.
- **Acción**: Ejecuta `https://fit-paisa.vercel.app/api/diag_db.php` para ver el reporte en tiempo real.

## 3. Procedimiento de Reparación Estándar

1.  **Verificar el Kernel**: Asegúrate de que `api/_db.php` no haya sido modificado accidentalmente de su estado estable.
2.  **Eliminar Duplicidades**: Si un archivo (`auth.php`, `profile.php`) tiene su propio `fp_db()`, ELIMÍNALO y cámbialo por `require_once '_db.php'`.
3.  **Auditoría de DSN**: El DSN debe ser exactamente:
    `"pgsql:host={$host};port={$port};dbname={$db};sslmode=require"`
4.  **Desbloqueo de Diagnóstico**: Si el diagnóstico en master pide clave (`DIAG_TOKEN`) y el usuario no la tiene, comenta temporalmente las líneas 18-28 de `api/diag_db.php` para permitir la auditoría. **RECUERDA REACTIVARLA AL TERMINAR.**

## 4. Comandos Útiles de Verificación
```powershell
# Verificar si hay errores de sintaxis en PHP
php -l api/auth.php

# Buscar credenciales configuradas
grep -r "PGHOST" api/

# Sincronizar cambios críticos entre ramas
git checkout master; git merge testing; git push origin master
```

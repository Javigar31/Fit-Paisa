# Planificación Estratégica: Fase de Pruebas FitPaisa (v3.2.x)

Este documento expone la reflexión técnica y estratégica sobre los 10 puntos fundamentales para asegurar la calidad de la plataforma FitPaisa, identificando las necesidades críticas para un despliegue exitoso.

---

## 1. Definición de Objetivos de Pruebas
**Reflexión:** Para FitPaisa, el objetivo principal no es solo la funcionalidad, sino la **estabilidad absoluta**. Venimos de una fase donde la arquitectura monolítica de controladores (v3.1.0) fue necesaria para eliminar errores 500 aleatorios.
- **Validar**: La resiliencia de la conexión a Neon DB ante latencias de Vercel.
- **Rendimiento**: Asegurar que las partículas 3D no penalizan la experiencia en dispositivos móviles de gama media.
- **Vulnerabilidades de PHP 8.3 en Vercel**: El uso de controladores monolíticos es nuestra defensa contra fallos de redemarcación; los objetivos deben centrarse en garantizar que esta "independencia" de archivos sea real y efectiva.

## [NUEVO] Foco: Validación de Arquitectura Monolítica
Dado que los controladores críticos (`auth.php`, `profile.php`, `nutrition.php`, `workouts.php`) ahora operan de forma autónoma (sin `require_once`), la fase de pruebas debe incluir:
- **Test de Aislamiento**: Deshabilitar temporalmente los archivos base (`_db.php`, `_jwt.php`) y verificar que los 4 controladores clave siguen operando al 100%.
- **Sincronización de Helpers**: Asegurar que las copias locales de las funciones de sanitización y JWT dentro de cada controlador no han divergido de la lógica central.
- **Monitorización de Cold Starts**: Evaluar si el aumento del tamaño de los archivos individuales afecta el tiempo de respuesta inicial en Vercel.

## 2. Analizar Requisitos
**Reflexión:** Debemos desglosar las funcionalidades transversales que impactan en la experiencia del usuario.
- **Catálogo y Registro**: El buscador de alimentos debe ser instantáneo y el registro de ingestas debe reflejarse en tiempo real.
- **Algoritmo Nutricional**: Validar matemáticamente que el déficit/superávit calórico se recalcula correctamente al cambiar el perfil.
- **Comunicación**: Asegurar la entrega bidireccional de mensajes entre Coach y Cliente.

## 3. Estrategia y Tipos de Pruebas
**Reflexión:** Dado nuestro entorno *serverless*, adoptaremos una metodología híbrida basada en el mapa mental de "Diseño y Realización de Pruebas":

### A. Pruebas de Caja Blanca (Estructurales)
- **Foco**: Validar la ruta lógica interna de los controladores monolíticos.
- **Acción**: Verificar que el código duplicado de JWT y `fp_db` en cada archivo (`auth.php`, `profile.php`, etc.) se ejecuta sin colisiones y maneja correctamente el flujo de excepciones `try-catch`.

### B. Pruebas de Caja Negra (Funcionales)
- **Foco**: Independencia del backend. Solo importa el "Input/Output".
- **Acción**: Validar que el buscador de alimentos devuelve el JSON esperado independientemente de si el controlador es monolítico o modular.

### C. Pruebas de Regresión
- **Foco**: Asegurar que nuevos parches no rompen la estabilidad v3.2.0.
- **Acción**: Uso estricto de la rama `testing` vinculada a la DB `fitpaisa_testing`.

## 4. Herramientas y Procedimientos de Depuración
Siguiendo la metodología formal, estableceremos tres dimensiones de depuración:
- **Dónde Depurar**: Uso de los scripts de diagnóstico (`api/diag_*.php`) como puntos de ruptura virtuales en el entorno Vercel.
- **Cómo Depurar**: Ejecución en modo **Aislamiento** (bloqueando inclusiones externas) para identificar fallos de redemarcación.
- **Qué Depurar**: Análisis de variables de entorno (Examinadores de variables) mediante la salida JSON controlada de los scripts de diagnóstico.

## 5. Pruebas Unitarias (Metodología SimpleTest/PHPUnit)
**Reflexión:** La arquitectura monolítica requiere que las funciones clave se validen de forma aislada.
- **Unit Testing**: Implementar archivos de prueba que validen únicamente la función `fp_ensure_schema` y la decodificación de JWT dentro de cada unidad monolítica para asegurar que no hay discrepancias entre controladores.

## 6. Crear Casos de Prueba
**Reflexión:** Debemos centrar los esfuerzos en los "bordes" del sistema.
- **Escenario 1**: Login tras un periodo largo de inactividad (Cold Start de Neon).
- **Escenario 2**: Registro de alimento pesado en gramos vs raciones.
- **Escenario 3**: Transiciones de página rápidas para verificar la limpieza de la escena 3D.

## 7. Ejecución de Pruebas
**Reflexión:** Se combinará la ejecución manual (navegación por UI) con scripts de diagnóstico automatizados.
- **Uso de scripts `diag_*.php`**: Ejecutar comprobaciones de salud del sistema antes de cada sesión manual.
- **Cross-Browser**: Probar específicamente en Safari (iOS/macOS) y Chrome (Android/Desktop).

## 8. Gestión de Errores
**Reflexión:** Los errores en FitPaisa se categorizan por impacto en el "Feeling Premium".
- **Prioridad Crítica**: Errores 500 o fallos en el motor 3D de fondo.
- **Prioridad Media**: Desajustes visuales menores o retrasos en carga de avatares.
- **Seguimiento**: Documentar cada bug en el `informe.txt` para mantener la trazabilidad.

## 9. Validación Final
**Reflexión:** La validación final la realizas tú (Javier García) como usuario principal y propietario del producto.
- **Criterio de Éxito**: La plataforma debe sentirse fluida (60fps), segura y los datos nutricionales deben ser precisos al gramo.

## 10. Normas de Calidad y Documentación
**Reflexión:** El éxito de FitPaisa reside en la trazabilidad.
- **Estándares**: Todo cambio debe seguir el estándar de controladores monolíticos para evitar regresiones 500.
- **Documentación de la Prueba**: Cada ciclo de pruebas finalizado debe quedar registrado formalmente en el archivo `informe.txt`, indicando el hito de versión alcanzado.

---

# ¿Qué necesito para realizar una fase de pruebas exitosa?

Para que este plan sea efectivo, identifico las siguientes necesidades críticas:
1. **Acceso a Logs de Producción**: Para identificar errores que no ocurren en testing pero sí en master.
2. **Dispositivos de Prueba Variados**: Validar el `scroll-snap` y el motor 3D en iPhone y Android.
3. **Set de Datos "Golden"**: Un conjunto de 10 alimentos conocidos para validar la exactitud del algoritmo sin margen de error.
4. **Reserva de Tiempo**: Un espacio de 24 horas entre el despliegue en `testing` y el paso a `master` para detectar latencias de DB.

---
**Documento generado para la versión v3.2.0 de FitPaisa.**

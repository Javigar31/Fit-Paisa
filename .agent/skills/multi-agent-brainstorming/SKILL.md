---
name: multi-agent-brainstorming
description: "Simula un proceso de revisión por pares estructurado utilizando múltiples agentes especializados para validar diseños, sacar a la luz suposiciones ocultas e identificar modos de fallo antes de la implementación."
risk: unknown
source: community
date_added: "2026-04-20"
---

# Brainstorming Multi-Agente (Revisión de Diseño Estructurada)

## Propósito

Transformar un diseño de un solo agente en un **diseño robusto y validado por revisión**
simulando un proceso formal de revisión por pares utilizando múltiples agentes restringidos.

Esta skill existe para:
- Sacar a la luz suposiciones ocultas.
- Identificar modos de fallo de manera temprana.
- Validar restricciones no funcionales.
- Someter los diseños a pruebas de estrés antes de la implementación.
- Prevenir el caos de la "lluvia de ideas" descontrolada.

Esto **NO es una lluvia de ideas en paralelo**.
Es una **revisión de diseño secuencial con roles enforced (obligatorios)**.

---

## Modelo Operativo

- Un agente diseña.
- Otros agentes revisan.
- Ningún agente puede exceder su mandato.
- La creatividad está centralizada; la crítica está distribuida.
- Las decisiones son explícitas y se registran en un log.

El proceso está **regulado (gated)** y **termina por diseño**.

---

## Roles de los Agentes (No Negociables)

Cada agente opera bajo un **límite estricto de alcance**.

### 1️⃣ Diseñador Principal (Agente Líder)

**Rol:**
- Es el dueño del diseño.
- Utiliza la skill estándar de `brainstorming`.
- Mantiene el Registro de Decisiones (Decision Log).

**Puede:**
- Hacer preguntas de aclaración.
- Proponer diseños y alternativas.
- Revisar diseños basados en el feedback.

**NO Puede:**
- Auto-aprobar el diseño final.
- Ignorar las objeciones de los revisores.
- Inventar requisitos después del cierre de diseño.

---

### 2️⃣ Agente Escéptico / Desafiante (Skeptic)

**Rol:**
- Asumir que el diseño fallará.
- Identificar debilidades y riesgos.

**Puede:**
- Cuestionar suposiciones.
- Identificar casos de borde (edge cases).
- Resaltar ambigüedades o exceso de confianza.
- Señalar violaciones de YAGNI (You Ain't Gonna Need It).

**NO Puede:**
- Proponer nuevas funciones.
- Rediseñar el sistema.
- Ofrecer arquitecturas alternativas.

Guía de prompt:
> “Asume que este diseño falla en producción. ¿Por qué?”

---

### 3️⃣ Agente Guardián de Restricciones (Constraint Guardian)

**Rol:**
- Hacer cumplir las restricciones no funcionales y del mundo real.

Áreas de enfoque:
- Rendimiento (performance).
- Escalabilidad.
- Fiabilidad.
- Seguridad y privacidad.
- Mantenibilidad.
- Coste operativo.

**Puede:**
- Rechazar diseños que violen las restricciones.
- Solicitar aclaración sobre los límites.

**NO Puede:**
- Debatir los objetivos del producto.
- Sugerir cambios en las funcionalidades.
- Optimizar más allá de los requisitos establecidos.

---

### 4️⃣ Agente Defensor del Usuario (User Advocate)

**Rol:**
- Representar al usuario final.

Áreas de enfoque:
- Carga cognitiva.
- Usabilidad.
- Claridad de los flujos.
- Manejo de errores desde la perspectiva del usuario.
- Desajuste entre la intención y la experiencia.

**Puede:**
- Identificar aspectos confusos o engañosos.
- Señalar valores por defecto deficientes o comportamientos poco claros.

**NO Puede:**
- Rediseñar la arquitectura.
- Añadir funcionalidades.
- Invalidar los objetivos de usuario establecidos.

---

### 5️⃣ Agente Integrador / Árbitro (Arbiter)

**Rol:**
- Resolver conflictos.
- Finalizar las decisiones.
- Hacer cumplir los criterios de salida.

**Puede:**
- Aceptar o rechazar objeciones.
- Requerir revisiones de diseño.
- Declarar el diseño como completado.

**NO Puede:**
- Inventar nuevas ideas.
- Añadir requisitos.
- Reabrir decisiones cerradas sin una causa justificada.

---

## El Proceso

### Fase 1 — Diseño de Agente Único

1. El Diseñador Principal ejecuta la **skill estándar de `brainstorming`**.
2. Se completa y confirma el "Cierre de Comprensión" (Understanding Lock).
3. Se produce el diseño inicial.
4. Se inicia el Registro de Decisiones (Decision Log).

Ningún otro agente participa todavía.

---

### Fase 2 — Bucle de Revisión Estructurado

Los agentes son invocados **uno a uno**, en el siguiente orden:

1. Escéptico / Desafiante.
2. Guardián de Restricciones.
3. Defensor del Usuario.

Para cada revisor:
- El feedback debe ser explícito y limitado a su alcance.
- Las objeciones deben referenciar suposiciones o decisiones.
- No se pueden introducir nuevas funciones.

El Diseñador Principal debe:
- Responder a cada objeción.
- Revisar el diseño si es necesario.
- Actualizar el Registro de Decisiones.

---

### Fase 3 — Integración y Arbitraje

El Integrador / Árbitro revisa:
- El diseño final.
- El Registro de Decisiones.
- Las objeciones no resueltas.

El Árbitro debe decidir explícitamente:
- Qué objeciones son aceptadas.
- Cuáles son rechazadas (con su respectiva justificación).

---

## Registro de Decisiones (Artefacto Obligatorio)

El Registro de Decisiones (Decision Log) debe registrar:

- Decisión tomada.
- Alternativas consideradas.
- Objeciones planteadas.
- Resolución y justificación (rationale).

Ningún diseño se considera válido sin un registro completado.

---

## Criterios de Salida (Parada Obligatoria)

Puedes salir del brainstorming multi-agente **solo cuando todo lo siguiente sea cierto**:

- Se completó el Cierre de Comprensión (Understanding Lock).
- Todos los agentes revisores han sido invocados.
- Todas las objeciones están resueltas o explícitamente rechazadas.
- El Registro de Decisiones está completo.
- El Árbitro ha declarado el diseño como aceptable.

Si algún criterio no se cumple:
- Continúa la revisión.
- NO procedas a la implementación.

Si esta skill fue invocada por una capa de orquestación, DEBES informar la disposición final explícitamente como: APROBADO, REVISAR o RECHAZAR, con una breve justificación.

---

## Modos de Fallo que esta Skill Previene

- Caos de la lluvia de ideas descontrolada.
- Consenso alucinado.
- Diseños sobre-confiados de un solo agente.
- Suposiciones ocultas.
- Implementación prematura.
- Debate interminable.

---

## Principios Clave

- Un diseñador, muchos revisores.
- La creatividad está centralizada.
- La crítica está restringida.
- Las decisiones son explícitas.
- El proceso debe terminar.

---

## Recordatorio Final

Esta skill existe para responder a una pregunta con confianza:

> “Si este diseño falla, ¿hicimos todo lo razonable para detectarlo a tiempo?”

Si la respuesta no está clara, **no salgas de esta skill**.

## Cuándo usar
Esta skill es aplicable para ejecutar el flujo de trabajo o las acciones descritas en la descripción general.

## Limitaciones
- Usa esta skill solo cuando la tarea coincida claramente con el alcance descrito anteriormente.
- No trates el resultado como un sustituto de la validación, pruebas o revisión experta específica del entorno.
- Detente y pide aclaraciones si faltan las entradas requeridas, los permisos, los límites de seguridad o los criterios de éxito.

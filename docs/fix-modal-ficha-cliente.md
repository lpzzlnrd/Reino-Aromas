# Modal "Ver ficha" de Clientes: proporción rota y superposición

**Rama:** `fix/modal-ficha-cliente`
**Archivo afectado:** `resources/views/src/components/layouts/clients/clients.home.vue`
**Estado:** diagnóstico verificado en código. Sin cambios aplicados todavía.

---

## Resumen en una línea

La ficha de cliente es **el único modal del CRM que no usa `<Teleport to="body">`**, así que
queda atrapada dentro del árbol del layout y hereda su ancho, su recorte y su orden de
apilamiento.

---

## Evidencia

Los cuatro modales del sistema, comparados:

| Modal | Ubicación | `<Teleport>` |
|---|---|---|
| Alta / edición de usuario | `users.home.vue:264` | ✅ sí |
| Editor de plantillas | `templates.home.vue:500` | ✅ sí |
| Confirmar borrado | `templates.home.vue:818` | ✅ sí |
| **Ficha de cliente** | `clients.home.vue:430` | ❌ **no** |

El patrón correcto ya existe en el repo (`users.home.vue:264-266`):

```
<Teleport to="body">
    <Transition name="fade">
        <div v-if="showModal" class="fixed inset-0 z-50 ...">
```

La ficha arranca directamente en el `<Transition>`, sin el `<Teleport>` que lo envuelve.

---

## Los tres síntomas, y de dónde sale cada uno

### 1. Proporción: el panel no llega al borde de la pantalla

El contenedor raíz del componente (`clients.home.vue:257`) es:

```
<div class="px-4 lg:px-8 py-6 w-full animate-fade-in overflow-x-hidden">
```

Ese `<div>` vive dentro del `<main>` del layout, que a su vez está al lado de un
`<aside class="... w-60 ... z-40">` (`dashboard.responsiveLayout.vue:66`).

`position: fixed` se resuelve contra el viewport **solo si ningún ancestro crea un
contexto de contención**. Aquí sí lo hay, así que el `justify-end` del overlay empuja el
panel al borde derecho del *contenedor*, no de la ventana. Resultado: el panel aparece
desplazado y más estrecho de lo que debería.

### 2. Superposición: el header móvil se dibuja encima del backdrop

Capas declaradas en el layout:

- `dashboard.responsiveLayout.vue:44` → header móvil, `sticky top-0 z-50`
- `dashboard.responsiveLayout.vue:66` → sidebar, `z-40`
- `clients.home.vue:430` → modal de ficha, `z-50`

El modal empata en `z-50` con el header móvil, pero al estar en un subárbol distinto el
desempate lo decide el **orden de documento**: gana el que se pinta después. Por eso en
móvil el header queda visible por encima del overlay, que es exactamente la superposición
reportada.

Subir el `z-index` **no** es la solución: mientras el modal siga anidado, seguirá compitiendo
dentro de un contexto de apilamiento que no controla.

### 3. Recorte: `overflow-x-hidden` corta el panel

Misma línea 257. Un ancestro con `overflow` distinto de `visible` recorta a sus
descendientes posicionados y refuerza el problema del punto 1.

---

## Solución propuesta

### Cambio principal — envolver en `<Teleport to="body">`

Replicar el patrón que ya usan los otros tres modales. Esto:

- saca el overlay del árbol del layout y lo cuelga del `<body>`
- devuelve al `fixed inset-0` el viewport completo (proporción correcta)
- elimina el recorte de `overflow-x-hidden`
- resuelve el apilamiento sin tocar ningún `z-index`

En `clients.home.vue`:

- **abrir** `<Teleport to="body">` justo antes del `<Transition>` de la línea 424
- **cerrar** `</Teleport>` justo después del `</Transition>` de la línea 589

### Ajuste secundario — altura en móvil

El panel usa `h-full` (`clients.home.vue:436`). Una vez colgado del `<body>`, conviene
`h-dvh` para que la barra de direcciones del navegador móvil no recorte el footer con el
botón "Abrir conversación", que es `sticky bottom-0`.

### Lo que NO hay que tocar

- **`useModal`** (`composables/useModal.ts`) sigue funcionando igual. El `ref="panelFicha"`
  (línea 432) se mantiene: `<Teleport>` no rompe los refs de plantilla, el foco atrapado ni
  el scroll-lock.
- **`z-index`**: con el teleport aplicado, el `z-50` actual es suficiente.
- **El contenido de la ficha** (cabecera, datos, edición, historial, footer) no cambia.

---

## Cómo verificar

1. Escritorio ancho: el panel debe pegarse al borde **derecho de la pantalla**, no al del
   área de contenido, y el backdrop debe cubrir también el sidebar.
2. Móvil (o viewport < 768px): abrir la ficha y confirmar que el header superior queda
   **detrás** del backdrop difuminado.
3. Con la ficha abierta, comprobar que el fondo no scrollea y que `Escape` la cierra
   (regresiones posibles de `useModal`).
4. Repasar que el botón "Abrir conversación" del footer queda visible sin scroll en móvil.

---

## Nota de alcance

Este documento cubre **solo** el modal de ficha de clientes. Los bugs de backend detectados
en la misma sesión — `profile_picture_url` con `VARCHAR(255)` desbordado por las URLs
firmadas de la CDN de Meta, y Reverb sin levantar en el VPS — son independientes y no se
tratan aquí.

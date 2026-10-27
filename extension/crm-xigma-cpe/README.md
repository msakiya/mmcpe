# CRM Xigma – Extensión de Chrome para WhatsApp Web

Versión 1.4.1 (Comunidad CPE) · Xigma Group

Convierte WhatsApp Web en un CRM básico. Los datos se guardan en tu navegador (`chrome.storage.local`) y, si te conectas, se sincronizan con tu servidor **https://mm.comunidadcpe.com** para tenerlos iguales en todas tus computadoras.

## Instalación
1. Descomprime `crm-xigma-v1.4.0.zip`: obtendrás la carpeta `crm-xigma-cpe`.
2. Abre `chrome://extensions` en Chrome.
3. Activa **Modo de desarrollador** (arriba a la derecha).
4. Clic en **Cargar descomprimida** y elige la carpeta `crm-xigma-cpe`.
5. Abre (o recarga) https://web.whatsapp.com.

**Si ya tenías la versión anterior (CRM Whats):** para conservar los datos locales, copia los archivos de `crm-xigma` dentro de tu carpeta actual (`crm-whats`), reemplazándolos, y pulsa **Recargar** en `chrome://extensions`. Si prefieres cargar la carpeta nueva, conéctate de nuevo al servidor en Ajustes → Sincronización y se descargarán tus datos (luego elimina la extensión antigua).

Panel web: https://mm.comunidadcpe.com/mm (mismo usuario y contraseña).

## Uso
- **Botón verde Xigma "CRM"** (abajo a la derecha) o el ícono de la extensión: muestra/oculta el panel.
- **Contacto**: abre un chat → "Agregar al CRM". Edita etapa, valor (S/), etiquetas, notas y recordatorio.
- **Conversación**: al abrir un chat se capturan los mensajes visibles (entrantes y salientes) y se muestran en la ficha. Con el servidor conectado, se guardan en mm.comunidadcpe.com. No se descargan imágenes/audios: solo una etiqueta como `[Imagen]` y el pie de foto si hay.
- Si estás conectado al servidor, al abrir un chat se crea solo un lead en etapa **Nuevo** para no perder la conversación (verás "Guardando conversación…").
- **Embudo / Lista**: tablero Kanban con arrastrar y soltar, y lista con búsqueda y filtros por etiqueta/etapa. Doble clic abre el chat (si hay teléfono).
- **Respuestas**: plantillas; clic para insertarlas en la caja de mensaje (nunca se envían solas). `{nombre}` se reemplaza por el primer nombre.
- **Ajustes**: etiquetas con color, etapas editables, exportar CSV, backup JSON e importar.
- **Recordatorios**: notificación de Chrome en la fecha/hora elegida (Chrome debe estar abierto).

## Autocompletado de leads (v1.4.0)
Al crear un lead (botón **+ Agregar al CRM** o automáticamente con el servidor conectado) se llenan solos:
- **Teléfono**: del título del chat si es un número; si el contacto está guardado con nombre, de los identificadores internos de los mensajes (`…@c.us`); si no, del panel **Info. del contacto** si está abierto. Números de 9 dígitos que empiezan con 9 se guardan con 51 (Perú). En leads ya existentes sin teléfono se rellena al abrir su chat (misma ficha, sin duplicar).
- **Nombre** (del chat) y **etapa** = primera etapa (Nuevo).
- **Recordatorio**: "Primer seguimiento" al día siguiente a las 10:00 (hora Lima). Configurable en Ajustes.
- **Nota inicial**: "Lead creado desde WhatsApp el <fecha hora>" + primer y último mensaje del cliente.
- **Valor S/**: el monto más alto que aparezca en el chat (S/ 150, S/150, 150 soles, PEN 150) si el valor está en 0.
- **Etiquetas por palabras clave** de los mensajes del cliente (por defecto: mayorista|por mayor|al por mayor → Mayorista; yape|plin|transferencia|pago pendiente|te pago → Pendiente pago). Editables en Ajustes.
Nunca se reemplaza lo que escribes tú: los campos editados a mano quedan marcados y una etiqueta que quitas no se vuelve a poner sola.

## Limitaciones
- Si WhatsApp identifica el chat con un id privado (`@lid`) y el número no aparece en pantalla, el teléfono no se puede detectar: abre **Info. del contacto** una vez o escríbelo a mano.
- En grupos no se toma ningún teléfono.
- WhatsApp cambia su HTML con frecuencia; si deja de detectar el contacto o los mensajes, hay que actualizar los selectores en `content.js`.
- Solo se capturan los mensajes **visibles** en la ventana del chat (no se hace scroll automático hacia el historial antiguo).
- Máximo **500 mensajes** por contacto (se conservan los más recientes).
- No se descargan ni suben archivos multimedia (solo etiquetas de tipo).
- Contactos guardados con nombre se identifican por nombre (si cambias el nombre en tu agenda, se crea otra ficha). Puedes escribir el teléfono manualmente.
- Si nunca te conectas al servidor, los datos quedan solo en ese navegador (usa el backup JSON).
- La sincronización de fichas es "gana el último cambio" para todo el CRM; las conversaciones se fusionan por id de mensaje (idempotente).

## Sincronización entre computadoras
1. Abre el panel → pestaña **Ajustes** → sección **Sincronización (servidor)**.
2. URL: `https://mm.comunidadcpe.com` (ya viene puesta). Escribe tu usuario y contraseña y pulsa **Conectar**.
3. Listo: cada cambio de ficha se sube al servidor unos 2 segundos después; las conversaciones se suben al capturar mensajes (~1,5 s de espera).
4. Al abrir un chat se descarga el historial guardado en el servidor y se mezcla con lo local.
5. En una computadora nueva: instala la extensión, conéctate con el mismo usuario y se descargará todo.

La contraseña no se guarda en la extensión, solo un token de sesión. Si se desconecta, vuelve a pulsar **Conectar**.
La extensión funciona aunque no haya internet; sincroniza cuando vuelve la conexión.

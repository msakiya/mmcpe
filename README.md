# MM CPE – CRM CPE (Comunidad CPE)

Panel CRM y API de sincronización de **https://mm.comunidadcpe.com**, más la extensión de Chrome que convierte WhatsApp Web en un CRM y sincroniza con este servidor.

## Estructura
- `site/` – docroot (`~/mm.comunidadcpe.com` en DreamHost, usuario `mmcpe`)
  - `index.html`, `404.html` – portada
  - `mm/` – panel web (login, dashboard, leads, embudo, ajustes). El panel vive en `/mm`, nunca en `/admin`.
  - `api/` – API JSON que usa la extensión (`auth/login`, `auth/logout`, `sync`, `conversations`, `health`)
  - `download-extension.php` – descarga `crm-cpe-v1.5.1.zip` (el zip no se versiona; se genera desde `extension/`)
- `extension/crm-cpe/` – extensión de Chrome **crm-cpe** (Manifest V3, v1.5.1) apuntando a `https://mm.comunidadcpe.com`
- `config/lib.example.php` – plantilla de la capa de datos (SQLite)

## Datos (fuera del docroot)
Los datos viven en `/home/mmcpe/crm-data/` (permisos 700) y **no** están en este repo:
- `lib.php` (copiar desde `config/lib.example.php`)
- `crm.sqlite` (se crea sola al primer acceso)
- `sessions/`

`site/mm/inc/core.php` y `site/api/index.php` cargan `/home/mmcpe/crm-data/lib.php` (en `core.php` se puede cambiar con la variable de entorno `CRM_LIB`).

## Empaquetar la extensión
```
cd extension && zip -r ../crm-cpe-v1.5.1.zip crm-cpe
```
Subir el zip al docroot junto a `download-extension.php`.

Requisitos: PHP 8.2 con PDO SQLite.

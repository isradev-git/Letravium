# Letravium

Aplicación de escritura en español para novelistas: manuscritos, planificación, universo, revisión, historial, importación y exportación a PDF, DOCX, EPUB y TXT.

Laravel 13 + MySQL, interfaz web con Blade y Vite. Todo el procesamiento de texto es local; la aplicación no incorpora IA, servicios de pago ni envío de manuscritos a terceros.

## Requisitos

- PHP 8.5 con las extensiones habituales de Laravel (`ctype`, `fileinfo`, `gd`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip`).
- Composer 2.
- Node.js 20 o posterior.
- MySQL 8.4.

## Instalación local

```sh
cp .env.example .env
composer install
php artisan key:generate
npm ci
npm run build
php artisan migrate
php artisan serve
```

La aplicación queda en `http://127.0.0.1:8000`. El acceso privado requiere correo verificado y TOTP.

## Pruebas

```sh
php artisan test
npm run test:editor
npm run test:pdf
```

Las pruebas de funcionalidad usan la configuración de `phpunit.xml`; para transacciones y concurrencia debe usarse MySQL 8.4 con una base de datos de prueba separada.

## Datos privados

Los manuscritos, imágenes, fuentes, copias y documentos se guardan en `storage/app/private`, fuera del repositorio. `.env`, la base de datos SQLite local, `vendor`, `node_modules` y `public/build` no se versionan.

## Licencias de recursos

Las fuentes incluidas conservan sus avisos en `resources/fonts/`. Los diccionarios y tesauros locales conservan sus licencias en `resources/js/editor/data/` y en las dependencias declaradas por `composer.lock` y `package-lock.json`.

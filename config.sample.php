<?php
declare(strict_types=1);

return [
    // Set the actual Joomla table prefix for this site, e.g. "jml_" (not "#__").
    'table_prefix' => 'jml_',

    // Use a dedicated MySQL/MariaDB account with only the required read/delete permissions.
    'dsn' => 'mysql:host=localhost;dbname=joomla;charset=utf8mb4',
    'username' => 'cleanup_user',
    'password' => 'replace-this',

    // Every file_url/file_url_thumb must resolve beneath one of these absolute directories.
    // Add the Joomla root only if VM media URLs include paths such as images/virtuemart/...
    'media_roots' => [
        '/home/account/public_html/images/virtuemart',
    ],

    // Keep this private directory outside public_html; it must exist and be writable by PHP.
    'audit_db' => '/home/account/private/vm4-cleanup/site-a.sqlite',

    // Keep this config outside public_html. A password hash from password_hash() is also accepted.
    'web_password' => 'replace-with-a-unique-random-password-at-least-16-chars',

    // Enable only for local development over localhost HTTP. Remote HTTP remains blocked. True only allows HTTP access from localhost.
    'allow_http_localhost' => true,

    // Site configs and the referenced files must remain outside public_html.
    // The first entry may point back to this config after you copy it to web-config.php.
    'web_sites' => [
        'Shop one' => '/home/account/private/vm4-cleanup/web-config.php',
        'Shop two' => '/home/account/private/vm4-cleanup/shop-two.php',
    ],
];

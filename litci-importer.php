<?php
/**
 * Plugin Name: LI-TCI Importer
 * Plugin URI:  https://litci.org
 * Description: Plugin para importar conteúdos externos para o site da LIT-QI.
 * Version:     0.1.0
 * Author:      Tiê Desenvolvimento
 * Author URI:  https://litci.org
 * License:     GPL2
 * Text Domain: litci-importer
 */

if (! defined('ABSPATH')) {
    exit;
}

// Caminho para includes
require_once __DIR__ . '/includes/class-media-handler.php';
require_once __DIR__ . '/includes/class-translation.php';
require_once __DIR__ . '/includes/class-import-service.php';
require_once __DIR__ . '/includes/class-admin-page.php';

// Inicializa a classe de admin que registra hooks e endpoints
add_action('plugins_loaded', function () {
    new LITCI_Importer();
});
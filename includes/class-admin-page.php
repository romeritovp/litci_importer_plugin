<?php
if (!defined('ABSPATH')) exit;

class LITCI_Importer
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_litci_fetch_posts', [$this, 'ajax_fetch_posts']);
        add_action('wp_ajax_litci_import_post', [$this, 'ajax_import_post']);
    }

    public function register_admin_page()
    {
        add_menu_page(
            'LITCI Importer',
            'LITCI Importer',
            'manage_options',
            'litci-importer',
            [$this, 'render_admin_page'],
            'dashicons-download',
            90
        );
    }

    public function enqueue_assets($hook)
    {
        // Só carrega scripts na página do plugin
        if ($hook !== 'toplevel_page_litci-importer') {
            return;
        }

        wp_enqueue_style('litci-importer-style', plugin_dir_url(__DIR__) . 'assets/style.css');
        wp_enqueue_script('litci-importer-script', plugin_dir_url(__DIR__) . 'assets/script.js', ['jquery'], '1.0', true);

        wp_localize_script('litci-importer-script', 'litciImporter', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('litci_importer_nonce')
        ]);
    }

    public function render_admin_page()
    {
?>
        <div class="wrap">
            <h1>🛰️ LITCI Importer</h1>
            <p>Selecione o site e veja os últimos 30 textos.</p>

            <label for="litci-endpoint">Site:</label>
            <select id="litci-endpoint">
                <option value="https://litci.org/es/wp-json/wp/v2/posts">LIT-CI Español</option>
                <option value="https://litci.org/pt/wp-json/wp/v2/posts">LIT-QI Português</option>
                <option value="https://litci.org/en/wp-json/wp/v2/posts">IWL-FI English</option>
            </select>

            <button id="litci-load" class="button button-primary">Carregar</button>

            <div id="litci-results" style="margin-top:20px;">
                <p>Selecione um endpoint e clique em <strong>Carregar</strong>.</p>
            </div>
        </div>
<?php
    }

    public function ajax_fetch_posts()
    {
        check_ajax_referer('litci_importer_nonce', 'nonce');

        $endpoint = sanitize_text_field($_POST['endpoint']);
        $url = $endpoint . '?per_page=30&_embed';

        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Erro na requisição: ' . $response->get_error_message()]);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            wp_send_json_error(['message' => 'Resposta inválida da API.']);
        }

        ob_start();
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr><th>Título</th><th>ID</th><th>Data</th><th>Ação</th></tr></thead><tbody>';

        foreach ($data as $item) {
            $title = isset($item['title']['rendered']) ? wp_strip_all_tags($item['title']['rendered']) : ($item['name'] ?? '—');
            $id = $item['id'] ?? '—';
            $date = isset($item['date']) ? date('d/m/Y', strtotime($item['date'])) : '—';
            $link = isset($item['link']) ? esc_url($item['link']) : '#';

            echo "<tr>
                <td><a href='{$link}' target='_blank'>" . esc_html($title) . "</a></td>
                <td>{$id}</td>
                <td>{$date}</td>
                <td>
                    <button class='button button-secondary litci-import-btn' 
                            data-id='{$id}' 
                            data-endpoint='" . esc_attr($endpoint) . "'>
                        Importar
                    </button>
                </td>
            </tr>";
        }

        echo '</tbody></table>';
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    public function ajax_import_post()
    {
        check_ajax_referer('litci_importer_nonce', 'nonce');

        $service = new LITCI_Import_Service();
        $result = $service->handle_import_request();

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }
}

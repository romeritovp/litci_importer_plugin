<?php
if (!defined('ABSPATH')) exit;

class LITCI_Media_Handler
{
    /*==================================================
        Faz o download da imagem destacada
        e cria o attachment no WP local
    ==================================================*/
    public function import_featured_image($image_url, $post_id)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Baixa a imagem para a pasta temporária
        $tmp = download_url($image_url);

        if (is_wp_error($tmp)) {
            return false;
        }

        // Cria um array com informações do arquivo
        $file_array = [
            'name'     => basename($image_url),
            'tmp_name' => $tmp
        ];

        // Faz o upload para a mídia e anexa ao post
        $attachment_id = media_handle_sideload($file_array, $post_id);

        // Se der erro, apaga o arquivo temporário
        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            return false;
        }

        return $attachment_id;
    }

    /*==================================================
    Processa um array de blocos, faz o download de imagens
    (<img> e <figure>) e substitui as URLs remotas por locais.
    ==================================================*/
    public function import_inline_images_array(array $exploded_content, $post_id)
    {
        // Includes necessários para media_handle_sideload, etc.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $modified_blocks = [];
        $modified = false;

        // Regex para encontrar a tag <img> e capturar a URL SRC
        // Captura: [1] a tag <img> completa (se houver), [3] a URL do SRC
        $img_tag_and_url_pattern = '/(<img[^>]+src=["\']([^"\']+)["\'][^>]*>)/is';

        // Regex para identificar se o bloco é uma FIGURA com <img> dentro
        $figure_with_img_pattern = '/^<figure.*?>.*<img.*?>.*<\/figure>$/is';


        foreach ($exploded_content as $block) {
            $working_block = $block;

            // 1. Verifica se é um bloco de IMAGEM (IMG solta ou FIGURE com IMG dentro)
            $is_img_block = preg_match($img_tag_and_url_pattern, $block, $img_matches);
            $is_figure_block = preg_match($figure_with_img_pattern, $block);

            if (!$is_img_block && !$is_figure_block) {
                // Não é uma imagem, mas pode ser um iframe, video, ou outro embed. 
                // Neste ponto, apenas mantemos o bloco original e passamos para a próxima iteração.
                $modified_blocks[] = $block;
                continue;
            }

            // Se for um bloco de imagem, o preg_match acima já capturou a tag img e a URL
            $full_img_tag = $img_matches[1] ?? ''; // A tag <img> completa
            $img_url = $img_matches[2] ?? '';       // A URL remota do SRC

            if (empty($img_url)) {
                // Imagem mal formatada, mantém e continua.
                $modified_blocks[] = $block;
                continue;
            }

            // 2. Verifica se a imagem já é local (se o host for igual ao home_url)
            $url_parts = parse_url($img_url);
            $home_parts = parse_url(home_url());

            if (isset($url_parts['host']) && isset($home_parts['host']) && $url_parts['host'] === $home_parts['host']) {
                $modified_blocks[] = $block; // Já local, mantém o bloco
                continue;
            }

            // 3. Download e Sideload (importação local)
            $tmp = download_url($img_url);
            if (is_wp_error($tmp)) {
                // error_log('LITCI Importer: ERRO NO DOWNLOAD DA IMAGEM: ' . $img_url);
                $modified_blocks[] = $block; // Erro, mantém a URL remota (pode quebrar)
                continue;
            }

            $file_array = [
                'name'     => basename(parse_url($img_url, PHP_URL_PATH)),
                'tmp_name' => $tmp,
            ];

            $attachment_id = media_handle_sideload($file_array, $post_id);
            @unlink($file_array['tmp_name']);

            if (is_wp_error($attachment_id)) {
                // error_log('LITCI Importer: ERRO NO SIDELOAD DA IMAGEM: ' . $img_url . ' - ' . $attachment_id->get_error_message());
                $modified_blocks[] = $block; // Erro, mantém o bloco original
                continue;
            }

            // 4. Criação da nova tag <img> com URLs locais e srcset
            $local_url = wp_get_attachment_url($attachment_id);
            $new_srcset = wp_get_attachment_image_srcset($attachment_id, 'full');
            $new_sizes = wp_get_attachment_image_sizes($attachment_id, 'full');

            // Extrai atributos existentes (exceto src, srcset, sizes)
            $attributes = [];
            preg_match_all('/(\w+)=["\']([^"\']+)["\']/i', $full_img_tag, $attr_matches);

            foreach ($attr_matches[1] as $attr_index => $attr_name) {
                $attr_name_lower = strtolower($attr_name);
                if (!in_array($attr_name_lower, ['src', 'srcset', 'sizes'])) {
                    $attributes[$attr_name_lower] = $attr_matches[2][$attr_index];
                }
            }

            // Monta a nova tag <img>
            $new_img_tag = '<img';
            $new_img_tag .= ' src="' . esc_attr($local_url) . '"';
            if ($new_srcset) {
                $new_img_tag .= ' srcset="' . esc_attr($new_srcset) . '"';
            }
            if ($new_sizes) {
                $new_img_tag .= ' sizes="' . esc_attr($new_sizes) . '"';
            }
            foreach ($attributes as $attr_name => $attr_value) {
                $new_img_tag .= ' ' . $attr_name . '="' . esc_attr($attr_value) . '"';
            }
            $new_img_tag .= '>';

            // 5. Substitui a tag <img> antiga pela nova DENTRO DO BLOCO
            // Isso cobre tanto o <img> solto quanto o <img> dentro da <figure>
            $working_block = str_replace($full_img_tag, $new_img_tag, $working_block);

            $modified_blocks[] = $working_block;
            $modified = true;
            // error_log('LITCI Importer: Imagem substituída com sucesso. ID: ' . $attachment_id);
        }

        // error_log('LITCI Importer: Processamento de imagens em array concluído.');

        return $modified_blocks;
    }
}

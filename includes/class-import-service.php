<?php
if (!defined('ABSPATH')) exit;

class LITCI_Import_Service
{
    private $mediaHandler;
    private $translator;

    public function __construct()
    {
        $this->mediaHandler = new LITCI_Media_Handler();
        $this->translator = new LITCI_Translation_Service();
    }

    public function handle_import_request()
    {
        check_ajax_referer('litci_importer_nonce', 'nonce');

        $id = intval($_POST['id'] ?? 0);
        $endpoint = sanitize_text_field($_POST['endpoint'] ?? 'posts');
        if (!$id) {
            wp_send_json_error(['message' => 'ID inválido.']);
        }

        // Busca o post remoto completo (com embed para imagem destacada)
        $url = $endpoint . '/' . $id . '?_embed';
        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Erro ao buscar post remoto.']);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            wp_send_json_error(['message' => 'Resposta inválida da API.']);
        }

        // ======================================================
        //  1 . Cria o post localmente
        // ======================================================

        $title   = wp_strip_all_tags($data['title']['rendered'] ?? 'Sem título');
        $content = $data['content']['rendered'] ?? '';
        $date    = $data['date'] ?? current_time('mysql');
        $political_author = $data['political_author'] ?? null;
        $tagline = $data['tagline'] ?? '';
        $menu_order = $data['menu_order'] ?? 0;

        if (isset($data['_embedded']['wp:term'][1])) {
            $tags_data = $data['_embedded']['wp:term'][1];
            $tag_names = array_map(function ($term) {
                return $term['name'];
            }, $tags_data);
        }

        // Cria o post local inicialmente com o conteúdo remoto
        $post_id = wp_insert_post([
            'post_title'   => 'Translating: ' . $title,
            'post_content' => $content,
            'post_date'    => $date,
            'post_status'  => 'draft',
            'post_type'    => 'post',
            'menu_order'   => $menu_order,
        ]);

        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => 'Erro ao criar post local.']);
        }

        // ======================================================
        //  2 . Importa imagem destacada
        // ======================================================

        $image_url = $data['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '';
        if ($image_url) {
            $image_id = $this->mediaHandler->import_featured_image($image_url, $post_id);
            if ($image_id) {
                set_post_thumbnail($post_id, $image_id);
            }
        }

        // ======================================================
        //  3 . Processa o conteúdo
        // ======================================================

        $final_content = $this->processContent($content, $post_id);

        // Traduz o título
        $translated_title = $this->translator->translate_content_with_openai(wp_strip_all_tags($title));
        $final_title = wp_strip_all_tags($translated_title);

        // Traduz tagline
        if($tagline){
            $translatedTagline = $this->translator->translate_content_with_openai(wp_strip_all_tags($tagline));
            $finalTagline = wp_strip_all_tags($translatedTagline);
        } else {
            $finalTagline = $this->translator->generate_political_tagline($final_content);
        }

        // Traduz tags
        if($tag_names){
            $translatedTags = $this->translator->translate_tags_with_openai($tag_names);
        } else {
            $translatedTags = $this->translator->generate_keywords_from_content($final_content);
        }
        
        
        error_log("[===== LITCI IMPORTER =====] Conteudo final para {$final_title} (ID {$post_id}): " . substr(strip_tags($content), 0, 500));

        // Atualiza o post com o novo conteúdo (já com imagens locais)
        wp_update_post([
            'ID'            => $post_id,
            'post_title'    => $final_title,
            'post_content'  => $final_content,
            'tags_input'     => $translatedTags,
            'meta_input'    => [
                '_edit_last'    => get_current_user_id(),
                '_gutenberg_blocks' => true, // Flag para o editor de blocos
                'litci_post_political_author' => $political_author,
                'litci_post_tagline' => $finalTagline,
            ],
        ]);

        // ======================================================
        //  4 . FINALIZAÇÃO COM REDIRECIONAMENTO
        // ======================================================

        // Obtém a URL de edição do post
        $edit_url = get_edit_post_link($post_id, 'raw');

        wp_send_json_success([
            'message' => "Post importado com sucesso (ID local: {$post_id})! Redirecionando...",
            'edit_url' => $edit_url, // URL para qual será redirecionado
            'image'   => $image_url ?: null
        ]);
    }

    private function processContent($content, $post_id)
    {
        // 1. Prepara o array de blocos
        $arrayBlocks = $this->explodeInMediaArray($content);

        // 2. Processa as Imagens (Fazer Download, Sideload, e substituição da URL no array)
        //    Chamamos a nova função aqui
        $processed_blocks = $this->mediaHandler->import_inline_images_array($arrayBlocks, $post_id);

        // 3. Tradução: Traduz apenas os blocos de texto, mantendo a ordem
        $translated_blocks = $this->translator->process_translation($processed_blocks);

        // 4. Prepara para o formato Guttenberg
        $wrapped_blocks = array_map(function ($block) {
            return $this->litci_wrap_block($block);
        }, $translated_blocks);

        $final_content = implode("\n", $wrapped_blocks);

        return $final_content;
    }

    /*==================================================
        Expolode o HTML do conteúdo em
        um array para ser processado
    ==================================================*/
    private function explodeInMediaArray($content): array
    {
        // O preg_split irá:
        // 1. Dividir a string nos delimitadores (os blocos de mídia).
        // 2. Manter os delimitadores no array resultante (PREG_SPLIT_DELIM_CAPTURE).
        // 3. Remover entradas vazias (PREG_SPLIT_NO_EMPTY).

        $block_pattern = '/(
            <figure.*?>.*?<\/figure>|
            <img[^>]+>|
            <iframe.*?>.*?<\/iframe>|
            <video.*?>.*?<\/video>|
            <blockquote\s+class=["\']instagram-media["\'].*?<\/blockquote>|
            <embed[^>]+>|
            <object.*?>.*?<\/object>|
            <p.*?>.*?<\/p>|
            <h[1-6].*?>.*?<\/h[1-6]>|
            <ul.*?>.*?<\/ul>|
            <ol.*?>.*?<\/ol> |
            <table.*?>.*?><\/table>
        )/isx';

        $exploded_content = preg_split(
            $block_pattern,
            $content,
            -1, // Sem limite
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        // Limpeza final para remover blocos que sejam apenas espaços em branco ou quebras de linha
        $final_blocks = [];
        foreach ($exploded_content as $block) {
            if (trim($block) !== '') {
                $final_blocks[] = $block;
            }
        }

        return $final_blocks ?? [];
    }

    /*==================================================
        Converte eventuais blocos clássicos
        para blocos Guttenberg
    ==================================================*/
    private function litci_wrap_block($block)
    {
        if (preg_match('/^(<h[1-6]>)/', $block)) {
            return "<!-- wp:heading -->{$block}<!-- /wp:heading -->";
        } elseif (preg_match('/^(<ul|<ol)/', $block)) {
            return "<!-- wp:list -->{$block}<!-- /wp:list -->";
        } elseif (preg_match('/^(<img|<fig)/', $block)) {
            return "<!-- wp:image -->{$block}<!-- /wp:image -->";
        } else {
            return "<!-- wp:paragraph -->{$block}<!-- /wp:paragraph -->";
        }
    }
}

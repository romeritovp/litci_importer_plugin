<?php
if (!defined('ABSPATH')) exit;

class LITCI_Translation_Service
{
    public function translate_content_with_openai($text)
    {
        if (empty($text)) {
            return $text;
        }
        $language = get_locale();
        // 1. Verifica se a chave da API está disponível
        $api_key = get_option('openai_api_token');
        if (empty($api_key)) {
            // Se a chave não estiver configurada, não traduz.
            error_log('LITCI Importer: A chave da API da OpenAI não está configurada.');
            return $text;
        }

        // 2. Define o prompt para a tradução
        // Dentro de private function translate_content_with_openai($text, $language = 'Português do Brasil')

        // Definição do prompt:
        // $prompt = "Traduza o texto a seguir para {$language}. Mantenha **estritamente** toda a formatação HTML, links e quebras de linha existentes. Jamais traduza ULRs de imagens. Retorne apenas o texto traduzido, sem introdução ou conclusão:\n\n{$text}";
        $prompt = <<<PROMPT
            Traduza o texto a seguir para {$language}. Siga estas instruções estritamente:
            1. Preserve EXATAMENTE toda a formatação HTML, incluindo a estrutura, ordem e valores de todas as tags, atributos (como class, id, src, srcset, sizes, alt, loading, etc.) e quebras de linha.
            2. NÃO traduza, modifique ou codifique URLs em atributos de tags HTML (como src, srcset, href, etc.), especialmente URLs de imagens.
            3. Traduza apenas o conteúdo textual dentro das tags HTML, mantendo os textos em atributos (como alt ou title) traduzidos apenas se forem descritivos e não parte de URLs.
            4. Mantenha todos os espaços em branco, quebras de linha e formatação original do texto.
            5. Retorne APENAS o texto traduzido, sem introdução, conclusão ou qualquer comentário adicional.

            Texto: {$text}
            PROMPT;
        $api_url = 'https://api.openai.com/v1/chat/completions';

        // 3. Monta o payload para a API
        $args = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'    => json_encode([
                'model'    => 'gpt-4o-mini', // Modelo eficiente e rápido para tradução
                'messages' => [
                    [
                        'role'    => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.05, // Baixa temperatura para traduções mais literais
            ]),
            'timeout' => 45, // Aumenta o timeout para requisições mais longas
        ];

        // 4. Faz a requisição HTTP
        $response = wp_remote_post($api_url, $args);

        if (is_wp_error($response)) {
            error_log('OpenAI Translation Error: ' . $response->get_error_message());
            return $text; // Retorna o original em caso de erro na requisição
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        error_log('OpenAI RESPONSE BODY (Raw): ' . $body);
        error_log('OpenAI RESPONSE DATA (Array): ' . print_r($data, true));

        // 5. Verifica e extrai a tradução
        if (isset($data['choices'][0]['message']['content'])) {
            $translated_text = trim($data['choices'][0]['message']['content']);
            $translated_text = html_entity_decode($translated_text, ENT_QUOTES, 'UTF-8');
            return $translated_text;
        } elseif (isset($data['error'])) {
            error_log('OpenAI API Error: ' . $data['error']['message']);
            return $text; // Retorna o original em caso de erro da API
        }

        return $text; // Retorna o original como fallback
    }

    public function translate_content_in_chunks($content)
    {
        if (empty($content)) {
            return $content;
        }

        $max_chunk_length = 3000;

        // =================================================================
        // 1. EXTRAIR TODOS OS BLOCOS (INCLUINDO TEXTO SOLTO E IMAGENS)
        // =================================================================

        // Regex aprimorado: Busca tags de bloco OU qualquer texto/tag que não seja tag de bloco
        // Captura parágrafos, títulos E o conteúdo solto entre eles.
        $pattern = '/(<p.*?>.*?<\/p>|<h\d.*?>.*?<\/h\d>|.+?)/is';

        // Usamos preg_match_all para garantir que capturemos tudo na ordem correta
        preg_match_all($pattern, $content, $matches);
        $logical_blocks = $matches[0]; // O array $logical_blocks agora tem todos os pedaços

        if (empty($logical_blocks)) {
            return $this->translate_content_with_openai($content);
        }

        $chunks_to_translate = [];
        $current_chunk = '';

        // =================================================================
        // 2. Agrupar os blocos lógicos em chunks maiores
        // =================================================================
        foreach ($logical_blocks as $block) {
            // Ignora strings que são apenas espaços em branco ou novas linhas
            if (trim($block) === '') {
                continue;
            }

            $is_media_block = preg_match(
                '/^<img[^>]+>$/i', // 1. IMG solta
                trim($block)
            ) || preg_match(
                '/^<figure.*?<\/figure>$/is', // 2. FIGURE completa (com .*, usa 's' para multiline)
                trim($block)
            );

            // Se o bloco for uma tag IMG sozinha, não a agrupamos (para proteção máxima)
            // Isso assume que tags <img> soltas não devem ser traduzidas.
            if ($is_media_block) {
                // Salva o chunk atual antes de inserir a mídia solta
                if (!empty($current_chunk)) {
                    $chunks_to_translate[] = $current_chunk;
                    $current_chunk = '';
                }
                // Adiciona o bloco de mídia solta como um chunk próprio (não precisa ser traduzido)
                $chunks_to_translate[] = $block;
                continue;
            }

            // Lógica de agrupamento (baseada no tamanho)
            if (strlen($current_chunk . $block) > $max_chunk_length && !empty($current_chunk)) {
                $chunks_to_translate[] = $current_chunk;
                $current_chunk = $block;
            } else {
                $current_chunk .= $block;
            }
        }

        // Adiciona o último chunk restante
        if (!empty($current_chunk)) {
            $chunks_to_translate[] = $current_chunk;
        }

        // ... (código da Etapa 2)

        $translated_chunks = [];
        // 3. Traduzir cada chunk grande
        foreach ($chunks_to_translate as $chunk) {

            $is_media_block = preg_match(
                '/^<img[^>]+>$/i', // 1. IMG solta
                trim($chunk)
            ) || preg_match(
                '/^<figure.*?<\/figure>$/is', // 2. FIGURE completa
                trim($chunk)
            );

            // Verifica se o chunk foi isolado na etapa anterior (media block)
            if ($is_media_block) {
                $translated_chunks[] = $chunk; // Adiciona sem traduzir
                continue;
            }

            // Se não for um bloco de mídia, traduz
            // Omiti o $language aqui, mas se sua função exigir, adicione 'Português do Brasil'
            $translated_chunk = $this->translate_content_with_openai($chunk);

            // ... (lógica de fallback) ...
            if (empty($translated_chunk)) {
                $translated_chunks[] = $chunk;
            } else {
                $translated_chunks[] = $translated_chunk;
            }
        }

        // 4. Remonta o conteúdo traduzido
        return implode('', $translated_chunks);
    }

    public function process_translation(array $exploded_content)
    {
        $translated_blocks = [];

        foreach ($exploded_content as $block) {

            // 1. Verifica se é um bloco de mídia.
            if ($this->is_media($block)) {
                // Se for mídia, mantém o bloco original (já deve estar com URLs locais).
                $translated_blocks[] = $block;
                error_log('LITCI Importer: Bloco de Mídia Ignorado para Tradução.');
                continue;
            }

            // 2. É um bloco de texto: Traduz.
            $translated_text = $this->translate_content_in_chunks($block);

            // 3. Adiciona a tradução (ou o original em caso de erro/vazio).
            if (empty($translated_text)) {
                $translated_blocks[] = $block;
                error_log('LITCI Importer: Falha na Tradução do Bloco. Mantendo Original.');
            } else {
                $translated_blocks[] = $translated_text;
                error_log('LITCI Importer: Bloco de Texto Traduzido com Sucesso.');
            }
        }

        return $translated_blocks;
    }

    /**
     * Verifica se um bloco é um bloco de mídia (figure, img, iframe, etc.).
     *
     * @param string $block O bloco de conteúdo a ser verificado.
     * @return bool True se for um bloco de mídia, False caso contrário.
     */
    public function is_media($block)
    {
        /*
        Padrões de Mídia/Embed:
        1. (<figure.*?>.*?<\/figure>) : Blocos de imagem/mídia do WordPress (inclui legendas).
        2. (<img[^>]+>) : Tag <img> solta.
        3. (<iframe.*?>.*?<\/iframe>) : Blocos de iFrame (YouTube, etc.).
        4. (<video.*?>.*?<\/video>) : Blocos de vídeo HTML5.
        5. (<blockquote\s+class=["\']instagram-media["\'].*?<\/blockquote>) : Bloco de embed do Instagram.
        6. (<embed[^>]+>|<object.*?>.*?<\/object>) : Tags genéricas de embed/objeto.
        */

        // O regex é construído com 'OU' (|) para capturar qualquer um desses padrões. O modificador 's' (dotall) é crucial.
        $media_pattern = '/(
            <figure.*?>.*?<\/figure>|
            <img[^>]+>|
            <iframe.*?>.*?<\/iframe>|
            <video.*?>.*?<\/video>|
            <blockquote\s+class=["\']instagram-media["\'].*?<\/blockquote>|
            <embed[^>]+>|
            <object.*?>.*?<\/object>
        )/isx';

        return preg_match($media_pattern, trim($block));
    }
}
